@props(['retryUrl', 'retryId' => null, 'referrerPolicy' => null])
<nav class="payment-actions" aria-label="گزینه‌های پرداخت">
    <a class="payment-button payment-button--primary" href="{{ $retryUrl }}" @if($retryId) id="{{ $retryId }}" @endif @if($referrerPolicy) referrerpolicy="{{ $referrerPolicy }}" @endif>تلاش مجدد برای پرداخت</a>
    <a class="payment-button" href="{{ route('cart.index') }}">بازگشت به سبد خرید</a>
</nav>
