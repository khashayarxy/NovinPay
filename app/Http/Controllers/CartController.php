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
use App\Support\PaymentTestProduct;
use App\Support\RepairIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

        $addresses = CustomerAddress::query()->where('customer_account_id', $account->id)->latest('id')->get();
        $defaultAddress = $addresses->first();
        $checkoutDraft = (array) $request->session()->get('checkout_draft', []);

        return view('account.checkout', [
            ...$data,
            'account' => $account,
            'addresses' => $addresses,
            'defaultAddress' => $defaultAddress,
            'checkoutDraft' => $checkoutDraft,
            'operation' => (string) Str::uuid(),
            'zibalAvailable' => $gateway->available(),
            'iranLocations' => config('iran_locations', []),
        ]);
    }

    public function reverseGeocode(Request $request, CustomerLogin $login): JsonResponse
    {
        $account = $login->authenticatedAccount($request);
        abort_unless($account && $account->is_active, 403);

        $lat = $request->query('lat');
        $lng = $request->query('lng');
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return response()->json(['ok' => false, 'province' => null, 'city' => null, 'address' => null]);
        }

        $latFloat = (float) $lat;
        $lngFloat = (float) $lng;
        if ($latFloat < -90 || $latFloat > 90 || $lngFloat < -180 || $lngFloat > 180) {
            return response()->json(['ok' => false, 'province' => null, 'city' => null, 'address' => null]);
        }

        $serviceKey = trim((string) config('services.neshan.service_key', ''));
        if ($serviceKey === '') {
            return response()->json(['ok' => false, 'province' => null, 'city' => null, 'address' => null]);
        }

        try {
            $response = Http::timeout(4)
                ->connectTimeout(2)
                ->acceptJson()
                ->withHeaders(['Api-Key' => $serviceKey])
                ->get('https://api.neshan.org/v5/reverse', [
                    'lat' => $latFloat,
                    'lng' => $lngFloat,
                ]);

            if (! $response->successful()) {
                return response()->json(['ok' => false, 'province' => null, 'city' => null, 'address' => null]);
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return response()->json(['ok' => false, 'province' => null, 'city' => null, 'address' => null]);
            }

            $address = is_string($payload['formatted_address'] ?? null)
                ? mb_substr(trim($payload['formatted_address']), 0, 400)
                : '';
            [$province, $city] = self::matchIranProvinceAndCity(
                (string) ($payload['state'] ?? ''),
                (string) ($payload['city'] ?? ''),
                (string) ($payload['county'] ?? ''),
                $address
            );

            return response()->json([
                'ok' => $province !== null || $address !== '',
                'province' => $province,
                'city' => $city,
                'address' => $address !== '' ? $address : null,
            ]);
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'province' => null, 'city' => null, 'address' => null]);
        }
    }

    public function saveDraft(Request $request, CustomerLogin $login): JsonResponse
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

        $existingDraft = (array) $request->session()->get('checkout_draft', []);
        foreach ([
            'full_name', 'mobile', 'national_code', 'email',
            'address_mode', 'address', 'province', 'city',
            'street_address', 'postal_code', 'latitude', 'longitude',
            'method', 'payment_method',
        ] as $field) {
            if (array_key_exists($field, $input) && (is_scalar($input[$field]) || $input[$field] === null)) {
                $val = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
                if ($val !== '' && $val !== null) {
                    $existingDraft[$field] = (string) $val;
                }
            }
        }

        $savedAddressPublicId = $existingDraft['address'] ?? null;

        DB::transaction(function () use ($account, $input, &$savedAddressPublicId): void {
            $fresh = CustomerAccount::query()->findOrFail($account->id);
            $dirty = false;

            $fullName = isset($input['full_name']) && is_string($input['full_name']) ? trim($input['full_name']) : '';
            if (mb_strlen($fullName) >= 2 && mb_strlen($fullName) <= 100 && $fresh->name !== $fullName) {
                $fresh->name = $fullName;
                $dirty = true;
            }

            $nationalCode = isset($input['national_code']) && is_string($input['national_code']) ? $input['national_code'] : '';
            if (preg_match('/^[0-9]{10}$/', $nationalCode) && self::isValidIranNationalCode($nationalCode) && $fresh->national_code !== $nationalCode) {
                $fresh->national_code = $nationalCode;
                $dirty = true;
            }

            $mobile = isset($input['mobile']) && is_string($input['mobile']) ? $input['mobile'] : '';
            if (preg_match('/^09[0-9]{9}$/', $mobile) && $fresh->phone !== $mobile) {
                $phoneDigest = RepairIdentity::digest('phone', $mobile);
                $taken = CustomerAccount::query()->where('phone_digest', $phoneDigest)->where('id', '<>', $fresh->id)->exists();
                if (! $taken) {
                    $fresh->phone = $mobile;
                    $fresh->phone_digest = $phoneDigest;
                    $dirty = true;
                }
            }

            $email = isset($input['email']) && is_string($input['email']) ? $input['email'] : '';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 150 && $fresh->email !== $email) {
                $emailDigest = hash_hmac('sha256', $email, config('app.key'));
                $taken = CustomerAccount::query()->where('email_digest', $emailDigest)->where('id', '<>', $fresh->id)->exists();
                if (! $taken) {
                    $fresh->email = $email;
                    $fresh->email_digest = $emailDigest;
                    $dirty = true;
                }
            }

            if ($dirty) {
                $fresh->save();
            }

            $province = isset($input['province']) && is_string($input['province']) ? trim($input['province']) : '';
            $city = isset($input['city']) && is_string($input['city']) ? trim($input['city']) : '';
            $street = isset($input['street_address']) && is_string($input['street_address']) ? trim($input['street_address']) : '';
            $postal = isset($input['postal_code']) && is_string($input['postal_code']) ? $input['postal_code'] : '';
            $lat = isset($input['latitude']) && is_numeric($input['latitude']) ? (float) $input['latitude'] : null;
            $lng = isset($input['longitude']) && is_numeric($input['longitude']) ? (float) $input['longitude'] : null;

            if ($lat !== null && ($lat < -90 || $lat > 90)) {
                $lat = null;
            }
            if ($lng !== null && ($lng < -180 || $lng > 180)) {
                $lng = null;
            }

            if ($province !== '' && $city !== '' && mb_strlen($street) >= 10 && mb_strlen($street) <= 400 && preg_match('/^[0-9]{10}$/', $postal)) {
                $addr = CustomerAddress::query()
                    ->where('customer_account_id', $fresh->id)
                    ->latest('id')
                    ->first();

                if (! $addr || ($addr->province !== $province || $addr->city !== $city || $addr->address !== $street || $addr->postal_code !== $postal)) {
                    if ($addr && $addr->address === $street && $addr->postal_code === $postal) {
                        // Update existing matching address
                    } elseif (! $addr) {
                        $addr = new CustomerAddress;
                        $addr->public_id = (string) Str::uuid();
                        $addr->customer_account_id = $fresh->id;
                    } else {
                        // Update latest address in place if it was created as a checkout address or create a new one
                        $matching = CustomerAddress::query()
                            ->where('customer_account_id', $fresh->id)
                            ->where('province', $province)
                            ->where('city', $city)
                            ->where('address', $street)
                            ->first();
                        if ($matching) {
                            $addr = $matching;
                        }
                    }
                }

                $addr->recipient = $fullName !== '' ? $fullName : ($fresh->name ?: 'گیرنده سفارش');
                $addr->phone = preg_match('/^09[0-9]{9}$/', $mobile) ? $mobile : ($fresh->phone ?: '09000000000');
                $addr->province = $province;
                $addr->city = $city;
                $addr->address = $street;
                $addr->postal_code = $postal;
                if ($lat !== null && $lng !== null) {
                    $addr->latitude = $lat;
                    $addr->longitude = $lng;
                }
                $addr->save();
                $savedAddressPublicId = $addr->public_id;
            } elseif ($lat !== null && $lng !== null) {
                $addr = CustomerAddress::query()
                    ->where('customer_account_id', $fresh->id)
                    ->latest('id')
                    ->first();
                if ($addr) {
                    $addr->latitude = $lat;
                    $addr->longitude = $lng;
                    $addr->save();
                    $savedAddressPublicId = $addr->public_id;
                }
            }
        }, 3);

        if ($savedAddressPublicId) {
            $existingDraft['address'] = $savedAddressPublicId;
        }
        $request->session()->put('checkout_draft', $existingDraft);

        return response()->json([
            'ok' => true,
            'address_id' => $savedAddressPublicId,
        ]);
    }

    public function add(Request $request, Product $product, ShoppingCart $cart, CustomerLogin $login)
    {
        abort_unless(PaymentTestProduct::canSeeProduct($product, $login->authenticatedAccount($request)), 404);

        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.ShoppingCart::MAX_ITEM_QUANTITY]]);

        return $this->changed($request, $cart->change($request, $product->slug, 'add', (int) $data['quantity'], $product), $cart);
    }

    public function update(Request $request, Product $product, ShoppingCart $cart, CustomerLogin $login)
    {
        abort_unless(PaymentTestProduct::canSeeProduct($product, $login->authenticatedAccount($request)), 404);

        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.ShoppingCart::MAX_ITEM_QUANTITY]]);

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
            'payment_method' => ['nullable', Rule::in(['zibal'])],
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
            $fresh = CustomerAccount::query()->findOrFail($account->id);
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
                $province = trim((string) $data['province']);
                $city = trim((string) $data['city']);
                $street = trim((string) $data['street_address']);
                $postal = (string) $data['postal_code'];
                $addr = CustomerAddress::query()
                    ->where('customer_account_id', $fresh->id)
                    ->where('province', $province)
                    ->where('city', $city)
                    ->where('address', $street)
                    ->where('postal_code', $postal)
                    ->latest('id')
                    ->first();

                if (! $addr) {
                    $addr = new CustomerAddress;
                    $addr->public_id = (string) Str::uuid();
                    $addr->customer_account_id = $fresh->id;
                }
                $addr->recipient = trim((string) ($data['full_name'] ?? $fresh->name ?? 'گیرنده سفارش'));
                $addr->phone = (string) ($data['mobile'] ?? $fresh->phone ?? '09000000000');
                $addr->province = $province;
                $addr->city = $city;
                $addr->address = $street;
                $addr->postal_code = $postal;
                $addr->latitude = isset($data['latitude']) && $data['latitude'] !== '' ? (float) $data['latitude'] : $addr->latitude;
                $addr->longitude = isset($data['longitude']) && $data['longitude'] !== '' ? (float) $data['longitude'] : $addr->longitude;
                $addr->save();
                $addressPublicId = $addr->public_id;
            }
        }, 3);

        $draftToSave = (array) $request->session()->get('checkout_draft', []);
        foreach (['full_name', 'mobile', 'national_code', 'email', 'address_mode', 'province', 'city', 'street_address', 'postal_code', 'latitude', 'longitude', 'method', 'payment_method'] as $field) {
            if (! empty($data[$field])) {
                $draftToSave[$field] = (string) $data[$field];
            }
        }
        if ($addressPublicId) {
            $draftToSave['address'] = $addressPublicId;
        }
        $request->session()->put('checkout_draft', $draftToSave);

        if (! $gateway->available()) {
            return redirect()->route('cart.checkout')
                ->withErrors(['payment_method' => 'پرداخت آنلاین فعلاً در دسترس نیست. لطفاً دقایقی دیگر دوباره تلاش کنید.'])
                ->withInput();
        }

        $order = $cart->place($request, $data['operation_key'], $data['quote'], $data['method'], $addressPublicId);

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

    /**
     * @return array{0: ?string, 1: ?string}
     */
    public static function matchIranProvinceAndCity(string $rawState, string $rawCity, string $rawCounty = '', string $rawFormatted = ''): array
    {
        $normalize = static function (string $value): string {
            $val = str_replace(['ي', 'ك', "\u{200c}"], ['ی', 'ک', ' '], trim($value));
            $val = preg_replace('/^(?:استان|شهرستان|شهر|بخش)\s+/u', '', $val) ?? $val;

            return trim((string) preg_replace('/\s+/u', ' ', $val));
        };

        $iranLocations = (array) config('iran_locations', []);
        $stateClean = $normalize($rawState);
        $cityClean = $normalize($rawCity);
        $countyClean = $normalize($rawCounty);
        $formattedClean = $normalize($rawFormatted);

        $matchedProvince = null;
        foreach (array_keys($iranLocations) as $provName) {
            $provNorm = $normalize((string) $provName);
            if ($stateClean !== '' && ($provNorm === $stateClean || str_contains($stateClean, $provNorm) || str_contains($provNorm, $stateClean))) {
                $matchedProvince = (string) $provName;
                break;
            }
        }

        if ($matchedProvince === null && $formattedClean !== '') {
            foreach (array_keys($iranLocations) as $provName) {
                $provNorm = $normalize((string) $provName);
                if ($provNorm !== '' && str_contains($formattedClean, $provNorm)) {
                    $matchedProvince = (string) $provName;
                    break;
                }
            }
        }

        $matchedCity = null;
        if ($matchedProvince !== null && isset($iranLocations[$matchedProvince]) && is_array($iranLocations[$matchedProvince])) {
            $candidates = array_filter([$cityClean, $countyClean]);
            foreach ($candidates as $cand) {
                foreach ($iranLocations[$matchedProvince] as $knownCity) {
                    $knownNorm = $normalize((string) $knownCity);
                    if ($knownNorm === $cand) {
                        $matchedCity = (string) $knownCity;
                        break 2;
                    }
                }
            }
            if ($matchedCity === null) {
                foreach ($candidates as $cand) {
                    foreach ($iranLocations[$matchedProvince] as $knownCity) {
                        $knownNorm = $normalize((string) $knownCity);
                        if ($knownNorm !== '' && (str_contains($cand, $knownNorm) || str_contains($knownNorm, $cand))) {
                            $matchedCity = (string) $knownCity;
                            break 2;
                        }
                    }
                }
            }
            if ($matchedCity === null && $formattedClean !== '') {
                foreach ($iranLocations[$matchedProvince] as $knownCity) {
                    $knownNorm = $normalize((string) $knownCity);
                    if ($knownNorm !== '' && str_contains($formattedClean, $knownNorm)) {
                        $matchedCity = (string) $knownCity;
                        break;
                    }
                }
            }
        }

        return [$matchedProvince, $matchedCity];
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

        if ($cart && $request->headers->get('X-Storefront-Cart-State') === '1') {
            $state = $cart->stateData($request);

            return response()->json([
                'count' => $count,
                'proof' => $state['proof'],
                'revision' => $state['revision'],
                'quantities' => $state['quantity'],
                'available' => $state['available'],
                'stockAvailable' => $state['stockAvailable'],
                'prices' => $state['prices'],
                'total' => $state['total'],
                'unavailable' => $state['unavailable'],
                'overLimit' => $state['overLimit'],
                'separate' => (bool) $state['separate'],
            ]);
        }

        if ($cart && $request->headers->get('X-Storefront-Cart-Html') === '1') {
            $viewData = $cart->viewData($request);

            return response()->json([
                'count' => $count,
                'proof' => $viewData['proof'],
                'revision' => $viewData['revision'],
                'quantities' => $viewData['quantity'],
                'available' => $viewData['available'],
                'stockAvailable' => $viewData['stockAvailable'],
                'prices' => $viewData['prices'],
                'total' => $viewData['total'],
                'unavailable' => $viewData['unavailable'],
                'overLimit' => $viewData['overLimit'],
                'separate' => (bool) $viewData['separate'],
                'html' => view('components.storefront.cart-content', [
                    ...$viewData,
                    'drawer' => $request->headers->get('X-Storefront-Cart-Page') !== '1',
                ])->render(),
            ]);
        }

        return response()->json(['count' => $count]);
    }
}
