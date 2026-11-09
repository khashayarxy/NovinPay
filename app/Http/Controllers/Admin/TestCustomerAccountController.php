<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Models\CustomerAccount;
use App\Services\CustomerLogin;
use App\Support\AdminHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestCustomerAccountController extends Controller
{
    public function index(CustomerLogin $login)
    {
        $rows = [];
        foreach ($login->testPhones() as $phone) {
            $key = $login->testDigest($phone);
            $account = CustomerAccount::query()->where('phone_digest', $key)->whereNull('verified_at')->first();
            $rows[] = [
                'key' => $key,
                'masked' => substr($phone, 0, 4).' ••• '.substr($phone, -4),
                'account' => $account,
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

    public function reset(Request $request, CustomerLogin $login, string $record)
    {
        $request->validate(['current_password' => ['required', 'string', 'max:255', 'current_password:web'], 'confirm' => ['accepted']]);
        $actor = $request->user()->id;
        $result = DB::transaction(function () use ($record, $login, $actor): string {
            $candidate = CustomerAccount::query()->where('public_id', $record)->first();
            if (! $candidate) {
                return 'absent';
            }
            $phone = $candidate->phone;
            $digest = $login->testDigest($phone);
            if ($candidate->verified_at || ! $login->testAllowed($phone) || ! hash_equals($digest, $candidate->phone_digest)) {
                return 'invalid';
            }
            // Verification takes this same challenge lock before creating or reading the account.
            $challenge = DB::table('customer_login_challenges')->where('phone_digest', $digest)->lockForUpdate()->first();
            $account = CustomerAccount::query()->lockForUpdate()->find($candidate->id);
            if (! $account) {
                return 'absent';
            }
            if (! $challenge || $account->verified_at || $account->customer_id !== null || ! hash_equals($digest, $account->phone_digest)) {
                AdminHistory::record(AdminAction::TestCustomerResetBlocked, 'customer_account', $account->id, $actor);

                return 'linked';
            }
            foreach (['repair_cases', 'repair_requests', 'sales_orders', 'sales_order_events', 'payment_attempts', 'payment_receipts', 'delivery_plans', 'delivery_events', 'customer_notifications', 'chat_customer_owners'] as $table) {
                if (DB::table($table)->where('customer_account_id', $account->id)->lockForUpdate()->first(['customer_account_id'])) {
                    AdminHistory::record(AdminAction::TestCustomerResetBlocked, 'customer_account', $account->id, $actor);

                    return 'dependency';
                }
            }
            DB::table('customer_login_challenges')->where('id', $challenge->id)->update([
                'challenge_id' => null, 'code_hash' => null, 'attempts' => 0, 'expires_at' => null, 'resend_at' => null,
            ]);
            DB::table('customer_addresses')->where('customer_account_id', $account->id)->delete();
            $account->delete();
            AdminHistory::record(AdminAction::TestCustomerReset, 'customer_account', $account->id, $actor);

            return 'reset';
        }, 3);

        $response = to_route('admin.test-customers.index');

        return match ($result) {
            'reset' => $response->with('success', 'حساب آزمایشی بازنشانی شد. می‌توانید با همین شماره دوباره ثبت‌نام کنید.'),
            'absent' => $response->with('success', 'این حساب دیگر وجود ندارد.'),
            'linked' => $response->withErrors(['reset' => 'حساب به سوابق مشتری متصل است یا وضعیت آزمایشی آن تأیید نشد.']),
            'dependency' => $response->withErrors(['reset' => 'برای این حساب سابقهٔ عملیاتی یا پشتیبانی ثبت شده است. بازنشانی انجام نشد.']),
            default => $response->withErrors(['reset' => 'فقط حساب آزمایشی شماره‌های مجاز را می‌توان بازنشانی کرد.']),
        };
    }
}
