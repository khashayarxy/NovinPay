@php
    $amountDisplay = \App\Support\FaNumber::digits(number_format((int) $amountToman, 0, '.', '٬'));
@endphp
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>شبیه‌ساز درگاه زیبال (فقط محیط لوکال) | {{ config('business.name') }}</title>
    <x-brand-head />
    <link rel="preload" href="/fonts/dana/DanaFaNum-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/dana/DanaFaNum-Bold.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ versioned_asset('css/payment-flow.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('pay/assets/pay.css') }}">
    <style>
        .sim-btn-paid { background: linear-gradient(135deg, #16a34a, #15803d); box-shadow: 0 12px 24px -10px rgba(22, 163, 74, .55); }
        .sim-btn-cancel { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; box-shadow: none; }
        .sim-btn-cancel:hover { background: #fef3c7; filter: none; }
        .sim-btn-fail { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; box-shadow: none; }
        .sim-btn-fail:hover { background: #fee2e2; filter: none; }
    </style>
</head>
<body class="t-indigo">
    <main class="payment-warning-shell">
        <div class="payment-warning-brand"><x-home.wordmark /></div>
        <section class="container" aria-labelledby="sim-title">
            <div class="icon-wrap" aria-hidden="true">
                <div class="icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                </div>
            </div>

            <h1 id="sim-title">شبیه‌ساز درگاه زیبال — فقط محیط لوکال</h1>
            <p class="subtitle">نتیجهٔ دلخواه تراکنش را برای بررسی صفحات موفقیت، لغو و خطای پرداخت انتخاب کنید.</p>

            <div class="info-box">
                این صفحه صرفاً در محیط توسعهٔ لوکال (<code class="np-latin">APP_ENV=local</code>) فعال است و در محیط تولید وجود ندارد.
            </div>

            <div class="rows">
                <div class="row">
                    <span>کد سفارش / مرجع</span>
                    <strong><bdi class="np-latin" dir="ltr">{{ $sourceCode }}</bdi></strong>
                </div>
                <div class="row">
                    <span>کد رهگیری شبیه‌سازی‌شده</span>
                    <strong><bdi class="np-latin" dir="ltr">{{ $attempt->track_id }}</bdi></strong>
                </div>
                <div class="row plain">
                    <span>مبلغ قابل پرداخت</span>
                    <strong>{{ $amountDisplay }} تومان</strong>
                </div>
            </div>

            <div class="btn-group">
                <form method="post" action="{{ route('payments.local-simulator.complete', $attempt->public_id) }}">
                    @csrf
                    <input type="hidden" name="outcome" value="paid">
                    <button type="submit" class="btn sim-btn-paid">پرداخت موفق</button>
                </form>
                <form method="post" action="{{ route('payments.local-simulator.complete', $attempt->public_id) }}">
                    @csrf
                    <input type="hidden" name="outcome" value="cancelled">
                    <button type="submit" class="btn sim-btn-cancel">انصراف از پرداخت</button>
                </form>
                <form method="post" action="{{ route('payments.local-simulator.complete', $attempt->public_id) }}">
                    @csrf
                    <input type="hidden" name="outcome" value="failed">
                    <button type="submit" class="btn sim-btn-fail">پرداخت ناموفق</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
