@extends('payments.layout')
@section('title', 'انتقال به درگاه زیبال')
@unless($indeterminate)
    @section('navigation')
        <meta http-equiv="refresh" content="0;url={{ $gatewayUrl }}">
    @endsection
@endunless
@section('payment')
    <div class="payment-context"><x-storefront.icon name="shield" /><span>درگاه پرداخت زیبال</span></div>
    @if($indeterminate)
        <h1 id="payment-title">وضعیت وی‌پی‌ان قابل تأیید نیست</h1>
        <p>قواعد مسیریابی یا استثناهای وی‌پی‌ان ممکن است اتصال به نوین‌پی و زیبال را از مسیرهای متفاوتی عبور دهند.</p>
        <p>اگر درگاه باز نشد، موقتاً وی‌پی‌ان را خاموش کنید یا استثناهای زیبال و درگاه پرداخت را بررسی کنید.</p>
    @else
        <h1 id="payment-title">انتقال به درگاه پرداخت</h1>
        <p>اگر درگاه باز نشد، ورود به درگاه را انتخاب کنید.</p>
    @endif
    @if($indeterminate)
        <x-payment-issue-actions :retry-url="$gatewayUrl" referrer-policy="origin" />
    @else
        <nav class="payment-actions" aria-label="گزینه‌های انتقال به درگاه">
            <a class="payment-button payment-button--primary" href="{{ $gatewayUrl }}" referrerpolicy="origin">ورود به درگاه زیبال</a>
        </nav>
    @endif
@endsection
