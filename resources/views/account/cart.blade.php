@extends('layouts.app')
@section('title', 'سبد خرید')
@section('compact-footer', '1')
@section('content')
<div class="np-container py-8">
    <section class="public-panel store-cart" data-storefront-cart data-cart-count="{{ array_sum($quantity) }}" data-cart-empty="{{ $items === [] && $excluded === [] && $legacyStatus === null ? 'true' : 'false' }}">
        <h1>سبد خرید</h1>
        <x-admin.errors />
        @if ($legacyStatus === 'claimable')
            <form method="post" action="{{ route('cart.legacy.claim') }}" data-cart-mutation>
                @csrf
                <x-storefront.cart-context :proof="$proof" />
                <p>انتخاب‌های سبد قبلی به همین دستگاه منتقل می‌شوند. اگر کالایی در هر دو سبد باشد، تعداد بیشتر حفظ می‌شود.</p>
                <button type="submit" class="btn btn-secondary">انتقال سبد قبلی به این دستگاه</button>
            </form>
        @elseif ($legacyStatus === 'held')
            <p role="status">انتخاب‌های سبد قبلی محفوظ‌اند. انتقال آن‌ها به همان نشست مرورگر نیاز دارد.</p>
        @endif
        <div class="store-cart-items">
            @forelse ($items as $slug => $item)
                @php($product = $products->get($slug))
                <article @class(['store-cart-item', 'store-cart-unavailable' => !$product])>
                    @if ($product)
                        <a class="store-cart-image" href="{{ route('products.show', $product) }}" aria-label="{{ $product->name }}">
                            <x-product-image :product="$product" />
                        </a>
                    @endif
                    <div>
                        <h2>
                            @if ($product)<a href="{{ route('products.show', $product) }}">{{ $product->name }}</a>
                            @else کالای خارج از دسترس @endif
                        </h2>
                        @if (in_array($slug, $unavailable, true))
                            <p id="cart-unavailable-{{ $loop->index }}">قیمت یا موجودی تغییر کرده است. پیش از ثبت سفارش این کالا را بررسی کنید.</p>
                        @else
                            <p>{{ \App\Support\FaNumber::digits($item['quantity']) }} عدد · {{ \App\Support\FaNumber::digits(number_format($product->price, 0, '.', '٬')) }} تومان</p>
                        @endif
                        @if ($separate)
                            <p class="muted">این دستگاه {{ \App\Support\FaNumber::digits($item['device']['quantity'] ?? 0) }} عدد · این حساب {{ \App\Support\FaNumber::digits($item['account']['quantity'] ?? 0) }} عدد</p>
                        @endif
                        <div class="store-cart-commands">
                            @if ($product && $available[$slug] > 0)
                                <form method="post" action="{{ route('cart.update', $product) }}" data-cart-mutation>
                                    @csrf
                                    <x-storefront.cart-context :proof="$proof" :revision="$revision" />
                                    <label>
                                        @if ($available[$slug] < $item['quantity'])<span class="sr-only">موجودی برای تعداد فعلی کافی نیست.</span>@endif
                                        <span class="sr-only">تعداد {{ $product->name }}</span>
                                        <select class="input" name="quantity">
                                            @for ($number = 1; $number <= $available[$slug]; $number++)
                                                <option value="{{ $number }}" @selected($number === $item['quantity'])>{{ \App\Support\FaNumber::digits($number) }}</option>
                                            @endfor
                                        </select>
                                    </label>
                                    <button type="submit" class="btn btn-secondary">تغییر تعداد</button>
                                </form>
                            @endif
                            <form method="post" action="{{ route('cart.remove', $slug) }}" data-cart-mutation>
                                @csrf
                                <x-storefront.cart-context :proof="$proof" :revision="$revision" />
                                @if ($separate)
                                    <button class="store-cart-remove" name="scope" value="hide">حذف از نمای فعلی</button>
                                @endif
                                @if ($item['device'])
                                    <button class="store-cart-remove" name="scope" value="device">{{ $separate ? 'حذف از سبد این دستگاه' : 'حذف از سبد' }}</button>
                                @endif
                                @if ($item['account'])
                                    <button class="store-cart-remove" name="scope" value="account">حذف از سبد حساب</button>
                                @endif
                            </form>
                        </div>
                    </div>
                </article>
            @empty
                <p>سبد خرید خالی است.</p>
                <a class="btn btn-secondary" href="{{ route('products.index') }}">مشاهدهٔ محصولات</a>
            @endforelse
            @foreach ($excluded as $slug)
                <form method="post" action="{{ route('cart.remove', $slug) }}" data-cart-mutation>
                    @csrf
                    <x-storefront.cart-context :proof="$proof" :revision="$revision" />
                    <p>یک کالا از نمای فعلی پنهان است. <button name="scope" value="restore" class="text-link">بازگرداندن کالا به این نما</button></p>
                </form>
            @endforeach
        </div>
        @if ($items !== [])
            <div class="store-cart-total" data-store-cart-total>
                @if ($overLimit)
                    <p>برای ثبت سفارش، حداکثر ۳۰ کالا در نمای فعلی نگه دارید.</p>
                @elseif ($unavailable === [])
                    <p>جمع کالاها <strong>{{ \App\Support\FaNumber::digits(number_format($total, 0, '.', '٬')) }} تومان</strong></p>
                    <a class="btn btn-primary" href="{{ route('cart.review') }}">تکمیل سفارش</a>
                @else
                    <p>برای تکمیل سفارش، کالاهای خارج از دسترس را بررسی کنید.</p>
                @endif
            </div>
        @endif
    </section>
</div>
@endsection
