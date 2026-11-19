@extends('layouts.app')
@section('title', 'تکمیل سفارش')
@section('compact-footer', '1')
@section('content')
<div class="np-container py-8">
    <section class="public-panel store-cart">
        <h1>تکمیل سفارش</h1>
        <x-admin.errors />
        <form action="{{ route('cart.checkout') }}" method="post" class="store-checkout">
            @csrf
            <x-storefront.cart-context :proof="$proof" :revision="$revision" />
            <input type="hidden" name="operation_key" value="{{ old('operation_key', $operation) }}">
            <input type="hidden" name="quote" value="{{ $quote }}">
            <fieldset>
                <legend>روش تحویل</legend>
                @foreach (\App\Services\DeliveryManagement::CHECKOUT_METHODS as $method => [$label, $description])
                    <label><input type="radio" name="method" value="{{ $method }}" required @checked(old('method') === $method) @disabled($method !== 'pickup' && $addresses->isEmpty())> {{ $label }} <small>{{ $description }}</small></label>
                @endforeach
            </fieldset>
            <fieldset>
                <legend>گیرنده و نشانی تحویل</legend>
                @if ($account->name)<p>تحویل حضوری به نام {{ $account->name }}</p>@endif
                @if ($addresses->isNotEmpty())
                    <label for="delivery-address">نشانی ذخیره‌شده برای ارسال</label>
                    <select class="input" id="delivery-address" name="address">
                        <option value="">انتخاب نشانی</option>
                        @foreach ($addresses as $address)
                            <option value="{{ $address->public_id }}" @selected(old('address') === $address->public_id)>{{ $address->recipient }} · {{ $address->city }} · {{ $address->address }}</option>
                        @endforeach
                    </select>
                @else
                    <p>برای ارسال، ابتدا نشانی و مشخصات گیرنده را در حساب خود ذخیره کنید.</p>
                @endif
                <a href="{{ route('account.index', ['section' => 'addresses']) }}" class="text-link">مدیریت نشانی‌ها</a>
            </fieldset>
            <div class="store-cart-total">
                <h2>خلاصهٔ سفارش</h2>
                <ul>
                    @foreach ($items as $slug => $item)
                        <li>{{ $products[$slug]->name }} · {{ \App\Support\FaNumber::digits($item['quantity']) }} عدد</li>
                    @endforeach
                </ul>
                <p>جمع کالاها <strong>{{ \App\Support\FaNumber::digits(number_format($total, 0, '.', '٬')) }} تومان</strong></p>
                <p>هزینهٔ ارسال در جمع کالاها محاسبه نشده است.</p>
                @unless (app(\App\Services\ZibalGateway::class)->available())
                    <p>پرداخت آنلاین فعلاً در دسترس نیست. با ثبت این درخواست، مبلغی پرداخت و کالایی رزرو نمی‌شود.</p>
                @endunless
                <button class="btn btn-primary" type="submit">ثبت درخواست سفارش</button>
                <a href="{{ route('cart.index') }}">بازگشت به سبد خرید</a>
            </div>
        </form>
    </section>
</div>
@endsection
