@extends('layouts.app')
@section('title', 'سبد خرید')
@section('content')
<div class="container py-8"><section class="public-panel"><h1>سبد خرید</h1><x-admin.errors />@php($total = 0)
@forelse($products as $product)@php($quantity = $cart[$product->slug])@php($total += (int)$product->price * $quantity)<article class="mt-6"><h2>{{ $product->name }}</h2><p>{{ \App\Support\FaNumber::digits($quantity) }} عدد · {{ \App\Support\FaNumber::digits(number_format($product->price)) }} تومان</p><form method="post" action="{{ route('cart.remove',$product->slug) }}">@csrf<button class="btn btn-secondary">حذف از سبد</button></form></article>@empty<p>سبد خرید خالی است.</p>@endforelse
@if($products->isNotEmpty())<p class="mt-6">جمع کالاها: {{ \App\Support\FaNumber::digits(number_format($total)) }} تومان</p><p>قیمت و موجودی هنگام ثبت دوباره بررسی می‌شوند. سفارش ابتدا برای تأیید موجودی ثبت می‌شود؛ دریافت وجه پس از تأیید و انتخاب روش تحویل است.</p>@if(auth('customer')->check())<form method="post" action="{{ route('cart.checkout') }}" class="mt-4">@csrf<input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}"><button class="btn btn-primary">ثبت سفارش</button></form>@else<a class="btn btn-primary mt-4" href="{{ route('account.login') }}">ورود و ثبت سفارش</a>@endif @endif
</section></div>
@endsection
