@extends('payments.layout')
@section('title', 'انتقال به درگاه زیبال')
@section('navigation')
    <meta http-equiv="refresh" content="0;url={{ $gatewayUrl }}">
@endsection
@section('payment')
    <div class="payment-context"><x-storefront.icon name="shield" /><span>درگاه پرداخت زیبال</span></div>
    <h1 id="payment-title">انتقال به درگاه پرداخت</h1>
    <p>اگر درگاه باز نشد، ورود به درگاه را انتخاب کنید.</p>
    <nav class="payment-actions" aria-label="گزینه‌های انتقال به درگاه">
        <a class="payment-button payment-button--primary" href="{{ $gatewayUrl }}" referrerpolicy="origin">ورود به درگاه</a>
        <a class="payment-button" href="{{ route('payments.result', $payment) }}">بازگشت به وضعیت پرداخت</a>
    </nav>
@endsection
