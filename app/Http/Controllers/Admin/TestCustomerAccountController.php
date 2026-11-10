<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Models\CustomerAccount;
use App\Services\CustomerLogin;
use App\Services\TestCustomerReset;
use App\Support\AdminHistory;
use App\Support\FaNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestCustomerAccountController extends Controller
{
    public function index(CustomerLogin $login, TestCustomerReset $reset)
    {
        $rows = [];
        foreach ($login->testPhones() as $phone) {
            $key = $login->testDigest($phone);
            $account = CustomerAccount::query()->where('phone_digest', $key)->whereNull('verified_at')->first();
            $rows[] = [
                'key' => $key,
                'masked' => substr($phone, 0, 4).' ••• '.substr($phone, -4),
                'account' => $account,
                'scope' => $account ? $reset->scope($account) : null,
            ];
        }

        return view('admin.test-customer-accounts', ['rows' => $rows]);
    }

    public function reveal(Request $request, CustomerLogin $login, string $key)
    {
        $request->validate(['current_password' => ['required', 'string', 'max:255', 'current_password:web']]);
        foreach ($login->testPhones() as $phone) {
            if (hash_equals($login->testDigest($phone), $key)) {
                $code = $login->privateTestCode($phone);
                if ($code !== null) {
                    // Never put the code in flash, old input, a URL, or an audit payload.
                    return response()->view('admin.test-customer-code', ['code' => $code]);
                }
                break;
            }
        }

        return to_route('admin.test-customers.index')->withErrors(['challenge' => 'کد فعالی وجود ندارد. ابتدا از صفحهٔ ورود درخواست کد بدهید.']);
    }

    public function reset(Request $request, CustomerLogin $login, TestCustomerReset $reset, string $record)
    {
        $request->validate(['current_password' => ['required', 'string', 'max:255', 'current_password:web'], 'confirm' => ['accepted'], 'scope' => ['required', 'regex:/\A[a-f0-9]{64}\z/D']]);
        $actor = $request->user()->id;
        $result = DB::transaction(function () use ($record, $login, $actor, $reset, $request): array {
            $candidate = CustomerAccount::query()->where('public_id', $record)->first();
            if (! $candidate) {
                return ['absent'];
            }
            $phone = $candidate->phone;
            $digest = $login->testDigest($phone);
            if (! $login->isTestAccount($candidate)) {
                return ['invalid'];
            }
            // Verification takes this same challenge lock before creating or reading the account.
            $challenge = DB::table('customer_login_challenges')->where('phone_digest', $digest)->lockForUpdate()->first();
            $account = CustomerAccount::query()->lockForUpdate()->find($candidate->id);
            if (! $account) {
                return ['absent'];
            }
            $scope = $reset->scope($account, true);
            $reason = ! $challenge || ! hash_equals($digest, $account->phone_digest)
                ? 'اعتبار ثبت‌نام آزمایشی تأیید نشد.'
                : ($scope['reason'] ?? (hash_equals($scope['pin'], $request->input('scope')) ? null : 'سوابق پس از نمایش تغییر کرده‌اند. صفحه را بازبینی و دوباره تأیید کنید.'));
            if ($reason !== null) {
                AdminHistory::record(AdminAction::TestCustomerResetBlocked, 'customer_account', $account->id, $actor);

                return ['blocked', $reason];
            }
            DB::table('customer_login_challenges')->where('id', $challenge->id)->update([
                'challenge_id' => null, 'code_hash' => null, 'attempts' => 0, 'expires_at' => null, 'resend_at' => null,
            ]);
            foreach (['customer_notifications' => 'notifications', 'repair_requests' => 'requests', 'customer_addresses' => 'addresses'] as $table => $key) {
                DB::table($table)->where('customer_account_id', $account->id)->whereIn('id', $scope[$key])->delete();
            }
            // Retain reserved tracking numbers and all administrator audit history.
            $account->delete();
            AdminHistory::record(AdminAction::TestCustomerReset, 'customer_account', $account->id, $actor);

            return ['reset', count($scope['requests'])];
        }, 3);

        $response = to_route('admin.test-customers.index');

        return match ($result[0]) {
            'reset' => $response->with('success', 'حساب آزمایشی و '.FaNumber::digits($result[1]).' درخواست آزمایشی بازنشانی شد. می‌توانید با همین شماره دوباره ثبت‌نام کنید.'),
            'absent' => $response->with('success', 'این حساب دیگر وجود ندارد.'),
            'blocked' => $response->withErrors(['reset' => $result[1]]),
            default => $response->withErrors(['reset' => 'فقط حساب آزمایشی شماره‌های مجاز را می‌توان بازنشانی کرد.']),
        };
    }
}
