@extends('layouts.app')
@section('title', 'تکمیل سفارش')
@section('content')
@php
    $zibalAvailable = $zibalAvailable ?? app(\App\Services\ZibalGateway::class)->available();
    $defaultAddress = $defaultAddress ?? $addresses->first();
    $checkoutDraft = $checkoutDraft ?? [];
    $defaultPayment = old('payment_method', $checkoutDraft['payment_method'] ?? ($zibalAvailable ? 'zibal' : 'request'));
    if ($defaultPayment === 'zibal' && ! $zibalAvailable) {
        $defaultPayment = 'request';
    }
    $iranLocations = $iranLocations ?? config('iran_locations', []);
    $selectedProvince = (string) old('province', $checkoutDraft['province'] ?? $defaultAddress?->province ?? '');
    $selectedCity = (string) old('city', $checkoutDraft['city'] ?? $defaultAddress?->city ?? '');
    $citiesForProvince = $iranLocations[$selectedProvince] ?? [];
    if ($selectedCity !== '' && ! in_array($selectedCity, $citiesForProvince, true)) {
        array_unshift($citiesForProvince, $selectedCity);
    }
    $defaultAddressMode = old('address_mode', $checkoutDraft['address_mode'] ?? ($addresses->isNotEmpty() ? 'saved' : 'new'));
    $defaultAddressId = (string) old('address', $checkoutDraft['address'] ?? $defaultAddress?->public_id ?? '');
    $defaultStreet = (string) old('street_address', $checkoutDraft['street_address'] ?? $defaultAddress?->address ?? '');
    $defaultPostal = (string) old('postal_code', $checkoutDraft['postal_code'] ?? $defaultAddress?->postal_code ?? '');
    $defaultLat = (string) old('latitude', $checkoutDraft['latitude'] ?? ($defaultAddress?->latitude !== null ? (string) $defaultAddress->latitude : ''));
    $defaultLng = (string) old('longitude', $checkoutDraft['longitude'] ?? ($defaultAddress?->longitude !== null ? (string) $defaultAddress->longitude : ''));
    $defaultFullName = (string) old('full_name', $checkoutDraft['full_name'] ?? $account->name ?? '');
    $defaultMobile = (string) old('mobile', $checkoutDraft['mobile'] ?? $account->phone ?? '');
    $defaultNationalCode = (string) old('national_code', $checkoutDraft['national_code'] ?? $account->national_code ?? '');
    $defaultEmail = (string) old('email', $checkoutDraft['email'] ?? $account->email ?? '');
    $defaultMethod = (string) old('method', $checkoutDraft['method'] ?? 'pickup');
    $methodLabels = array_map(fn (array $meta) => $meta[0], \App\Services\DeliveryManagement::CHECKOUT_METHODS);
