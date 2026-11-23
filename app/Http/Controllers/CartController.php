<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\Product;
use App\Services\CustomerLogin;
use App\Services\DeliveryManagement;
use App\Services\LegacyCartHold;
use App\Services\ShoppingCart;
use App\Services\ZibalGateway;
use App\Services\ZibalPayments;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CartController extends Controller
{
    public function index(Request $request, ShoppingCart $cart)
    {
        return view('account.cart', $this->details($request, $cart));
    }

    public function review(Request $request, ShoppingCart $cart, CustomerLogin $login, ZibalGateway $gateway)
    {
        $account = $login->authenticatedAccount($request);
        $data = $this->details($request, $cart);
        if ($data['products']->isEmpty() || $data['unavailable'] !== [] || $data['overLimit']) {
            return redirect()->route('cart.index');
        }

        return view('account.checkout', [
            ...$data,
            'account' => $account,
            'addresses' => CustomerAddress::query()->where('customer_account_id', $account->id)->latest('id')->get(),
            'operation' => (string) Str::uuid(),
            'zibalAvailable' => $gateway->available(),
            'iranLocations' => config('iran_locations', []),
        ]);
    }

    public function add(Request $request, Product $product, ShoppingCart $cart)
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:10']]);

        return $this->changed($request, $cart->change($request, $product->slug, 'add', (int) $data['quantity'], $product), $cart);
    }

    public function update(Request $request, Product $product, ShoppingCart $cart)
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:10']]);

        return $this->changed($request, $cart->change($request, $product->slug, 'quantity', (int) $data['quantity'], $product), $cart);
    }

    public function remove(Request $request, string $slug, ShoppingCart $cart)
    {
        $data = $request->validate(['scope' => ['required', Rule::in(['hide', 'account', 'device', 'restore'])]]);

        return $this->changed($request, $cart->change($request, $slug, $data['scope']), $cart);
    }

    public function claimLegacy(Request $request, ShoppingCart $cart, LegacyCartHold $legacy)
    {
        return $this->changed($request, $cart->importLegacy($request, $legacy), $cart);
    }

    public function checkout(Request $request, ShoppingCart $cart, CustomerLogin $login, ZibalGateway $gateway, ZibalPayments $payments)
    {
        $account = $login->authenticatedAccount($request);
        abort_unless($account && $account->is_active, 403);

        $input = $request->all();
        foreach (['mobile', 'national_code', 'postal_code'] as $digitField) {
            if (isset($input[$digitField]) && is_string($input[$digitField])) {
                $input[$digitField] = preg_replace('/\s+/', '', FaNumber::normalizeDigits(trim($input[$digitField])));
            }
        }
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }

        $method = $input['method'] ?? null;
        $addressMode = $input['address_mode'] ?? (empty($input['address']) && ! empty($input['street_address']) ? 'new' : 'saved');
        $needsAddress = $method !== 'pickup';
        $creatingInlineAddress = $needsAddress && $addressMode === 'new';

        $rules = [
            'operation_key' => ['required', 'uuid'],
            'quote' => ['required', 'string', 'size:64'],
            'method' => ['required', Rule::in(array_keys(DeliveryManagement::CHECKOUT_METHODS))],
            'payment_method' => ['nullable', Rule::in(['zibal', 'request'])],
            'full_name' => ['nullable', 'string', 'min:2', 'max:100'],
            'mobile' => ['nullable', 'regex:/^09[0-9]{9}$/'],
            'national_code' => ['nullable', 'regex:/^[0-9]{10}$/', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && $value !== '' && ! self::isValidIranNationalCode($value)) {
                    $fail('کد ملی واردشده معتبر نیست.');
                }
            }],
            'email' => ['nullable', 'email', 'max:150'],
            'address_mode' => ['nullable', Rule::in(['saved', 'new'])],
            'address' => $needsAddress && ! $creatingInlineAddress ? ['required', 'uuid'] : ['nullable', 'uuid'],
            'province' => $creatingInlineAddress ? ['required', 'string', 'max:60'] : ['nullable', 'string', 'max:60'],
            'city' => $creatingInlineAddress ? ['required', 'string', 'max:60'] : ['nullable', 'string', 'max:60'],
            'street_address' => $creatingInlineAddress ? ['required', 'string', 'min:10', 'max:400'] : ['nullable', 'string', 'max:400'],
            'postal_code' => $creatingInlineAddress ? ['required', 'regex:/^[0-9]{10}$/'] : ['nullable', 'regex:/^[0-9]{10}$/'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];

        $messages = [
            'method.required' => 'لطفاً نحوه ارسال سفارش را انتخاب کنید.',
            'method.in' => 'نحوه ارسال انتخاب‌شده معتبر نیست.',
            'full_name.min' => 'نام و نام خانوادگی باید حداقل ۲ کاراکتر باشد.',
            'mobile.regex' => 'لطفاً شماره موبایل معتبر ۱۱ رقمی وارد کنید (مانند ۰۹۱۲۳۴۵۶۷۸۹).',
            'national_code.regex' => 'کد ملی باید دقیقاً ۱۰ رقم باشد.',
            'email.email' => 'لطفاً نشانی ایمیل معتبر وارد کنید.',
            'address.required' => 'لطفاً یک نشانی ذخیره‌شده انتخاب کنید یا نشانی جدید وارد نمایید.',
            'province.required' => 'لطفاً استان محل تحویل را انتخاب کنید.',
            'city.required' => 'لطفاً شهر محل تحویل را انتخاب کنید.',
            'street_address.required' => 'لطفاً آدرس دقیق تحویل را وارد کنید.',
            'street_address.min' => 'آدرس دقیق باید حداقل ۱۰ کاراکتر باشد.',
            'postal_code.required' => 'لطفاً کد پستی ۱۰ رقمی را وارد کنید.',
            'postal_code.regex' => 'کد پستی باید دقیقاً ۱۰ رقم بدون خط تیره باشد.',
        ];

        $data = Validator::make($input, $rules, $messages)->validate();

        // Save inline personal details & optional new address directly inside checkout (no /account detour).
        $addressPublicId = $data['address'] ?? null;
        DB::transaction(function () use ($account, $data, $creatingInlineAddress, &$addressPublicId): void {
            $fresh = CustomerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $dirty = false;
            if (! empty($data['full_name'])) {
                $fresh->name = trim($data['full_name']);
                $dirty = true;
            }
            if (! empty($data['national_code'])) {
                $fresh->national_code = $data['national_code'];
                $dirty = true;
            }
            if (! empty($data['mobile'])) {
                $phoneDigest = RepairIdentity::digest('phone', $data['mobile']);
                $taken = CustomerAccount::query()->where('phone_digest', $phoneDigest)->where('id', '<>', $fresh->id)->exists();
                if (! $taken) {
                    $fresh->phone = $data['mobile'];
                    $fresh->phone_digest = $phoneDigest;
                    $dirty = true;
                }
            }
            if (! empty($data['email'])) {
                $emailDigest = hash_hmac('sha256', $data['email'], config('app.key'));
                $taken = CustomerAccount::query()->where('email_digest', $emailDigest)->where('id', '<>', $fresh->id)->exists();
                if (! $taken) {
                    $fresh->email = $data['email'];
                    $fresh->email_digest = $emailDigest;
                    $dirty = true;
                }
            }
            if ($dirty) {
                $fresh->save();
            }

            if ($creatingInlineAddress) {
                $addr = new CustomerAddress;
                $addr->public_id = (string) Str::uuid();
                $addr->customer_account_id = $fresh->id;
                $addr->recipient = trim((string) ($data['full_name'] ?? $fresh->name ?? 'گیرنده سفارش'));
                $addr->phone = (string) ($data['mobile'] ?? $fresh->phone ?? '09000000000');
                $addr->province = trim((string) $data['province']);
                $addr->city = trim((string) $data['city']);
                $addr->address = trim((string) $data['street_address']);
                $addr->postal_code = (string) $data['postal_code'];
                $addr->latitude = isset($data['latitude']) && $data['latitude'] !== '' ? (float) $data['latitude'] : null;
                $addr->longitude = isset($data['longitude']) && $data['longitude'] !== '' ? (float) $data['longitude'] : null;
                $addr->save();
                $addressPublicId = $addr->public_id;
            }
        }, 3);

        $order = $cart->place($request, $data['operation_key'], $data['quote'], $data['method'], $addressPublicId);

        $paymentMethod = $data['payment_method'] ?? 'request';
        if ($paymentMethod === 'zibal' && $gateway->available()) {
            $freshAccount = CustomerAccount::query()->findOrFail($account->id);
            $attempt = $payments->startForOrderCheckout(
                $freshAccount,
                $order,
                $data['method'],
                $addressPublicId,
                $data['operation_key']
            );
            $destination = $attempt->state === 'pending' && $attempt->track_id ? 'payments.connect' : 'payments.result';

            return redirect()->route($destination, $attempt->public_id, 303);
        }

        return redirect()->route('account.index', ['section' => 'orders'])->with('success', 'درخواست سفارش ثبت شد. پس از تأیید موجودی، صورت‌حساب و روش تحویل در حساب شما نمایش داده می‌شود.');
    }

    public static function isValidIranNationalCode(string $code): bool
    {
        if (! preg_match('/^[0-9]{10}$/', $code) || preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $code[$i]) * (10 - $i);
        }
        $remainder = $sum % 11;
        $check = (int) $code[9];

        return $remainder < 2 ? $check === $remainder : $check === (11 - $remainder);
    }

    private function details(Request $request, ShoppingCart $cart): array
    {
        return $cart->viewData($request);
    }

    private function changed(Request $request, int $count, ?ShoppingCart $cart = null)
    {
        if (! $request->expectsJson()) {
            return redirect()->route('cart.index');
        }

        if ($cart && $request->headers->get('X-Storefront-Cart-Html') === '1') {
            $viewData = $cart->viewData($request);

            return response()->json([
                'count' => $count,
                'proof' => $viewData['proof'],
                'revision' => $viewData['revision'],
                'html' => view('components.storefront.cart-content', [...$viewData, 'drawer' => true])->render(),
            ]);
        }

        return response()->json(['count' => $count]);
    }
}
