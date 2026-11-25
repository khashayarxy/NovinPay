@php
    $isSim = (bool) ($isLocalSimulator ?? false);
    $amountDisplay = isset($amountToman)
        ? \App\Support\FaNumber::digits(number_format((int) $amountToman, 0, '.', '٬'))
        : null;
@endphp
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    @unless($indeterminate)
        <meta http-equiv="refresh" content="0;url={{ $gatewayUrl }}">
    @endunless
    <title>{{ $isSim ? 'انتقال به شبیه‌ساز درگاه زیبال' : 'انتقال به درگاه زیبال' }} | {{ config('business.name') }}</title>
    <x-brand-head />
    <link rel="preload" href="/fonts/dana/DanaFaNum-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/dana/DanaFaNum-Bold.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ versioned_asset('css/payment-flow.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('pay/assets/pay.css') }}">
</head>
<body class="t-blue">
    <main class="payment-warning-shell">
        <div class="payment-warning-brand"><x-home.wordmark /></div>
        <section class="container" aria-labelledby="payment-title">
            <div class="icon-wrap" aria-hidden="true">
                <div class="icon">
                    <svg viewBox="0 0 24 24"><path pathLength="20" d="M7 11V8a5 5 0 0 1 10 0v3"/><path pathLength="20" d="M6 11h12v9H6z"/></svg>
                </div>
            </div>

            @if($indeterminate)
                <h1 id="payment-title">وضعیت وی‌پی‌ان قابل تأیید نیست</h1>
                <div class="info-box warn"><p>در صورت باز نشدن درگاه، وی‌پی‌ان را موقتاً خاموش کنید و دوباره تلاش کنید.</p></div>
                <x-payment-issue-actions :retry-url="$gatewayUrl" referrer-policy="origin" variant="vpn" />
            @else
                <h1 id="payment-title">انتقال امن به درگاه پرداخت</h1>

                <div class="bar" aria-hidden="true"></div>

                @if (! empty($sourceCode) || $amountDisplay !== null)
                    <div class="rows">
                        @if (! empty($sourceCode))
                            <div class="row">
                                <span>{{ ($isOrder ?? true) ? 'کد سفارش' : 'کد پیگیری تعمیر' }}</span>
                                <b class="np-latin">{{ $sourceCode }}</b>
                            </div>
                        @endif
                        @if ($amountDisplay !== null)
                            <div class="row plain">
                                <span>مبلغ قابل پرداخت</span>
                                <b>{{ $amountDisplay }} تومان</b>
                            </div>
                        @endif
                    </div>
                @endif
                <div class="btn-group">
                    <a class="btn" href="{{ $gatewayUrl }}" referrerpolicy="origin">{{ $isSim ? 'ورود به شبیه‌ساز درگاه' : 'انتقال به درگاه پرداخت' }}</a>
                    <a class="btn btn--secondary" href="{{ route('cart.index') }}">بازگشت به سبد خرید</a>
                </div>
            @endif
        </section>
    </main>
</body>
</html>
