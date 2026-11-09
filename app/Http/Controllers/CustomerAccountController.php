<?php

namespace App\Http\Controllers;

use App\Enums\RepairStage;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use App\Services\CustomerLogin;
use App\Support\CrmAccess;
use App\Support\CustomerTimeline;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    public function login(Request $request)
    {
        $account = Auth::guard('customer')->user();
        if ($account && $account->is_active && $account->session_version === $request->session()->get('customer_session_version')
            && ($account->verified_at || app(CustomerLogin::class)->testAllowed($account->phone))) {
            return redirect()->route('account.index');
        }

        return response()->view('account.login', [
            'phone' => $request->session()->get('customer_login_phone'),
            'challenge' => $request->session()->has('customer_challenge'),
            'resendAt' => (int) $request->session()->get('customer_resend_at', 0),
        ]);
    }

    public function restart(Request $request)
    {
        $request->session()->forget(['customer_login_phone', 'customer_challenge', 'customer_resend_at']);

        return redirect()->route('account.login');
    }

    public function start(Request $request, CustomerLogin $login)
    {
        $request->merge(['phone' => RepairIdentity::phone($request->input('phone'))]);
        $data = $this->validatePrivate($request, ['phone' => ['required', 'regex:/\A09[0-9]{9}\z/']]);
        if ($request->session()->get('customer_login_phone') !== $data['phone']) {
            $request->session()->forget(['customer_challenge', 'customer_resend_at']);
        }
        $request->session()->put('customer_login_phone', $data['phone']);

        return $this->requestCode($request, $login, $data['phone']);
    }

    public function send(Request $request, CustomerLogin $login)
    {
        $sessionPhone = $request->session()->get('customer_login_phone');
        if (is_string($sessionPhone)) {
            return $this->requestCode($request, $login, $sessionPhone);
        }
        $request->merge(['phone' => RepairIdentity::phone($request->input('phone'))]);
        $data = $this->validatePrivate($request, ['phone' => ['required', 'regex:/\A09[0-9]{9}\z/']]);
        $request->session()->put('customer_challenge', $login->issue($data['phone']));
        $request->session()->put('customer_login_phone', $data['phone']);

        return redirect()->route('account.login')->with('success', 'کد تأیید ارسال شد.');
    }

    private function requestCode(Request $request, CustomerLogin $login, string $phone)
    {
        $routeParameters = $request->boolean('recover') ? ['method' => 'recovery'] : [];
        try {
            $request->session()->put('customer_challenge', $login->issue($phone));
            $request->session()->put('customer_resend_at', now()->addMinute()->timestamp);
        } catch (HttpException $exception) {
            if (! in_array($exception->getStatusCode(), [429, 503], true)) {
                throw $exception;
            }

            return redirect()->route('account.login', $routeParameters)->withErrors(['code' => $exception->getMessage()]);
        }

        return redirect()->route('account.login', $routeParameters)->with('success', 'کد تأیید آماده است.');
    }

    public function verify(Request $request, CustomerLogin $login)
    {
        $code = $request->input('code');
        $request->merge(['code' => is_string($code) ? FaNumber::normalizeDigits($code) : '']);
        $data = $this->validatePrivate($request, ['code' => ['required', 'regex:/\A[0-9]{6}\z/']]);
        try {
            $account = $login->verify((string) $request->session()->get('customer_challenge'), $data['code']);
        } catch (ValidationException $exception) {
            return redirect()->route('account.login', $request->boolean('recover') ? ['method' => 'recovery'] : [])->withErrors($exception->errors());
        }

        return $this->authenticated($request, $account, true);
    }

    public function passwordLogin(Request $request, CustomerLogin $login)
    {
        $data = $this->validatePrivate($request, ['password' => ['required', 'string', 'max:72']]);
        $phone = $request->session()->get('customer_login_phone');
        $password = $data['password'];
        $account = is_string($phone) && strlen($password) <= 72 && ! str_contains($password, "\0")
            ? $login->passwordAccount($phone, $password) : null;
        if (! $account) {
            return redirect()->route('account.login', ['method' => 'password'])->withErrors([
                'password' => 'ورود با این رمز ممکن نیست. رمز را بررسی کنید یا با کد تأیید وارد شوید.',
            ]);
        }

        return $this->authenticated($request, $account, false);
    }

    private function authenticated(Request $request, CustomerAccount $account, bool $otp)
    {
        $updated = CustomerAccount::query()->whereKey($account->id)->where('session_version', $account->session_version)
            ->where('is_active', true)->update(['last_login_at' => now()]);
        if (! $updated && ! CustomerAccount::query()->whereKey($account->id)->where('session_version', $account->session_version)->where('is_active', true)->exists()) {
            return redirect()->route('account.login')->withErrors(['code' => 'نشست معتبر نیست. دوباره وارد شوید.']);
        }
        Auth::guard('customer')->login($account);
        $request->session()->regenerate(true);
        $request->session()->forget(['customer_login_phone', 'customer_challenge', 'customer_resend_at', 'customer_otp_at', 'customer_password_offer']);
        $request->session()->put('customer_session_version', $account->session_version);
        if ($otp) {
            $request->session()->put('customer_otp_at', now()->timestamp);
            if (! $account->password) {
                $request->session()->put('customer_password_offer', true);

                return redirect()->route('account.index', ['section' => 'security']);
            }
            if ($request->boolean('recover')) {
                return redirect()->route('account.index', ['section' => 'security']);
            }
        }

        return $this->finishLogin($request);
    }

    private function finishLogin(Request $request)
    {
        return redirect()->to($request->session()->pull('customer_intended', route('account.index')));
    }

    public function skipPassword(Request $request)
    {
        $request->session()->forget('customer_password_offer');

        return $this->finishLogin($request);
    }

    public function password(Request $request)
    {
        $data = $this->validatePrivate($request, [
            'password' => ['required', 'string', 'min:10', 'max:72', 'confirmed', function ($attribute, $value, $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('رمز عبور بیش از حد طولانی است یا نویسهٔ نامعتبر دارد.');
                }
            }],
            'current_password' => ['nullable', 'string', 'max:72'],
        ]);
        $fresh = DB::transaction(function () use ($request, $data): CustomerAccount {
            $account = CustomerAccount::query()->lockForUpdate()->findOrFail(Auth::guard('customer')->id());
            abort_unless($account->is_active && $account->session_version === $request->session()->get('customer_session_version'), 403);
            $verifiedAt = (int) $request->session()->get('customer_otp_at', 0);
            $recent = $verifiedAt > 0 && $verifiedAt <= now()->timestamp && $verifiedAt >= now()->subMinutes(5)->timestamp;
            $current = $data['current_password'] ?? '';
            if (! $recent && (! $account->password || strlen($current) > 72 || str_contains($current, "\0") || ! Hash::check($current, $account->password))) {
                throw ValidationException::withMessages(['current_password' => 'رمز فعلی را بررسی کنید یا دوباره با کد تأیید وارد شوید.']);
            }
            $account->password = Hash::make($data['password']);
            $account->session_version++;
            $account->save();

            return $account;
        }, 3);
        $offer = $request->session()->pull('customer_password_offer', false);
        $request->session()->forget('customer_otp_at');
        $request->session()->put('customer_session_version', $fresh->session_version);
        $request->session()->regenerate(true);

        return ($offer ? $this->finishLogin($request) : redirect()->route('account.index', ['section' => 'security']))->with('success', 'رمز عبور ذخیره شد.');
    }

    public function logout(Request $request)
    {
        Auth::guard('customer')->logout();
        $request->session()->forget(['customer_session_version', 'customer_otp_at', 'customer_password_offer', 'customer_login_phone', 'customer_challenge', 'customer_resend_at', 'customer_intended', 'store_cart', 'repair_submission_token', 'repair_reference']);
        $request->session()->regenerate(true);

        return redirect()->route('account.login');
    }

    public function index(Request $request)
    {
        $account = Auth::guard('customer')->user();
        $section = $request->query('section');
        $sections = ['overview', 'repairs', 'orders', 'payments', 'delivery', 'profile', 'addresses', 'security'];
        $section = in_array($section, $sections, true) ? $section : 'overview';
        $repairs = RepairCase::query()->where($this->owned($account));
        $requests = RepairRequest::query()->where($this->owned($account));
        $orders = SalesOrder::query()->where($this->owned($account));
        $documents = FinancialDocument::query()->where('customer_id', $account->customer_id ?? 0);

        return view('account.index', [
            'account' => $account,
            'section' => $section,
            'counts' => $section === 'overview' ? [
                'requests' => (clone $requests)->count(),
                'active' => (clone $repairs)->whereNotIn('status', [RepairStage::Delivered->value, RepairStage::Returned->value])->count(),
                'orders' => (clone $orders)->count(),
            ] : [],
            'repairs' => in_array($section, ['overview', 'repairs'], true) ? $this->listing($repairs, $section, 'repairs_page') : [],
            'requests' => in_array($section, ['overview', 'repairs'], true) ? $this->listing($requests->whereDoesntHave('physicalCase'), $section, 'requests_page') : [],
            'orders' => in_array($section, ['overview', 'orders'], true) ? $this->listing($orders, $section, 'orders_page') : [],
            'documents' => in_array($section, ['payments', 'delivery'], true) ? $this->listing($documents, $section, 'page') : [],
            'addresses' => $section === 'addresses' ? CustomerAddress::query()->where('customer_account_id', $account->id)->latest('id')->get() : [],
            'complete' => $section === 'overview' && $account->name && CustomerAddress::query()->where('customer_account_id', $account->id)->exists(),
            'recentOtp' => (int) $request->session()->get('customer_otp_at', 0) >= now()->subMinutes(5)->timestamp,
        ]);
    }

    private function listing($query, string $section, string $page)
    {
        $query->latest('id');

        return $section === 'overview' ? $query->limit(3)->get() : $query->paginate(10, ['*'], $page)->appends(['section' => $section]);
    }

    private function owned(CustomerAccount $account): \Closure
    {
        return fn ($query) => $query->where('customer_account_id', $account->id)
            ->when($account->customer_id, fn ($q) => $q->orWhere('customer_id', $account->customer_id));
    }

    public function repairRequest(string $record)
    {
        $account = Auth::guard('customer')->user();
        $repair = RepairRequest::query()->where($this->owned($account))->where('tracking_reference', $record)->firstOrFail();
        $case = $repair->physicalCase()->where($this->owned($account))->first();
        if ($case) {
            return redirect()->route('account.repairs.show', $case->public_id);
        }

        return view('account.repair', ['repair' => $repair, 'case' => false, 'timeline' => CustomerTimeline::request($repair), 'document' => null, 'plan' => null]);
    }

    public function repair(string $record)
    {
        $account = Auth::guard('customer')->user();
        $repair = RepairCase::query()->where($this->owned($account))->where('public_id', $record)
            ->with(['onlineRequest' => fn ($q) => $q->where($this->owned($account)), 'currentEstimate.decision', 'delivery', 'events' => fn ($q) => $q->orderBy('id')])->firstOrFail();
        [$document, $plan] = $this->settlement($account, 'repair_case_id', $repair->id);

        return view('account.repair', ['repair' => $repair, 'case' => true, 'timeline' => CustomerTimeline::repair($repair, $plan), 'document' => $document, 'plan' => $plan]);
    }

    public function order(string $record)
    {
        $account = Auth::guard('customer')->user();
        $order = SalesOrder::query()->where($this->owned($account))->where('public_id', $record)
            ->with(['lines', 'events' => fn ($q) => $q->orderBy('id')])->firstOrFail();
        [$document, $plan] = $this->settlement($account, 'sales_order_id', $order->id);

        return view('account.order', ['order' => $order, 'timeline' => CustomerTimeline::order($order, $plan), 'document' => $document, 'plan' => $plan]);
    }

    private function settlement(CustomerAccount $account, string $column, int $id): array
    {
        $document = FinancialDocument::query()->where('customer_id', $account->customer_id ?? 0)->where($column, $id)->first();
        $plan = $document ? DeliveryPlan::query()->where('financial_document_id', $document->id)->first() : null;

        return [$document, $plan];
    }

    public function profile(Request $request)
    {
        $data = $this->validatePrivate($request, ['name' => ['required', 'string', 'min:2', 'max:100']]);
        DB::transaction(function () use ($data, $request): void {
            $account = CustomerAccount::query()->lockForUpdate()->findOrFail(Auth::guard('customer')->id());
            abort_unless($account->is_active && $account->session_version === $request->session()->get('customer_session_version'), 403);
            $account->name = $data['name'];
            $phoneDigest = RepairIdentity::digest('customer-phone', $account->phone);
            if ($account->verified_at && ! $account->customer_id && ! Customer::query()->where('phone_digest', $phoneDigest)->exists()) {
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
        $postal = $request->input('postal_code');
        $request->merge(['postal_code' => is_string($postal) ? FaNumber::normalizeDigits($postal) : '']);
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
