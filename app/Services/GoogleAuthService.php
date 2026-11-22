<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Support\RepairIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GoogleAuthService
{
    public function configured(): bool
    {
        return (bool) config('services.google.enabled', false)
            && trim((string) config('services.google.client_id', '')) !== ''
            && trim((string) config('services.google.client_secret', '')) !== '';
    }

    public function redirectUri(): string
    {
        $configured = trim((string) config('services.google.redirect', ''));

        return $configured !== '' ? $configured : route('account.google.callback');
    }

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => trim((string) config('services.google.client_id')),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    /**
     * @return array{sub: string, email: string, email_verified: bool, name: ?string}
     */
    public function exchangeAndResolveUser(string $code): array
    {
        $tokenResponse = Http::withoutRedirecting()
            ->connectTimeout(4)
            ->timeout(10)
            ->asForm()
            ->acceptJson()
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => trim((string) config('services.google.client_id')),
                'client_secret' => trim((string) config('services.google.client_secret')),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri(),
            ]);

        $accessToken = $tokenResponse->successful() ? $tokenResponse->json('access_token') : null;
        if (! is_string($accessToken) || $accessToken === '') {
            throw new HttpException(502, 'تأیید ورود با گوگل انجام نشد. لطفاً دوباره تلاش کنید.');
        }

        $profileResponse = Http::withoutRedirecting()
            ->connectTimeout(4)
            ->timeout(10)
            ->acceptJson()
            ->withToken($accessToken)
            ->get('https://openidconnect.googleapis.com/v1/userinfo');

        if (! $profileResponse->successful()) {
            throw new HttpException(502, 'دریافت اطلاعات حساب گوگل انجام نشد.');
        }

        $sub = trim((string) $profileResponse->json('sub', ''));
        $email = mb_strtolower(trim((string) $profileResponse->json('email', '')));
        $emailVerified = (bool) $profileResponse->json('email_verified', false);
        $name = trim((string) $profileResponse->json('name', ''));

        if ($sub === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(422, 'اطلاعات معتبر حساب گوگل در دسترس نیست.');
        }

        return [
            'sub' => $sub,
            'email' => $email,
            'email_verified' => $emailVerified,
            'name' => $name !== '' ? mb_strimwidth($name, 0, 100, '') : null,
        ];
    }

    /**
     * @param  array{sub: string, email: string, email_verified: bool, name: ?string}  $googleUser
     */
    public function findOrCreateAccount(array $googleUser, ?CustomerAccount $currentAccount = null): CustomerAccount
    {
        $googleDigest = RepairIdentity::digest('customer-google-sub', $googleUser['sub']);
        $emailDigest = RepairIdentity::digest('customer-account-email', $googleUser['email']);

        return DB::transaction(function () use ($googleUser, $googleDigest, $emailDigest, $currentAccount): CustomerAccount {
            // 1. Linking flow when already signed in (e.g. mobile user linking Google).
            if ($currentAccount !== null) {
                $locked = CustomerAccount::query()->lockForUpdate()->findOrFail($currentAccount->id);
                abort_unless($locked->is_active, 403, 'حساب کاربری غیرفعال است.');
                $existingGoogle = CustomerAccount::query()->where('google_id_digest', $googleDigest)->first();
                if ($existingGoogle && $existingGoogle->id !== $locked->id) {
                    throw new HttpException(409, 'این حساب گوگل قبلاً به حساب کاربری دیگری متصل شده است.');
                }
                $updates = ['google_id_digest' => $googleDigest, 'last_login_at' => now()];
                if (! $locked->email_digest && $googleUser['email_verified']) {
                    $emailTaken = CustomerAccount::query()->where('email_digest', $emailDigest)->where('id', '!=', $locked->id)->exists();
                    if (! $emailTaken) {
                        $updates['email_digest'] = $emailDigest;
                        $updates['email'] = $googleUser['email'];
                        $updates['email_verified_at'] = now();
                    }
                }
                if (! $locked->name && $googleUser['name']) {
                    $updates['name'] = $googleUser['name'];
                }
                $locked->forceFill($updates)->save();

                return $locked;
            }

            // 2. Existing Google-linked account.
            $account = CustomerAccount::query()->where('google_id_digest', $googleDigest)->lockForUpdate()->first();
            if ($account) {
                abort_unless($account->is_active, 403, 'حساب کاربری غیرفعال است.');
                $account->forceFill(['last_login_at' => now()])->save();

                return $account;
            }

            // 3. Existing account with the same verified email -> link seamlessly.
            if ($googleUser['email_verified']) {
                $byEmail = CustomerAccount::query()->where('email_digest', $emailDigest)->lockForUpdate()->first();
                if ($byEmail) {
                    abort_unless($byEmail->is_active, 403, 'حساب کاربری غیرفعال است.');
                    $byEmail->forceFill([
                        'google_id_digest' => $googleDigest,
                        'email_verified_at' => $byEmail->email_verified_at ?? now(),
                        'verified_at' => $byEmail->verified_at ?? now(),
                        'name' => $byEmail->name ?: $googleUser['name'],
                        'last_login_at' => now(),
                    ])->save();

                    return $byEmail;
                }
            }

            // 4. Create new customer account via Google.
            $created = new CustomerAccount;
            $created->forceFill([
                'public_id' => (string) Str::uuid(),
                'google_id_digest' => $googleDigest,
                'email_digest' => $googleUser['email_verified'] ? $emailDigest : null,
                'email' => $googleUser['email_verified'] ? $googleUser['email'] : null,
                'name' => $googleUser['name'],
                'verified_at' => now(),
                'email_verified_at' => $googleUser['email_verified'] ? now() : null,
                'is_active' => true,
                'session_version' => 1,
                'last_login_at' => now(),
            ])->save();

            return $created;
        }, 3);
    }
}
