<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Support\RepairIdentity;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

class CustomerLogin
{
    public function testAllowed(string $phone): bool
    {
        return config('customer.test_otp_enabled')
            && preg_match('/\A09[0-9]{9}\z/D', $phone)
            && in_array($phone, array_map('trim', config('customer.test_otp_phones')), true);
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
        if (! $this->testAllowed($phone)) {
            return null;
        }

        $row = DB::table('customer_login_challenges')->where('phone_digest', $this->digest($phone, true))->first();

        return $row && $row->code_hash && now()->lt($row->expires_at) && $row->attempts < 5
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

    public function issue(string $phone): string
    {
        abort_unless(preg_match('/\A09[0-9]{9}\z/D', $phone), 422);
        $test = $this->testAllowed($phone);
        $sms = app(SmsIr::class);
        abort_unless($test || (config('customer.sms_enabled') && $sms->ready(config('customer.otp_template'), config('customer.otp_parameter'))), 503, 'ورود پیامکی هنوز فعال نشده است.');
        $digest = $this->digest($phone, $test);
        $challenge = (string) Str::uuid();
        $code = $test ? $this->testCode($challenge) : (string) random_int(100000, 999999);
        DB::transaction(function () use ($digest, $phone, $code, $challenge): void {
            DB::table('customer_login_challenges')->insertOrIgnore(['phone_digest' => $digest, 'phone' => Crypt::encryptString($phone)]);
            $row = DB::table('customer_login_challenges')->where('phone_digest', $digest)->lockForUpdate()->first();
            abort_if($row->resend_at && now()->lt($row->resend_at), 429, 'برای ارسال دوباره، یک دقیقه صبر کنید.');
            DB::table('customer_login_challenges')->where('id', $row->id)->update(['challenge_id' => $challenge, 'code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(3), 'resend_at' => now()->addMinute()]);
        }, 3);
        // Never log the request, OTP, mobile, API response or transport exception.
        $ok = $test || $sms->send($phone, config('customer.otp_template'), config('customer.otp_parameter'), $code, 'otp');
        if (! $ok) {
            DB::table('customer_login_challenges')->where('challenge_id', $challenge)->update(['code_hash' => null]);
            abort(503, 'کد ارسال نشد؛ پس از یک دقیقه دوباره تلاش کنید.');
        }

        return $challenge;
    }

    public function verify(string $challenge, string $code): CustomerAccount
    {
        $account = DB::transaction(function () use ($challenge, $code): ?CustomerAccount {
            $row = DB::table('customer_login_challenges')->where('challenge_id', $challenge)->lockForUpdate()->first();
            if (! $row || ! $row->code_hash || now()->gte($row->expires_at) || $row->attempts >= 5) {
                return null;
            }
            DB::table('customer_login_challenges')->where('id', $row->id)->increment('attempts');
            if (! Hash::check($code, $row->code_hash)) {
                return null;
            }
            $phone = Crypt::decryptString($row->phone);
            $test = hash_equals($this->digest($phone, true), $row->phone_digest);
            if ($test ? ! $this->testAllowed($phone) : ! hash_equals($this->digest($phone, false), $row->phone_digest)) {
                return null;
            }
            DB::table('customer_login_challenges')->where('id', $row->id)->update(['code_hash' => null]);
            // Phone possession does not authorize historical CRM records, including shared phones.
            CustomerAccount::query()->insertOrIgnore(['public_id' => (string) Str::uuid(), 'phone_digest' => $row->phone_digest, 'phone' => $row->phone, 'verified_at' => $test ? null : now(), 'created_at' => now(), 'updated_at' => now()]);

            return CustomerAccount::query()->where('phone_digest', $row->phone_digest)->where('is_active', true)->first();
        }, 3);
        if (! $account) {
            throw ValidationException::withMessages(['code' => 'کد معتبر نیست یا منقضی شده است.']);
        }

        return $account;
    }
}
