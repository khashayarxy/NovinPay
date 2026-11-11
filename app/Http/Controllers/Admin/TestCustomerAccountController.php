<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerAccount;
use App\Services\CustomerLogin;
use App\Services\TestCustomerReset;
use App\Support\FaNumber;
use Illuminate\Http\Request;

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

    public function reset(Request $request, TestCustomerReset $reset, string $record)
    {
        $request->validate(['current_password' => ['required', 'string', 'max:255', 'current_password:web'], 'confirm' => ['accepted'], 'scope' => ['required', 'regex:/\A[a-f0-9]{64}\z/D']]);
        $candidate = CustomerAccount::query()->where('public_id', $record)->first();
        $result = $candidate ? $reset->reset($candidate, $request->user()->id, $request->input('scope')) : ['absent'];

        $response = to_route('admin.test-customers.index');

        return match ($result[0]) {
            'reset' => $response->with('success', 'حساب آزمایشی و '.FaNumber::digits($result[1]).' درخواست آزمایشی بازنشانی شد. می‌توانید با همین شماره دوباره ثبت‌نام کنید.'),
            'absent' => $response->with('success', 'این حساب دیگر وجود ندارد.'),
            'blocked' => $response->withErrors(['reset' => $result[1]]),
            default => $response->withErrors(['reset' => 'فقط حساب آزمایشی شماره‌های مجاز را می‌توان بازنشانی کرد.']),
        };
    }
}
