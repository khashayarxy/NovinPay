<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Support\RepairIdentity;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerLogin
{
    public function issue(string $phone): string
    {
        abort_unless(config('customer.sms_enabled') && config('customer.sms_api_key') && config('customer.otp_template'), 503, 'ورود پیامکی هنوز فعال نشده است.');
        $digest = RepairIdentity::digest('customer-account-phone', $phone);
        $code = (string) random_int(100000, 999999);
        $challenge = (string) Str::uuid();
        DB::transaction(function () use ($digest, $phone, $code, $challenge): void {
            DB::table('customer_login_challenges')->insertOrIgnore(['phone_digest' => $digest, 'phone' => Crypt::encryptString($phone)]);
            $row = DB::table('customer_login_challenges')->where('phone_digest', $digest)->lockForUpdate()->first();
            abort_if($row->resend_at && now()->lt($row->resend_at), 429, 'برای ارسال دوباره، یک دقیقه صبر کنید.');
            DB::table('customer_login_challenges')->where('id', $row->id)->update(['challenge_id' => $challenge, 'code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(3), 'resend_at' => now()->addMinute()]);
        }, 3);
        // Never log the request, OTP, mobile, API response or transport exception.
        try {
            $response = Http::withoutRedirecting()->connectTimeout(4)->timeout(10)->withHeaders(['X-API-KEY' => config('customer.sms_api_key')])->post('https://api.sms.ir/v1/send/verify', ['mobile' => $phone, 'templateId' => (int) config('customer.otp_template'), 'parameters' => [['name' => config('customer.otp_parameter'), 'value' => $code]]]);
            $ok = $response->successful() && $response->json('status') === 1;
        } catch (\Throwable) {
            $ok = false;
        }
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
            DB::table('customer_login_challenges')->where('id', $row->id)->update(['code_hash' => null]);
            // Phone possession does not authorize historical CRM records, including shared phones.
            CustomerAccount::query()->insertOrIgnore(['public_id' => (string) Str::uuid(), 'phone_digest' => $row->phone_digest, 'phone' => $row->phone, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return CustomerAccount::query()->where('phone_digest', $row->phone_digest)->where('is_active', true)->first();
        }, 3);
        if (! $account) {
            throw ValidationException::withMessages(['code' => 'کد معتبر نیست یا منقضی شده است.']);
        }

        return $account;
    }
}
