@extends('layouts.app')
@section('title', 'سبد خرید')
@section('content')
<div class="np-container py-8">
    <section class="public-panel store-cart" data-storefront-cart data-cart-empty="{{ $products->isEmpty() && $unavailable === [] ? 'true' : 'false' }}">
        <h1>سبد خرید</h1>
        <x-admin.errors />
        @php($total = 0)
        <div class="store-cart-items">
            @foreach ($products as $product)
                @php($quantity = $cart[$product->slug])
                @php($total += (int) $product->price * $quantity)
                <article class="store-cart-item">
                    <a class="store-cart-image" href="{{ route('products.show', $product) }}" aria-label="{{ $product->name }}">
                        <x-product-image :product="$product" />
                    </a>
                    <div>
                        <h2><a href="{{ route('products.show', $product) }}">{{ $product->name }}</a></h2>
                        <p>
                            {{ \App\Support\FaNumber::digits($quantity) }} عدد ·
                            @if (in_array($product->slug, $unpriced, true))
                                استعلام قیمت
                            @else
                                {{ \App\Support\FaNumber::digits(number_format($product->price, 0, '.', '٬')) }} تومان
                            @endif
                        </p>
                        <div class="store-cart-commands">
                            <form method="post" action="{{ route('cart.update', $product) }}" data-cart-mutation>
                                @csrf
                                <label>
                                    <span class="sr-only">تعداد {{ $product->name }}</span>
                                    <select class="input" name="quantity">
                                        @for ($number = 1; $number <= 10; $number++)
                                            <option value="{{ $number }}" @selected($number === $quantity)>{{ \App\Support\FaNumber::digits($number) }}</option>
                                        @endfor
                                    </select>
                                </label>
                                <button type="submit" class="btn btn-secondary">تغییر تعداد</button>
                            </form>
                            <form method="post" action="{{ route('cart.remove', $product->slug) }}" data-cart-mutation>
                                @csrf
                                <button type="submit" class="store-cart-remove" aria-label="حذف {{ $product->name }} از سبد">حذف</button>
                            </form>
                        </div>
                    </div>
                </article>
            @endforeach
            @if ($products->isEmpty() && $unavailable === [])
                <p>سبد خرید خالی است.</p>
                <a class="btn btn-secondary" href="{{ route('products.index') }}">مشاهدهٔ محصولات</a>
            @endif
            @if ($unavailable !== [] || $unpriced !== [])
                <p id="cart-unavailable" role="status">برای ثبت سفارش، کالاهای خارج از دسترس یا بدون قیمت معتبر را حذف کنید.</p>
                @foreach ($unavailable as $slug)
                    <article class="store-cart-item store-cart-unavailable">
                        <h2>این کالا دیگر برای خرید در دسترس نیست.</h2>
                        <form method="post" action="{{ route('cart.remove', $slug) }}" data-cart-mutation>
                            @csrf
                            <button type="submit" class="btn btn-secondary">حذف از سبد</button>
                        </form>
                    </article>
                @endforeach
            @endif
        </div>
        @if ($products->isNotEmpty())
            <div class="store-cart-total" data-store-cart-total>
                @unless (app(\App\Services\ZibalGateway::class)->available())
                    <p class="text-sm">پرداخت آنلاین فعلاً در دسترس نیست. ثبت سفارش به معنی رزرو کالا یا پرداخت نیست.</p>
                @endunless
                @if ($unpriced === [])
                    <p>جمع کالاها <strong>{{ \App\Support\FaNumber::digits(number_format($total, 0, '.', '٬')) }} تومان</strong></p>
                @else
                    <p>جمع قیمت کالاهای این سبد هنوز مشخص نیست.</p>
                @endif
                @if (auth('customer')->check())
                    <form method="post" action="{{ route('cart.checkout') }}">
                        @csrf
                        <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <button type="submit" class="btn btn-primary" @disabled($unavailable !== [] || $unpriced !== []) @if ($unavailable !== [] || $unpriced !== []) aria-describedby="cart-unavailable" @endif>ثبت سفارش</button>
                    </form>
                @else
                    <a class="btn btn-primary" href="{{ route('account.login') }}">ورود و ثبت سفارش</a>
                @endif
            </div>
        @endif
    </section>
</div>
@endsection
