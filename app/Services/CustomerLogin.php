<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Services\Observability\ObservabilityEvents;
use App\Support\RepairIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerLogin
{
    private const TEST_SMS_HASH_PREFIX = 'v3:test-sms:';

    public const RESEND_SECONDS = 60;

    private const DAILY_SMS_LIMIT = 15;

    private const DAILY_SMS_SECONDS = 86400;

    public function authenticatedAccount(Request $request): ?CustomerAccount
    {
        if (! $request->hasSession()) {
            return null;
        }
        $account = Auth::guard('customer')->user();

        return $account && $account->is_active
            && $account->session_version === $request->session()->get('customer_session_version')
            && ($account->verified_at || $account->email_verified_at || $this->testAllowed($account->phone)) ? $account : null;
    }

    public function testAllowed(?string $phone): bool
    {
        return is_string($phone) && $phone !== '' && in_array($phone, $this->testPhones(), true);
    }

    public function isTestAccount(CustomerAccount $account): bool
    {
        return $account->verified_at === null && $account->email_verified_at === null
            && $this->testAllowed($account->phone)
            && is_string($account->phone_digest)
            && hash_equals($this->testDigest((string) $account->phone), $account->phone_digest);
    }

    public function testUsesSms(string $phone): bool
    {
        return trim(config('customer.test_sms_phone', '')) === $phone && $this->testAllowed($phone);
    }

    public function testPhones(): array
    {
        return config('customer.test_otp_enabled')
            ? array_values(array_unique(array_filter(array_map('trim', config('customer.test_otp_phones')), fn ($phone) => preg_match('/\A09[0-9]{9}\z/D', $phone))))
            : [];
    }

    public function testDigest(string $phone): string
    {
        return $this->digest($phone, true);
    }

    private function digest(string $phone, bool $test): string
    {
        return RepairIdentity::digest($test ? 'customer-test-account-phone' : 'customer-account-phone', $phone);
    }

    private function testCode(string $challenge): string
    {
        $value = hexdec(substr(RepairIdentity::digest('customer-private-otp', $challenge), 0, 8));

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function privateTestCode(string $phone): ?string
    {
        if (! $this->testAllowed($phone) || $this->testUsesSms($phone)) {
            return null;
        }

        $row = DB::table('customer_login_challenges')->where('phone_digest', $this->digest($phone, true))->first();

        return $row && $row->code_hash && ! str_starts_with($row->code_hash, self::TEST_SMS_HASH_PREFIX)
            && now()->lt($row->expires_at) && $row->attempts < 5
            ? $this->testCode($row->challenge_id) : null;
    }

    public function passwordAccount(string $phone, string $password): ?CustomerAccount
    {
        return (new Timebox)->call(function () use ($phone, $password): ?CustomerAccount {
            $account = CustomerAccount::query()->where('phone_digest', $this->digest($phone, false))->first();
            if (! $account && $this->testAllowed($phone)) {
                $account = CustomerAccount::query()->where('phone_digest', $this->digest($phone, true))->first();
            }

            // Do a hasher operation even for unknown accounts or accounts without a password.
            $matches = $account?->password
                ? Hash::check($password, $account->password)
                : Hash::check($password, Hash::make(Str::random(48))) && false;

            return $matches && $account->is_active ? $account : null;
        }, 400000);
    }

    public function issue(string $phone, ?callable $reserved = null): string
    {
        abort_unless(preg_match('/\A09[0-9]{9}\z/D', $phone), 422);
        $test = $this->testAllowed($phone);
        $testSms = $this->testUsesSms($phone);
        $privateCode = $test && ! $testSms;
        $sms = app(CustomerOtpSms::class);
        $smsReady = $sms->enabled() && $sms->configured() && (! $testSms || $sms->provider() === 'ippanel');
        $localSimulated = app()->isLocal() && ! $privateCode && ! $smsReady;
        $skipSms = $privateCode || $localSimulated;
        abort_unless($skipSms || $smsReady, 503, 'ورود پیامکی هنوز فعال نشده است.');
        $digest = $this->digest($phone, $test);
        $quotaKey = 'customer-sms-dispatch:'.RepairIdentity::digest('otp-send-quota', $phone);
        $challenge = (string) Str::uuid();
        $code = $privateCode ? $this->testCode($challenge) : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hashPrefix = $testSms ? self::TEST_SMS_HASH_PREFIX : 'v2:';
        $quotaUntil = DB::transaction(function () use ($digest, $phone, $code, $challenge, $hashPrefix, $skipSms, $quotaKey): ?int {
            DB::table('customer_login_challenges')->insertOrIgnore(['phone_digest' => $digest, 'phone' => Crypt::encryptString($phone)]);
            $row = DB::table('customer_login_challenges')->where('phone_digest', $digest)->lockForUpdate()->first();
            if ($row->resend_at && now()->lt($row->resend_at)) {
                throw new HttpException(429, 'resend_cooldown', headers: ['Retry-After' => (string) max(1, (int) ceil(now()->diffInSeconds($row->resend_at)))]);
            }
            if (! $skipSms && RateLimiter::tooManyAttempts($quotaKey, self::DAILY_SMS_LIMIT)) {
                throw new HttpException(429, 'daily_sms_limit', headers: ['Retry-After' => (string) max(1, RateLimiter::availableIn($quotaKey))]);
            }
            DB::table('customer_login_challenges')->where('id', $row->id)->update([
                'challenge_id' => $challenge, 'code_hash' => $hashPrefix.Hash::make($this->codeDigest($challenge, $code)),
                'attempts' => 0, 'expires_at' => now()->addMinutes(2), 'resend_at' => now()->addSeconds(self::RESEND_SECONDS),
            ]);
            if ($skipSms) {
                return null;
            }
            RateLimiter::hit($quotaKey, self::DAILY_SMS_SECONDS);

            return now()->timestamp + RateLimiter::availableIn($quotaKey);
        }, 3);
        // Bind the reserved challenge to the initiating session before provider I/O.
        // A lost/ambiguous delivery response must not orphan a delivered code.
        if ($reserved !== null) {
            $reserved($challenge);
        }
        // Never log the request, OTP, mobile, API response or transport exception.
        $outcome = $skipSms ? 'accepted' : $sms->sendOutcome($phone, $code);
        if ($outcome !== 'accepted') {
            $wait = DB::transaction(function () use ($challenge, $outcome, $quotaKey, $quotaUntil): int {
                $row = DB::table('customer_login_challenges')->where('challenge_id', $challenge)->lockForUpdate()->first();
                if ($row && $outcome === 'rejected') {
                    DB::table('customer_login_challenges')->where('id', $row->id)->update(['code_hash' => null]);
                }
                $remaining = RateLimiter::availableIn($quotaKey);
                if ($outcome === 'rejected' && $row && $remaining > 0 && now()->timestamp + $remaining === $quotaUntil && RateLimiter::attempts($quotaKey) > 0) {
                    RateLimiter::decrement($quotaKey, $remaining);
                }

                return $row?->resend_at ? max(1, (int) ceil(now()->diffInSeconds($row->resend_at))) : self::RESEND_SECONDS;
            }, 3);
            throw new HttpException(503, 'delivery_unconfirmed', headers: ['Retry-After' => (string) $wait]);
        }

        if (app()->isLocal()) {
            session()->put('local_dev_otp', [
                'challenge' => $challenge,
                'code' => $code,
            ]);
        }

        return $challenge;
    }

    public static function localDevOtpFor(?string $challenge): ?string
    {
        if (! app()->isLocal() || ! is_string($challenge) || $challenge === '') {
            return null;
        }
        $stored = session('local_dev_otp');
        if (! is_array($stored) || ($stored['challenge'] ?? null) !== $challenge) {
            return null;
        }
        $code = $stored['code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    private function codeDigest(string $challenge, string $code): string
    {
        return RepairIdentity::digest('customer-otp-code', $challenge.':'.$code);
    }

    public function activeChallenge(string $phone, mixed $challenge): bool
    {
        return $this->challengeState($phone, $challenge) !== null;
    }

    public function challengeState(string $phone, mixed $challenge): ?array
    {
        if (! is_string($challenge) || $challenge === '') {
            return null;
        }
        $row = DB::table('customer_login_challenges')
            ->where('phone_digest', $this->digest($phone, $this->testAllowed($phone)))
            ->where('challenge_id', $challenge)->first(['code_hash', 'attempts', 'expires_at', 'resend_at']);

        return $row && $row->code_hash && $row->attempts < 5 && now()->lt($row->expires_at)
            && (! $this->testUsesSms($phone) || str_starts_with($row->code_hash, self::TEST_SMS_HASH_PREFIX))
            ? ['expiresAt' => Carbon::parse($row->expires_at)->timestamp,
                'resendAt' => Carbon::parse($row->resend_at)->timestamp]
            : null;
    }

    public function verify(string $challenge, string $code): CustomerAccount
    {
        $wasCreated = false;
        $account = DB::transaction(function () use ($challenge, $code, &$wasCreated): ?CustomerAccount {
            $row = DB::table('customer_login_challenges')->where('challenge_id', $challenge)->lockForUpdate()->first();
            if ($row?->code_hash && now()->gte($row->expires_at)) {
                throw ValidationException::withMessages(['code' => 'زمان اعتبار کد تمام شد. کد تازه درخواست کنید.']);
            }
            if (! $row || ! $row->code_hash || $row->attempts >= 5) {
                return null;
            }
            DB::table('customer_login_challenges')->where('id', $row->id)->increment('attempts');
            $phone = Crypt::decryptString($row->phone);
            $test = hash_equals($this->digest($phone, true), $row->phone_digest);
            if ($test ? ! $this->testAllowed($phone) : ! hash_equals($this->digest($phone, false), $row->phone_digest)) {
                return null;
            }
            $testSms = str_starts_with($row->code_hash, self::TEST_SMS_HASH_PREFIX);
            // A code issued through the private reveal path cannot carry over to SMS mode.
            if ($test && $this->testUsesSms($phone) && ! $testSms) {
                return null;
            }
            $prefixLength = $testSms ? strlen(self::TEST_SMS_HASH_PREFIX) : (str_starts_with($row->code_hash, 'v2:') ? 3 : 0);
            if (! Hash::check($prefixLength ? $this->codeDigest($challenge, $code) : $code, substr($row->code_hash, $prefixLength))) {
                return null;
            }
            DB::table('customer_login_challenges')->where('id', $row->id)->update(['code_hash' => null]);
            // Phone possession does not authorize historical CRM records, including shared phones.
            $wasCreated = CustomerAccount::query()->insertOrIgnore(['public_id' => (string) Str::uuid(), 'phone_digest' => $row->phone_digest, 'phone' => $row->phone, 'verified_at' => $test ? null : now(), 'created_at' => now(), 'updated_at' => now()]) > 0;

            return CustomerAccount::query()->where('phone_digest', $row->phone_digest)->where('is_active', true)->first();
        }, 3);
        if (! $account) {
            throw ValidationException::withMessages(['code' => 'کد معتبر نیست یا منقضی شده است.']);
        }

        app(ObservabilityEvents::class)->userAuth($wasCreated ? 'registered' : 'login_otp', $account);

        return $account;
    }
}
