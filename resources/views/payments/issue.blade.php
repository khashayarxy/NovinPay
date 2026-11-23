@extends('payments.layout')
@section('title', 'پیگیری نتیجهٔ پرداخت')
@section('payment')
    <div class="payment-context"><x-storefront.icon name="shield" /><span>پرداخت آنلاین</span></div>
    <h1 id="payment-title">نتیجهٔ پرداخت در دسترس نیست</h1>
    <p>برای ادامه، به سبد خرید برگردید یا دوباره مسیر پرداخت را باز کنید.</p>
    <x-payment-issue-actions :retry-url="$retryUrl" />
@endsection
