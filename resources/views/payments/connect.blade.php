<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="dcterms.source" content="https://db-ip.com">
    <link rel="help" href="{{ route('privacy') }}#payment-country-data">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="preload" href="/fonts/dana/DanaFaNum-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/dana/DanaFaNum-Bold.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ versioned_asset('pay/assets/vpn-warning.css') }}">
    <title>وی‌پی‌ان را خاموش کنید | نوین‌پی</title>
    <script src="{{ versioned_asset('pay/assets/vpn-warning.js') }}" defer></script>
</head>
<body class="t-indigo">
    @php
        $checkUrl = $checkUrl ?? route('payments.connect', $payment, false);
        $resultUrl = $resultUrl ?? route('payments.result', $payment, false);
        $gatewayRouteUrl = $gatewayRouteUrl ?? route('payments.gateway', ['payment' => $payment, 'continue' => 1], false);
        $indeterminateUrl = $indeterminateUrl ?? route('payments.gateway', $payment, false);
        $verifySplitVpn = (bool) ($verifySplitVpn ?? false);
    @endphp
    <main class="payment-warning-shell">
        <div class="payment-warning-brand"><x-home.wordmark /></div>
        <div class="container" id="go" data-check-url="{{ $checkUrl }}"
            data-result-url="{{ $resultUrl }}"
            data-indeterminate-url="{{ $indeterminateUrl }}"
            data-verify-split="{{ $verifySplitVpn ? '1' : '0' }}">
            <div class="icon-wrap">
                <div class="icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path pathLength="20" d="M7 11V8a5 5 0 0 1 10 0v3"/><path pathLength="20" d="M6 11h12v9H6z"/></svg>
                </div>
            </div>
            <h2 id="goTitle">{{ $verifySplitVpn ? 'در حال بررسی اتصال...' : 'انتقال به درگاه پرداخت' }}</h2>
            <p class="lead" id="goLead">{{ $verifySplitVpn ? 'لطفاً چند لحظه صبر کنید.' : 'درگاه پرداخت فقط از داخل ایران در دسترس است.' }}</p>
            <div class="bar" id="goBar" @unless($verifySplitVpn) hidden @endunless aria-hidden="true"></div>
            <p class="hint" id="goHint" @unless($verifySplitVpn) hidden @endunless>اتصال شما با درگاه پرداخت، امن و رمزگذاری‌شده است.</p>

            <div class="info-box late" id="vpnBox" role="alert" @if($verifySplitVpn) hidden @endif>
                <p><strong>اتصال از خارج ایران تشخیص داده شد.</strong></p>
                <p><span class="ph">برای ورود به درگاه،</span> <span class="ph">وی‌پی‌ان را خاموش کنید.</span></p>
            </div>
            <button class="btn late" type="button" id="vpnCheck" @if($verifySplitVpn) hidden @endif>بررسی دوباره اتصال</button>

            <div class="info-box go late" id="fallback" hidden>
                <p><span class="ph">اگر به‌صورت خودکار</span> <span class="ph">منتقل نشدید،</span> <span class="ph">این دکمه را بزنید.</span></p>
                <a class="btn" id="fallbackLink" href="{{ $gatewayRouteUrl }}">انتقال به درگاه پرداخت</a>
            </div>
        </div>

        <div class="container t-rose" id="bad" hidden>
            <div class="icon-wrap">
                <div class="icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path pathLength="20" d="M6 6l12 12"/><path pathLength="20" d="M18 6L6 18"/></svg>
                </div>
            </div>
            <h2>لینک پرداخت نامعتبر است</h2>
            <p class="lead">امکان انتقال به درگاه با این لینک وجود ندارد.</p>
            <div class="info-box go">
                <p><span class="ph">با لینک پرداخت جدید در سایت،</span> <span class="ph">می‌تونید مجدد برای پرداخت اقدام کنید.</span></p>
            </div>
        </div>
    </main>
    <noscript>
        <div class="container t-amber">
            <h2>بررسی اتصال در دسترس نیست</h2>
            <p class="lead">برای دیدن راهنمای اتصال و ادامهٔ پرداخت، وضعیت اتصال را باز کنید.</p>
            <a class="btn" href="{{ $indeterminateUrl }}">ادامهٔ پرداخت</a>
        </div>
        <style>.payment-warning-shell { display: none; }</style>
    </noscript>
</body>
</html>
