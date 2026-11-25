@php
    $checkUrl = $checkUrl ?? route('payments.connect', $payment, false);
    $resultUrl = $resultUrl ?? route('payments.result', $payment, false);
    $gatewayRouteUrl = $gatewayRouteUrl ?? route('payments.gateway', ['payment' => $payment, 'continue' => 1], false);
    $indeterminateUrl = $indeterminateUrl ?? route('payments.gateway', $payment, false);
@endphp
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="dcterms.source" content="https://db-ip.com">
    <link rel="help" href="{{ route('privacy') }}#payment-country-data">
    <title>بررسی اتصال به درگاه پرداخت | {{ config('business.name') }}</title>
    <x-brand-head />
    <link rel="preload" href="/fonts/dana/DanaFaNum-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/dana/DanaFaNum-Bold.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ versioned_asset('pay/assets/pay.css') }}">
    <script src="{{ versioned_asset('pay/assets/vpn-warning.js') }}" defer></script>
</head>
<body class="t-indigo">
    <main class="payment-warning-shell">
        <div class="payment-warning-brand"><x-home.wordmark /></div>
        <section class="container" aria-labelledby="payment-title">
            <div id="go" data-check-url="{{ $checkUrl }}" data-result-url="{{ $resultUrl }}" data-error-url="{{ route('payments.page', ['page' => 'error'], false) }}"
                data-indeterminate-url="{{ $indeterminateUrl }}" data-gateway-url="{{ $gatewayRouteUrl }}"
                data-verify-split="{{ $verifySplitVpn ? '1' : '0' }}">
                <div class="icon-wrap" aria-hidden="true">
                    <div class="icon">
                        <svg viewBox="0 0 24 24"><path pathLength="20" d="M7 11V8a5 5 0 0 1 10 0v3"/><path pathLength="20" d="M6 11h12v9H6z"/></svg>
                    </div>
                </div>
                <h1 id="payment-title"><span id="goTitle">{{ $verifySplitVpn ? 'در حال بررسی اتصال…' : 'انتقال به درگاه پرداخت' }}</span></h1>
                <p class="lead" id="goLead" aria-live="polite">{{ $verifySplitVpn ? 'لطفاً چند لحظه صبر کنید.' : 'درگاه پرداخت فقط از داخل ایران در دسترس است.' }}</p>
                <div class="bar" aria-hidden="true"></div>
                <p class="hint">اتصال شما با درگاه پرداخت، امن و رمزگذاری‌شده است.</p>
                <div class="info-box" id="vpnBox" role="status" @if($verifySplitVpn) hidden @endif>
                    <p><strong>اتصال از خارج ایران تشخیص داده شد.</strong> وی‌پی‌ان را خاموش کنید و دوباره تلاش کنید.</p>
                </div>
                <div class="info-box go" id="fallback" hidden>
                    <p>اگر خودکار منتقل نشدید، دکمهٔ انتقال به درگاه پرداخت را بزنید.</p>
                </div>
                <x-payment-issue-actions :retry-url="$indeterminateUrl" retry-id="vpnCheck" variant="vpn" />
            </div>
        </section>
    </main>
</body>
</html>