@endphp
<div class="np-container commerce-page">
    <section class="store-cart" data-checkout-stepper>
        <div class="checkout-header">
            <h1>تکمیل سفارش</h1>
            <ol class="checkout-steps" aria-label="مراحل تکمیل سفارش">
                <li class="checkout-step is-active" data-step-indicator="1" role="button" tabindex="0" aria-current="step">
                    <span class="checkout-step__num">۱</span>
                    <span class="checkout-step__label">مشخصات</span>
                </li>
                <li class="checkout-step" data-step-indicator="2" role="button" tabindex="0">
                    <span class="checkout-step__num">۲</span>
                    <span class="checkout-step__label">آدرس</span>
                </li>
                <li class="checkout-step" data-step-indicator="3" role="button" tabindex="0">
                    <span class="checkout-step__num">۳</span>
                    <span class="checkout-step__label">نحوه ارسال</span>
                </li>
                <li class="checkout-step" data-step-indicator="4" role="button" tabindex="0">
                    <span class="checkout-step__num">۴</span>
                    <span class="checkout-step__label">پرداخت</span>
                </li>
            </ol>
        </div>

        <x-admin.errors />

        <script type="application/json" id="iran-locations-data">{!! json_encode($iranLocations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

        <form action="{{ route('cart.checkout') }}" method="post" class="store-checkout commerce-columns" data-store-checkout data-courier-zones='@json(config('delivery.courier_zones'))' data-iran-address-form data-zibal-available="{{ $zibalAvailable ? '1' : '0' }}" data-draft-url="{{ route('cart.checkout.draft') }}" data-reverse-geocode-url="{{ route('cart.checkout.reverse-geocode') }}" data-account-key="{{ $account->public_id }}" novalidate>
            @csrf
            <x-storefront.cart-context :proof="$proof" :revision="$revision" />
            <input type="hidden" name="operation_key" value="{{ old('operation_key', $operation) }}">
            <input type="hidden" name="quote" value="{{ $quote }}">

            <div class="commerce-checkout-fields">
                {{-- Step 1: Personal Details --}}
                <div class="checkout-step-panel is-active" data-checkout-step="1">
                    <fieldset class="commerce-panel">
                        <legend>مرحله ۱ · مشخصات</legend>
                        <div class="checkout-form-grid">
                            <div class="checkout-field">
                                <label for="checkout-full-name">نام و نام خانوادگی</label>
                                <input id="checkout-full-name" name="full_name" type="text" class="checkout-input @error('full_name') is-invalid @enderror" value="{{ $defaultFullName }}" autocomplete="name" maxlength="100" placeholder="مثلاً علی محمدی">
                                <p class="checkout-field-error" data-error-for="full_name" @unless($errors->has('full_name')) hidden @endunless>@error('full_name'){{ $message }}@enderror</p>
                            </div>
                            <div class="checkout-field">
                                <label for="checkout-mobile">شماره موبایل</label>
                                <input id="checkout-mobile" name="mobile" type="tel" dir="ltr" inputmode="numeric" data-digits-only="11" class="checkout-input @error('mobile') is-invalid @enderror" value="{{ $defaultMobile }}" autocomplete="tel" maxlength="11" placeholder="09123456789">
                                <p class="checkout-field-error" data-error-for="mobile" @unless($errors->has('mobile')) hidden @endunless>@error('mobile'){{ $message }}@enderror</p>
                            </div>
                            <div class="checkout-field">
                                <label for="checkout-national-code">کد ملی (۱۰ رقمی)</label>
                                <input id="checkout-national-code" name="national_code" type="text" dir="ltr" inputmode="numeric" data-digits-only="10" class="checkout-input @error('national_code') is-invalid @enderror" value="{{ $defaultNationalCode }}" maxlength="10" placeholder="۰۰۱۲۳۴۵۶۷۸">
                                <p class="checkout-field-error" data-error-for="national_code" @unless($errors->has('national_code')) hidden @endunless>@error('national_code'){{ $message }}@enderror</p>
                            </div>
                            <div class="checkout-field">
                                <label for="checkout-email">ایمیل (اختیاری)</label>
                                <input id="checkout-email" name="email" type="email" dir="ltr" class="checkout-input @error('email') is-invalid @enderror" value="{{ $defaultEmail }}" autocomplete="email" maxlength="150" placeholder="name@example.com">
                                <p class="checkout-field-error" data-error-for="email" @unless($errors->has('email')) hidden @endunless>@error('email'){{ $message }}@enderror</p>
                            </div>
                        </div>
                        <div class="checkout-step-actions">
                            <a href="{{ route('cart.index') }}" class="commerce-action commerce-action--secondary">بازگشت به سبد خرید</a>
                            <button type="button" class="commerce-action commerce-action--primary" data-checkout-next="2">ادامه به آدرس</button>
                        </div>
                    </fieldset>
                </div>

                {{-- Step 2: Shipping Address & Interactive Map (Map-First) --}}
                <div class="checkout-step-panel" data-checkout-step="2">
                    <fieldset class="commerce-panel">
                        <legend>مرحله ۲ · آدرس</legend>
                        <p class="commerce-summary__note">اگر قصد تحویل حضوری در فروشگاه نوین پی را دارید، می‌توانید بدون وارد کردن نشانی به مرحله بعد بروید.</p>

                        @if ($addresses->isNotEmpty())
                            <div class="checkout-mode-tabs" role="radiogroup" aria-label="انتخاب نوع نشانی">
                                <label class="checkout-mode-tab">
                                    <input type="radio" name="address_mode" value="saved" @checked($defaultAddressMode === 'saved')>
                                    <span>انتخاب از نشانی‌های ذخیره‌شده</span>
                                </label>
                                <label class="checkout-mode-tab">
                                    <input type="radio" name="address_mode" value="new" @checked($defaultAddressMode === 'new')>
                                    <span>ثبت یا ویرایش نشانی روی نقشه</span>
                                </label>
                            </div>

                            <div class="checkout-saved-address-box" data-address-section="saved" @if($defaultAddressMode !== 'saved') hidden @endif>
                                <div class="checkout-field">
                                    <label for="delivery-address">نشانی ذخیره‌شده</label>
                                    <div class="commerce-select">
                                        <select id="delivery-address" name="address" class="checkout-input @error('address') is-invalid @enderror">
                                            <option value="">انتخاب نشانی</option>
                                            @foreach ($addresses as $address)
                                                <option value="{{ $address->public_id }}"
                                                        data-province="{{ $address->province }}"
                                                        data-city="{{ $address->city }}"
                                                        data-street="{{ $address->address }}"
                                                        data-postal="{{ $address->postal_code }}"
                                                        data-lat="{{ $address->latitude ?? '' }}"
                                                        data-lng="{{ $address->longitude ?? '' }}"
                                                        @selected($defaultAddressId === $address->public_id)>{{ $address->recipient }} · {{ $address->province }}، {{ $address->city }} · {{ $address->address }}</option>
                                            @endforeach
                                        </select>
                                        <x-storefront.icon name="chevron-down" />
                                    </div>
                                    <p class="checkout-field-error" data-error-for="address" @unless($errors->has('address')) hidden @endunless>@error('address'){{ $message }}@enderror</p>
                                </div>
                            </div>
                        @else
                            <input type="hidden" name="address_mode" value="new">
                        @endif

                        <div class="checkout-new-address-box" data-address-section="new" @if($addresses->isNotEmpty() && $defaultAddressMode !== 'new') hidden @endif>
                            @php($neshanWebKey = trim((string) config('services.neshan.web_key', '')))
                            <div class="checkout-map" data-checkout-map data-neshan-key="{{ $neshanWebKey }}" data-default-lat="{{ $defaultLat !== '' ? $defaultLat : '35.6597' }}" data-default-lng="{{ $defaultLng !== '' ? $defaultLng : '51.0592' }}">
                                <div class="checkout-map__header">
                                    <span>ابتدا موقعیت دقیق تحویل را روی نقشه انتخاب کنید (کلیک، جابه‌جایی پین یا «موقعیت من»)</span>
                                    <div class="checkout-map__controls">
                                        <button type="button" class="checkout-map__btn" data-map-locate title="موقعیت فعلی من">موقعیت من</button>
                                        <button type="button" class="checkout-map__btn" data-map-zoom="in" aria-label="بزرگ‌نمایی">+</button>
                                        <button type="button" class="checkout-map__btn" data-map-zoom="out" aria-label="کوچک‌نمایی">−</button>
                                    </div>
                                </div>
                                <div class="checkout-map__viewport" data-map-viewport tabindex="0" role="application" aria-label="نقشه انتخاب موقعیت تحویل">
                                    <div class="checkout-map__tiles" data-map-tiles></div>
                                    <div class="checkout-map__pin" data-map-fallback-pin aria-hidden="true"></div>
                                </div>
                                <div class="checkout-map__footer">
                                    <span class="checkout-map__coords" data-map-coords dir="ltr">{{ $defaultLat !== '' && $defaultLng !== '' ? $defaultLat.', '.$defaultLng : 'موقعیت انتخاب نشده (اختیاری)' }}</span>
                                    <span class="checkout-map__attr" data-map-attr>© نقشه نشان</span>
                                </div>
                                <input type="hidden" name="latitude" value="{{ $defaultLat }}" data-map-lat>
                                <input type="hidden" name="longitude" value="{{ $defaultLng }}" data-map-lng>
                            </div>

                            <div class="checkout-form-grid">
                                <div class="checkout-field">
                                    <label for="checkout-province">استان</label>
                                    <select id="checkout-province" name="province" class="checkout-input @error('province') is-invalid @enderror" data-iran-province>
                                        <option value="">انتخاب استان</option>
                                        @foreach (array_keys($iranLocations) as $provinceName)
                                            <option value="{{ $provinceName }}" @selected($selectedProvince === $provinceName)>{{ $provinceName }}</option>
                                        @endforeach
                                    </select>
                                    <p class="checkout-field-error" data-error-for="province" @unless($errors->has('province')) hidden @endunless>@error('province'){{ $message }}@enderror</p>
                                </div>
                                <div class="checkout-field">
                                    <label for="checkout-city">شهر</label>
                                    <select id="checkout-city" name="city" class="checkout-input @error('city') is-invalid @enderror" data-iran-city data-selected-city="{{ $selectedCity }}">
                                        <option value="">{{ $selectedProvince !== '' ? 'انتخاب شهر' : 'ابتدا استان را انتخاب کنید' }}</option>
                                        @foreach ($citiesForProvince as $cityName)
                                            <option value="{{ $cityName }}" @selected($selectedCity === $cityName)>{{ $cityName }}</option>
                                        @endforeach
                                    </select>
                                    <p class="checkout-field-error" data-error-for="city" @unless($errors->has('city')) hidden @endunless>@error('city'){{ $message }}@enderror</p>
                                </div>
                                <div class="checkout-field checkout-field--full">
                                    <label for="checkout-street">آدرس دقیق</label>
                                    <textarea id="checkout-street" name="street_address" rows="2" class="checkout-input @error('street_address') is-invalid @enderror" maxlength="400" placeholder="خیابان، کوچه، پلاک، واحد">{{ $defaultStreet }}</textarea>
                                    <p class="checkout-field-error" data-error-for="street_address" @unless($errors->has('street_address')) hidden @endunless>@error('street_address'){{ $message }}@enderror</p>
                                </div>
                                <div class="checkout-field">
                                    <label for="checkout-postal-code">کد پستی (۱۰ رقمی)</label>
                                    <input id="checkout-postal-code" name="postal_code" type="text" dir="ltr" inputmode="numeric" data-digits-only="10" class="checkout-input @error('postal_code') is-invalid @enderror" value="{{ $defaultPostal }}" maxlength="10" placeholder="۳۳۵۱۶۱۲۳۴۵">
                                    <p class="checkout-field-error" data-error-for="postal_code" @unless($errors->has('postal_code')) hidden @endunless>@error('postal_code'){{ $message }}@enderror</p>
                                </div>
                            </div>
                        </div>

                        <div class="checkout-step-actions">
                            <button type="button" class="commerce-action commerce-action--secondary" data-checkout-prev="1">مرحله قبل</button>
                            <button type="button" class="commerce-action commerce-action--primary" data-checkout-next="3">ادامه به نحوه ارسال</button>
                        </div>
                    </fieldset>
                </div>

                {{-- Step 3: Shipping Method --}}
                <div class="checkout-step-panel" data-checkout-step="3">
                    <fieldset class="commerce-panel">
                        <legend>مرحله ۳ · نحوه ارسال و روش تحویل</legend>
                        <div class="commerce-choices">
                            @foreach (\App\Services\DeliveryManagement::CHECKOUT_METHODS as $method => [$label, $description])
                                <label class="commerce-choice checkout-shipping-card" @if ($method === 'courier') hidden @endif>
                                    <input type="radio" name="method" value="{{ $method }}" @disabled($method === 'courier') @checked($defaultMethod === $method) data-method-label="{{ $label }}">
                                    <span class="checkout-shipping-card__body">
                                        <strong>{{ $label }}</strong>
                                        <small>{{ $description }}</small>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <p class="checkout-field-error" data-error-for="method" @unless($errors->has('method')) hidden @endunless>@error('method'){{ $message }}@enderror</p>
                        <p class="checkout-field-error" data-step3-address-error hidden></p>

                        <div class="checkout-step-actions">
                            <button type="button" class="commerce-action commerce-action--secondary" data-checkout-prev="2">مرحله قبل</button>
                            <button type="button" class="commerce-action commerce-action--primary" data-checkout-next="4">ادامه به پرداخت</button>
                        </div>
                    </fieldset>
                </div>

                {{-- Step 4: Payment Method & Final Confirmation (ONLY step with final submit button) --}}
                <div class="checkout-step-panel" data-checkout-step="4">
                    <fieldset class="commerce-panel">
                        <legend>مرحله ۴ · پرداخت</legend>
                        <div class="commerce-choices checkout-payment-choices">
                            @if ($zibalAvailable)
                                <label class="commerce-choice checkout-gateway-card">
                                    <input type="radio" name="payment_method" value="zibal" @checked($defaultPayment === 'zibal') data-payment-label="پرداخت آنلاین با درگاه زیبال">
                                    <span class="checkout-gateway-card__body">
                                        <span class="checkout-gateway-card__title-row">
                                            <strong>پرداخت آنلاین با درگاه زیبال</strong>
                                            <img src="{{ versioned_asset('images/zibal-trust-light.svg') }}" width="22" height="26" alt="زیبال" class="checkout-gateway-card__logo" loading="lazy" decoding="async">
                                        </span>
                                        <small>کلیه کارت‌های عضو شتاب · تأیید آنی و انتقال امن به درگاه پرداخت</small>
                                    </span>
                                </label>
                            @endif

                            <label class="commerce-choice checkout-gateway-card">
                                <input type="radio" name="payment_method" value="request" @checked($defaultPayment === 'request') data-payment-label="ثبت سفارش (کارت‌به‌کارت / شماره شبا پس از تأیید)">
                                <span class="checkout-gateway-card__body">
                                    <strong>ثبت درخواست سفارش (پرداخت پس از تأیید / کارت‌به‌کارت)</strong>
                                    <small>ثبت سفارش بدون پرداخت آنلاین؛ پس از بررسی، امکان تسویه حضوری یا کارت‌به‌کارت / شبا فعال می‌شود.</small>
                                </span>
                            </label>
                        </div>
                        @unless ($zibalAvailable)
                            <p class="commerce-notice mt-3">پرداخت آنلاین فعلاً در دسترس نیست. ثبت درخواست، پرداخت یا رزرو کالا ایجاد نمی‌کند.</p>
                        @endunless
                        <p class="checkout-field-error" data-error-for="payment_method" @unless($errors->has('payment_method')) hidden @endunless>@error('payment_method'){{ $message }}@enderror</p>

                        <dl class="checkout-confirm-list">
                            <div>
                                <dt>تحویل‌گیرنده</dt>
                                <dd data-confirm-person>{{ old('full_name', $account->name ?: '—') }}</dd>
                            </div>
                            <div>
                                <dt>نحوه ارسال</dt>
                                <dd data-confirm-delivery>{{ $methodLabels[$defaultMethod] ?? 'تحویل حضوری در فروشگاه نوین پی' }}</dd>
                            </div>
                            <div data-confirm-address-row hidden>
                                <dt>نشانی ارسال</dt>
                                <dd data-confirm-address>—</dd>
                            </div>
                            <div>
                                <dt>نحوه پرداخت</dt>
                                <dd data-confirm-payment>{{ $defaultPayment === 'zibal' ? 'پرداخت آنلاین با درگاه زیبال' : 'ثبت درخواست سفارش' }}</dd>
                            </div>
                            <div>
                                <dt>مبلغ قابل پرداخت</dt>
                                <dd class="checkout-confirm-total"><x-storefront.money :amount="$total" /></dd>
                            </div>
                        </dl>

                        <div class="checkout-step-actions">
                            <button type="button" class="commerce-action commerce-action--secondary" data-checkout-prev="3">مرحله قبل</button>
                            <button class="commerce-action commerce-action--primary commerce-action--prominent" type="submit" data-checkout-submit data-label-zibal="پرداخت آنلاین با زیبال" data-label-request="ثبت درخواست سفارش">
                                {{ $defaultPayment === 'zibal' ? 'پرداخت آنلاین با زیبال' : 'ثبت درخواست سفارش' }}
                            </button>
                        </div>
                        <p class="commerce-checkout-status" data-checkout-status role="status" hidden></p>
                    </fieldset>
                </div>
            </div>

            <aside class="store-cart-total commerce-summary">
                <h2>خلاصهٔ سفارش</h2>
                <ul class="commerce-checkout-items">
                    @foreach ($items as $slug => $item)
                        <li><span>{{ \App\Support\LatinText::html($products[$slug]->name) }} <small>× {{ \App\Support\FaNumber::digits($item['quantity']) }}</small></span>
                            <x-storefront.money :amount="$products[$slug]->price * $item['quantity']" /></li>
                    @endforeach
                </ul>
                <dl class="commerce-summary__amounts">
                    <div><dt>جمع کالاها</dt><dd><x-storefront.money :amount="$total" /></dd></div>
                </dl>
                <p class="commerce-summary__note">هزینهٔ ارسال در جمع کالاها محاسبه نشده است. دکمهٔ پرداخت در مرحلهٔ چهارم نمایش داده می‌شود.</p>
                <div class="commerce-actions">
                    <a class="commerce-action commerce-action--text" href="{{ route('cart.index') }}">بازگشت به سبد</a>
                </div>
            </aside>
        </form>
    </section>
</div>
@endsection
