@props(['retryUrl', 'retryPostUrl' => null, 'retryId' => null, 'referrerPolicy' => null, 'variant' => 'flow'])
@php
    $isVpn = $variant === 'vpn';
    $navClass = $isVpn ? 'btn-group' : 'payment-actions';
    $primaryClass = $isVpn ? 'btn' : 'payment-button payment-button--primary';
    $secondaryClass = $isVpn ? 'btn btn--secondary' : 'payment-button';
@endphp
<nav class="{{ $navClass }}" aria-label="گزینه‌های پرداخت">
    @if ($retryPostUrl)
        <form method="post" action="{{ $retryPostUrl }}">
            @csrf
            <button type="submit" class="{{ $primaryClass }}" @if($retryId) id="{{ $retryId }}" @endif>تلاش مجدد برای پرداخت</button>
        </form>
    @else
        <a class="{{ $primaryClass }}" href="{{ $retryUrl }}" @if($retryId) id="{{ $retryId }}" @endif @if($referrerPolicy) referrerpolicy="{{ $referrerPolicy }}" @endif>تلاش مجدد برای پرداخت</a>
    @endif
    <a class="{{ $secondaryClass }}" href="{{ route('cart.index') }}">بازگشت به سبد خرید</a>
</nav>
