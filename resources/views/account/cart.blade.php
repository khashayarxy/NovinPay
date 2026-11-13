@extends('layouts.app')
@section('title', 'سبد خرید')
@section('content')
<div class="container py-8"><section class="public-panel"><h1>سبد خرید</h1><x-admin.errors />@php($total = 0)
@forelse($products as $product)@php($quantity = $cart[$product->slug])@php($total += (int)$product->price * $quantity)<article class="mt-6"><h2>{{ $product->name }}</h2><p>{{ \App\Support\FaNumber::digits($quantity) }} عدد · @if(in_array($product->slug, $unpriced, true)) استعلام قیمت @else {{ \App\Support\FaNumber::digits(number_format($product->price)) }} تومان @endif</p><form method="post" action="{{ route('cart.remove',$product->slug) }}">@csrf<button class="btn btn-secondary">حذف از سبد</button></form></article>@empty @if($unavailable === [])<p>سبد خرید خالی است.</p>@endif @endforelse
@if($unavailable !== [] || $unpriced !== [])
    <p id="cart-unavailable" class="mt-6" role="status">برای ثبت سفارش، کالای خارج‌شده از دسترس یا بدون قیمت معتبر را از سبد حذف کنید.</p>
    @foreach($unavailable as $slug)
        <article class="mt-6"><h2>این کالا دیگر برای خرید در دسترس نیست.</h2>
            <form method="post" action="{{ route('cart.remove', $slug) }}">@csrf<button type="submit" class="btn btn-secondary">حذف از سبد</button></form>
        </article>
    @endforeach
@endif
@if($products->isNotEmpty())@if($unpriced === [])<p class="mt-6">جمع کالاها: {{ \App\Support\FaNumber::digits(number_format($total)) }} تومان</p>@else<p class="mt-6">جمع قیمت کالاهای این سبد هنوز مشخص نیست.</p>@endif <p>هنگام ثبت سفارش، قیمت و موجودی دوباره بررسی می‌شوند. بعد از تأیید موجودی و انتخاب روش تحویل، می‌توانید پرداخت کنید.</p>@if(auth('customer')->check())<form method="post" action="{{ route('cart.checkout') }}" class="mt-4">@csrf<input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}"><button type="submit" class="btn btn-primary" @disabled($unavailable !== [] || $unpriced !== []) @if($unavailable !== [] || $unpriced !== []) aria-describedby="cart-unavailable" @endif>ثبت سفارش</button></form>@else<a class="btn btn-primary mt-4" href="{{ route('account.login') }}">ورود و ثبت سفارش</a>@endif @endif
</section></div>
@endsection
