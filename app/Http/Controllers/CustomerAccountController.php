<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\FinancialDocument;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use App\Services\CustomerLogin;
use App\Support\CrmAccess;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerAccountController extends Controller
{
    private function validatePrivate(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw new HttpResponseException(back()->withErrors($validator));
        }

        return $validator->validated();
    }

    public function login()
    {
        return view('account.login');
    }

    public function send(Request $request, CustomerLogin $login)
    {
        $request->merge(['phone' => RepairIdentity::phone($request->input('phone'))]);
        $data = $this->validatePrivate($request, ['phone' => ['required', 'regex:/\A09[0-9]{9}\z/']]);
        $request->session()->put('customer_challenge', $login->issue($data['phone']));

        return redirect()->route('account.login')->with('success', 'کد تأیید ارسال شد.');
    }

    public function verify(Request $request, CustomerLogin $login)
    {
        $code = $request->input('code');
        $request->merge(['code' => is_string($code) ? FaNumber::normalizeDigits($code) : '']);
        $data = $this->validatePrivate($request, ['code' => ['required', 'regex:/\A[0-9]{6}\z/']]);
        $account = $login->verify((string) $request->session()->get('customer_challenge'), $data['code']);
        Auth::guard('customer')->login($account);
        $request->session()->regenerate();
        $request->session()->forget('customer_challenge');
        $request->session()->put('customer_session_version', $account->session_version);

        return redirect()->route('account.index');
    }

    public function logout(Request $request)
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('account.login');
    }

    public function index()
    {
        $account = Auth::guard('customer')->user();
        $scoped = fn ($query) => $query->where('customer_account_id', $account->id)->when($account->customer_id, fn ($q) => $q->orWhere('customer_id', $account->customer_id));

        return view('account.index', [
            'account' => $account,
            'repairs' => RepairCase::query()->where($scoped)->latest('id')->paginate(10, ['*'], 'repairs_page'),
            'requests' => RepairRequest::query()->where($scoped)->whereDoesntHave('physicalCase')->latest('id')->paginate(10, ['*'], 'requests_page'),
            'orders' => SalesOrder::query()->where('customer_id', $account->customer_id ?? 0)->latest('id')->paginate(10, ['*'], 'orders_page'),
            'documents' => FinancialDocument::query()->where('customer_id', $account->customer_id ?? 0)->latest('id')->paginate(10, ['*'], 'documents_page'),
            'addresses' => CustomerAddress::query()->where('customer_account_id', $account->id)->latest('id')->get(),
        ]);
    }

    public function profile(Request $request)
    {
        $data = $this->validatePrivate($request, ['name' => ['required', 'string', 'min:2', 'max:100']]);
        DB::transaction(function () use ($data, $request): void {
            $account = CustomerAccount::query()->lockForUpdate()->findOrFail(Auth::guard('customer')->id());
            abort_unless($account->is_active && $account->session_version === $request->session()->get('customer_session_version'), 403);
            $account->name = $data['name'];
            $phoneDigest = RepairIdentity::digest('customer-phone', $account->phone);
            if (! $account->customer_id && ! Customer::query()->where('phone_digest', $phoneDigest)->exists()) {
                $customer = new Customer;
                foreach (['public_id' => (string) Str::uuid(), 'name' => $data['name'], 'contact_phone' => $account->phone, 'phone_digest' => $phoneDigest, 'name_digest' => RepairIdentity::digest('customer-name', CrmAccess::name($data['name'])), 'identity_digest' => RepairIdentity::digest('customer-identity', CrmAccess::name($data['name']).':'.$account->phone), 'is_active' => true, 'version' => 1] as $key => $value) {
                    $customer->$key = $value;
                }
                $customer->save();
                $account->customer_id = $customer->id;
            }
            $account->save();
        }, 3);

        return back()->with('success', 'اطلاعات شما ذخیره شد.');
    }

    public function address(Request $request, ?string $addressId = null)
    {
        $request->merge(['phone' => RepairIdentity::phone($request->input('phone'))]);
        $data = $this->validatePrivate($request, ['recipient' => ['required', 'string', 'max:100'], 'phone' => ['required', 'regex:/\A09[0-9]{9}\z/'], 'province' => ['required', 'string', 'max:100'], 'city' => ['required', 'string', 'max:100'], 'address' => ['required', 'string', 'min:10', 'max:1000'], 'postal_code' => ['required', 'regex:/\A[0-9]{10}\z/']]);
        DB::transaction(function () use ($request, $addressId, $data): void {
            $account = CustomerAccount::query()->lockForUpdate()->findOrFail(Auth::guard('customer')->id());
            abort_unless($account->is_active && $account->session_version === $request->session()->get('customer_session_version'), 403);
            abort_if($addressId === null && CustomerAddress::query()->where('customer_account_id', $account->id)->count() >= 10, 422, 'حداکثر ده آدرس ذخیره می‌شود.');
            $address = $addressId !== null ? CustomerAddress::query()->where('public_id', $addressId)->where('customer_account_id', $account->id)->lockForUpdate()->firstOrFail() : new CustomerAddress;
            $address->customer_account_id = $account->id;
            $address->public_id ??= (string) Str::uuid();
            foreach ($data as $key => $value) {
                $address->$key = $value;
            }
            $address->save();
        }, 3);

        return back()->with('success', 'آدرس ذخیره شد.');
    }

    public function removeAddress(Request $request, string $addressId)
    {
        DB::transaction(function () use ($request, $addressId): void {
            $account = CustomerAccount::query()->lockForUpdate()->findOrFail(Auth::guard('customer')->id());
            abort_unless($account->is_active && $account->session_version === $request->session()->get('customer_session_version'), 403);
            CustomerAddress::query()->where('public_id', $addressId)->where('customer_account_id', $account->id)->lockForUpdate()->firstOrFail()->delete();
        }, 3);

        return back()->with('success', 'آدرس حذف شد؛ نشانی ثبت‌شده در ارسال‌های قبلی حفظ می‌شود.');
    }
}
