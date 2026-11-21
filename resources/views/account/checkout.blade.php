@extends('layouts.app')
@section('title', 'تکمیل سفارش')
@section('content')
<div class="np-container commerce-page">
    <section class="store-cart">
        <h1>تکمیل سفارش</h1>
        <x-admin.errors />
        <form action="{{ route('cart.checkout') }}" method="post" class="store-checkout commerce-columns" data-store-checkout>
            @csrf
            <x-storefront.cart-context :proof="$proof" :revision="$revision" />
            <input type="hidden" name="operation_key" value="{{ old('operation_key', $operation) }}">
            <input type="hidden" name="quote" value="{{ $quote }}">
            <div class="commerce-checkout-fields">
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
                    <a href="{{ route('account.index', ['section' => 'addresses']) }}" class="commerce-action commerce-action--secondary">مدیریت نشانی‌ها</a>
                </fieldset>
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
                @unless (app(\App\Services\ZibalGateway::class)->available())
                    <p class="commerce-notice">پرداخت آنلاین فعلاً در دسترس نیست. ثبت درخواست، پرداخت یا رزرو کالا ایجاد نمی‌کند.</p>
                @endunless
                <div class="commerce-actions">
                    <button class="commerce-action commerce-action--primary commerce-action--prominent" type="submit" data-checkout-submit>ثبت درخواست سفارش</button>
                    <a class="commerce-action commerce-action--text" href="{{ route('cart.index') }}">بازگشت به سبد</a>
                </div>
                <p class="commerce-checkout-status" data-checkout-status role="status" hidden></p>
            </aside>
        </form>
    </section>
</div>
@endsection
