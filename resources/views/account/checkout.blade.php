@extends('layouts.app')
@section('title', 'تکمیل سفارش')
@section('content')
@php
    $zibalAvailable = $zibalAvailable ?? app(\App\Services\ZibalGateway::class)->available();
    $defaultPayment = old('payment_method', $zibalAvailable ? 'zibal' : 'request');
    if ($defaultPayment === 'zibal' && ! $zibalAvailable) {
        $defaultPayment = 'request';
    }
    $methodLabels = array_map(fn (array $meta) => $meta[0], \App\Services\DeliveryManagement::CHECKOUT_METHODS);
@endphp
<div class="np-container commerce-page">
    <section class="store-cart" data-checkout-stepper>
        <div class="checkout-header">
            <h1>تکمیل سفارش</h1>
            <ol class="checkout-steps" aria-label="مراحل تکمیل سفارش">
                <li class="checkout-step is-active" data-step-indicator="1" aria-current="step">
                    <span class="checkout-step__num">۱</span>
                    <span class="checkout-step__label">تحویل و نشانی</span>
                </li>
                <li class="checkout-step" data-step-indicator="2">
                    <span class="checkout-step__num">۲</span>
                    <span class="checkout-step__label">درگاه پرداخت</span>
                </li>
                <li class="checkout-step" data-step-indicator="3">
                    <span class="checkout-step__num">۳</span>
                    <span class="checkout-step__label">تأیید و پرداخت</span>
                </li>
            </ol>
        </div>

        <x-admin.errors />

        <form action="{{ route('cart.checkout') }}" method="post" class="store-checkout commerce-columns" data-store-checkout data-zibal-available="{{ $zibalAvailable ? '1' : '0' }}">
            @csrf
            <x-storefront.cart-context :proof="$proof" :revision="$revision" />
            <input type="hidden" name="operation_key" value="{{ old('operation_key', $operation) }}">
            <input type="hidden" name="quote" value="{{ $quote }}">

            <div class="commerce-checkout-fields">
                {{-- Step 1: Delivery & Address --}}
                <div class="checkout-step-panel is-active" data-checkout-step="1">
                    <fieldset class="commerce-panel">
                        <legend>روش تحویل</legend>
                        <div class="commerce-choices">
                            @foreach (\App\Services\DeliveryManagement::CHECKOUT_METHODS as $method => [$label, $description])
                                <x-storefront.delivery-option :$method :$label :$description
                                    :cost="match($method) { 'pickup' => 'بدون هزینهٔ ارسال', 'tipax' => 'پس‌کرایه', default => 'پس از بررسی' }"
                                    :disabled="$method !== 'pickup' && $addresses->isEmpty()" />
                            @endforeach
                        </div>
                        @error('method')<p class="commerce-field-error">{{ $message }}</p>@enderror
                    </fieldset>

                    <fieldset class="commerce-panel">
                        <legend>گیرنده و نشانی تحویل</legend>
                        @if ($account->name)
                            <div class="commerce-recipient"><span>تحویل حضوری به نام</span> <strong>{{ $account->name }}</strong></div>
                        @endif
                        @if ($addresses->isNotEmpty())
                            <div class="commerce-address-field">
                                <label for="delivery-address">نشانی ذخیره‌شده برای ارسال</label>
                                <div class="commerce-select">
                                    <select id="delivery-address" name="address" @error('address') aria-invalid="true" aria-describedby="checkout-address-error" @enderror>
                                        <option value="">انتخاب نشانی</option>
                                        @foreach ($addresses as $address)
                                            <option value="{{ $address->public_id }}" @selected(old('address') === $address->public_id)>{{ $address->recipient }} · {{ $address->city }} · {{ $address->address }}</option>
                                        @endforeach
                                    </select>
                                    <x-storefront.icon name="chevron-down" />
                                </div>
                            </div>
                        @else
                            <p class="commerce-summary__note">برای ارسال، ابتدا نشانی و مشخصات گیرنده را در حساب خود ذخیره کنید.</p>
                        @endif
                        @error('address')<p class="commerce-field-error" id="checkout-address-error">{{ $message }}</p>@enderror
                        <p class="commerce-field-error" data-step1-error hidden></p>
                        <div class="checkout-step-actions">
                            <a href="{{ route('account.index', ['section' => 'addresses']) }}" class="commerce-action commerce-action--secondary">مدیریت نشانی‌ها</a>
                            <button type="button" class="commerce-action commerce-action--primary" data-checkout-next="2">ادامه به انتخاب درگاه پرداخت</button>
                        </div>
                    </fieldset>
                </div>

                {{-- Step 2: Payment Gateway Selection --}}
                <div class="checkout-step-panel" data-checkout-step="2">
                    <fieldset class="commerce-panel">
                        <legend>انتخاب روش پرداخت</legend>
                        <div class="commerce-choices checkout-payment-choices">
                            @if ($zibalAvailable)
                                <label class="commerce-choice checkout-gateway-card">
                                    <input type="radio" name="payment_method" value="zibal" @checked($defaultPayment === 'zibal') data-payment-label="پرداخت آنلاین با درگاه زیبال">
                                    <span class="checkout-gateway-card__body">
                                        <span class="checkout-gateway-card__title-row">
                                            <strong>پرداخت آنلاین با درگاه زیبال</strong>
                                            <img src="{{ asset('images/zibal-trust-light.svg') }}" width="22" height="26" alt="زیبال" class="checkout-gateway-card__logo" loading="lazy" decoding="async">
                                        </span>
                                        <small>کلیه کارت‌های عضو شتاب · تأیید آنی و انتقال امن به درگاه پرداخت</small>
                                    </span>
                                </label>
                            @endif

                            <label class="commerce-choice checkout-gateway-card">
                                <input type="radio" name="payment_method" value="request" @checked($defaultPayment === 'request') data-payment-label="ثبت درخواست سفارش (پرداخت پس از بررسی)">
                                <span class="checkout-gateway-card__body">
                                    <strong>ثبت درخواست سفارش (پرداخت پس از بررسی)</strong>
                                    <small>ثبت سفارش بدون پرداخت آنلاین؛ پس از تأیید موجودی، صورت‌حساب در حساب کاربری فعال می‌شود.</small>
                                </span>
                            </label>
                        </div>
                        @unless ($zibalAvailable)
                            <p class="commerce-notice mt-3">پرداخت آنلاین فعلاً در دسترس نیست. ثبت درخواست، پرداخت یا رزرو کالا ایجاد نمی‌کند.</p>
                        @endunless
                        @error('payment_method')<p class="commerce-field-error">{{ $message }}</p>@enderror

                        <div class="checkout-step-actions">
                            <button type="button" class="commerce-action commerce-action--secondary" data-checkout-prev="1">بازگشت به مرحله قبل</button>
                            <button type="button" class="commerce-action commerce-action--primary" data-checkout-next="3">ادامه به تأیید نهایی</button>
                        </div>
                    </fieldset>
                </div>

                {{-- Step 3: Confirmation & Redirect --}}
                <div class="checkout-step-panel" data-checkout-step="3">
                    <section class="commerce-panel checkout-confirm-panel" aria-labelledby="checkout-confirm-heading">
                        <h2 id="checkout-confirm-heading">تأیید نهایی سفارش</h2>
                        <dl class="checkout-confirm-list">
                            <div>
                                <dt>روش تحویل</dt>
                                <dd data-confirm-delivery>{{ $methodLabels[old('method', 'pickup')] ?? 'تحویل حضوری' }}</dd>
                            </div>
                            <div data-confirm-address-row hidden>
                                <dt>نشانی ارسال</dt>
                                <dd data-confirm-address>—</dd>
                            </div>
                            <div>
                                <dt>روش پرداخت</dt>
                                <dd data-confirm-payment>{{ $defaultPayment === 'zibal' ? 'پرداخت آنلاین با درگاه زیبال' : 'ثبت درخواست سفارش (پرداخت پس از بررسی)' }}</dd>
                            </div>
                            <div>
                                <dt>مبلغ قابل پرداخت</dt>
                                <dd class="checkout-confirm-total"><x-storefront.money :amount="$total" /></dd>
                            </div>
                        </dl>
                        <div class="checkout-step-actions">
                            <button type="button" class="commerce-action commerce-action--secondary" data-checkout-prev="2">ویرایش روش پرداخت</button>
                        </div>
                    </section>
                </div>
            </div>

            <aside class="store-cart-total commerce-summary">
                <h2>خلاصهٔ سفارش</h2>
                <ul class="commerce-checkout-items">
                    @foreach ($items as $slug => $item)
                        <li><span>{{ $products[$slug]->name }} <small>× {{ \App\Support\FaNumber::digits($item['quantity']) }}</small></span>
                            <x-storefront.money :amount="$products[$slug]->price * $item['quantity']" /></li>
                    @endforeach
                </ul>
                <dl class="commerce-summary__amounts">
                    <div><dt>جمع کالاها</dt><dd><x-storefront.money :amount="$total" /></dd></div>
                </dl>
                <p class="commerce-summary__note">هزینهٔ ارسال در جمع کالاها محاسبه نشده است.</p>
                @unless ($zibalAvailable)
                    <p class="commerce-notice">پرداخت آنلاین فعلاً در دسترس نیست. ثبت درخواست، پرداخت یا رزرو کالا ایجاد نمی‌کند.</p>
                @endunless
                <div class="commerce-actions">
                    <button class="commerce-action commerce-action--primary commerce-action--prominent" type="submit" data-checkout-submit data-label-zibal="پرداخت آنلاین با زیبال" data-label-request="ثبت درخواست سفارش">
                        {{ $defaultPayment === 'zibal' ? 'پرداخت آنلاین با زیبال' : 'ثبت درخواست سفارش' }}
                    </button>
                    <a class="commerce-action commerce-action--text" href="{{ route('cart.index') }}">بازگشت به سبد</a>
                </div>
                <p class="commerce-checkout-status" data-checkout-status role="status" hidden></p>
            </aside>
        </form>
    </section>
</div>
@endsection
