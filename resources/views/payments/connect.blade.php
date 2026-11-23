@extends('payments.layout')
@section('title', 'بررسی اتصال به درگاه پرداخت')
@section('head')
    <meta name="dcterms.source" content="https://db-ip.com">
    <link rel="help" href="{{ route('privacy') }}#payment-country-data">
    <script src="{{ versioned_asset('pay/assets/vpn-warning.js') }}" defer></script>
@endsection
@section('payment')
    @php
        $checkUrl = $checkUrl ?? route('payments.connect', $payment, false);
        $resultUrl = $resultUrl ?? route('payments.result', $payment, false);
        $gatewayRouteUrl = $gatewayRouteUrl ?? route('payments.gateway', ['payment' => $payment, 'continue' => 1], false);
        $indeterminateUrl = $indeterminateUrl ?? route('payments.gateway', $payment, false);
    @endphp
    <div class="payment-context"><x-storefront.icon name="shield" /><span>درگاه پرداخت زیبال</span></div>
    <div id="go" data-check-url="{{ $checkUrl }}" data-result-url="{{ $resultUrl }}" data-error-url="{{ route('payments.page', ['page' => 'error'], false) }}"
        data-indeterminate-url="{{ $indeterminateUrl }}" data-gateway-url="{{ $gatewayRouteUrl }}"
        data-verify-split="{{ $verifySplitVpn ? '1' : '0' }}">
        <h1 id="payment-title"><span id="goTitle">{{ $verifySplitVpn ? 'در حال بررسی اتصال…' : 'انتقال به درگاه پرداخت' }}</span></h1>
        <p id="goLead" aria-live="polite">{{ $verifySplitVpn ? 'لطفاً چند لحظه صبر کنید.' : 'درگاه پرداخت فقط از داخل ایران در دسترس است.' }}</p>
        <p class="payment-note" id="vpnBox" role="status" @if($verifySplitVpn) hidden @endif>
            <strong>اتصال از خارج ایران تشخیص داده شد.</strong> وی‌پی‌ان را خاموش کنید و دوباره تلاش کنید.
        </p>
        <p class="payment-note" id="fallback" hidden>اگر خودکار منتقل نشدید، دوباره برای ورود به درگاه تلاش کنید.</p>
        <x-payment-issue-actions :retry-url="$indeterminateUrl" retry-id="vpnCheck" />
    </div>
@endsection
