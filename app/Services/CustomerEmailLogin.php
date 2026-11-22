<?php

namespace App\Services;

use App\Mail\UnifiedNotificationMail;
use App\Models\CustomerAccount;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CustomerEmailLogin
{
    public const RESEND_SECONDS = 60;

    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function digest(string $email): string
    {
        return RepairIdentity::digest('customer-account-email', $this->normalizeEmail($email));
    }

    public function issue(string $email): string
    {
        abort_unless((bool) config('customer.email_auth_enabled', true), 503, 'ورود با ایمیل در حال حاضر غیرفعال است.');
        $normalized = $this->normalizeEmail($email);
        abort_unless(filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false, 422);

        $digest = $this->digest($normalized);
        $challenge = (string) Str::uuid();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($digest, $normalized, $challenge, $code): void {
            DB::table('customer_email_challenges')->insertOrIgnore([
                'email_digest' => $digest,
                'email' => Crypt::encryptString($normalized),
            ]);
            $row = DB::table('customer_email_challenges')->where('email_digest', $digest)->lockForUpdate()->first();
            if ($row->resend_at && now()->lt($row->resend_at)) {
                throw new HttpException(429, 'resend_cooldown', headers: ['Retry-After' => (string) max(1, (int) ceil(now()->diffInSeconds($row->resend_at)))]);
            }
            DB::table('customer_email_challenges')->where('id', $row->id)->update([
                'challenge_id' => $challenge,
                'code_hash' => Hash::make($this->codeDigest($challenge, $code)),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(5),
                'resend_at' => now()->addSeconds(self::RESEND_SECONDS),
            ]);
        }, 3);

        try {
            Mail::to($normalized)->send(new UnifiedNotificationMail(
                mailSubject: 'کد تأیید ورود به '.config('business.name', 'نوین‌پی'),
                heading: 'کد تأیید ورود شما',
                intro: 'برای ورود یا ثبت‌نام در حساب کاربری، کد شش‌رقمی زیر را وارد کنید. این کد تا ۵ دقیقه معتبر است.',
                fields: [
                    'کد تأیید' => FaNumber::digits($code),
                ],
            ));
        } catch (\Throwable) {
            DB::table('customer_email_challenges')->where('challenge_id', $challenge)->update(['code_hash' => null]);
            throw new HttpException(503, 'ارسال ایمیل تأیید در حال حاضر ممکن نیست. لطفاً کمی بعد تلاش کنید.');
        }

        return $challenge;
    }

    public function challengeState(string $email, mixed $challenge): ?array
    {
        if (! is_string($challenge) || $challenge === '') {
            return null;
        }
        $row = DB::table('customer_email_challenges')
            ->where('email_digest', $this->digest($email))
            ->where('challenge_id', $challenge)
            ->first(['code_hash', 'attempts', 'expires_at', 'resend_at']);

        return $row && $row->code_hash && $row->attempts < 5 && now()->lt($row->expires_at)
            ? [
                'expiresAt' => Carbon::parse($row->expires_at)->timestamp,
                'resendAt' => Carbon::parse($row->resend_at)->timestamp,
            ]
            : null;
    }

    public function verify(string $challenge, string $code): CustomerAccount
    {
        $account = DB::transaction(function () use ($challenge, $code): ?CustomerAccount {
            $row = DB::table('customer_email_challenges')->where('challenge_id', $challenge)->lockForUpdate()->first();
            if (! $row || ! $row->code_hash || $row->attempts >= 5 || now()->gte($row->expires_at)) {
                return null;
            }
            if (! Hash::check($this->codeDigest($challenge, $code), (string) $row->code_hash)) {
                DB::table('customer_email_challenges')->where('id', $row->id)->update(['attempts' => $row->attempts + 1]);

                return null;
            }
            DB::table('customer_email_challenges')->where('id', $row->id)->update(['code_hash' => null, 'attempts' => 5]);

            $email = $this->normalizeEmail(Crypt::decryptString($row->email));
            $account = CustomerAccount::query()->where('email_digest', $row->email_digest)->lockForUpdate()->first();
            if (! $account) {
                $account = new CustomerAccount;
                $account->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'email_digest' => $row->email_digest,
                    'email' => $email,
                    'verified_at' => now(),
                    'email_verified_at' => now(),
                    'is_active' => true,
                    'session_version' => 1,
                ])->save();
            } else {
                abort_unless($account->is_active, 403, 'حساب کاربری غیرفعال است.');
                $account->forceFill([
                    'email_verified_at' => $account->email_verified_at ?? now(),
                    'verified_at' => $account->verified_at ?? now(),
                    'last_login_at' => now(),
                ])->save();
            }

            return $account;
        }, 3);

        if (! $account) {
            throw ValidationException::withMessages(['code' => 'کد تأیید نامعتبر یا منقضی است.']);
        }

        return $account;
    }

    public function passwordAccount(string $email, string $password): ?CustomerAccount
    {
        $normalized = $this->normalizeEmail($email);

        return (new Timebox)->call(function () use ($normalized, $password): ?CustomerAccount {
            $account = CustomerAccount::query()->where('email_digest', $this->digest($normalized))->first();
            $matches = $account?->password
                ? Hash::check($password, $account->password)
                : Hash::check($password, Hash::make(Str::random(48))) && false;

            return $matches && $account->is_active ? $account : null;
        }, 400000);
    }

    private function codeDigest(string $challenge, string $code): string
    {
        return RepairIdentity::digest('customer-email-otp-code', $challenge.':'.$code);
    }
}
