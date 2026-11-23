@php
    $isOrder = $isOrder ?? (bool) $document->sales_order_id;
    $sourceCode = $sourceCode ?? strtoupper(substr($document->public_id, 0, 8));
    $retryUrl = $retryUrl ?? $backUrl;
    $continueUrl = $continueUrl ?? ($canContinue ? route('payments.connect', $attempt->public_id) : null);
    $themeClass = match ($attempt->state) {
        'settled' => 't-green',
        'failed' => 't-red',
        'review' => 't-amber',
        default => 't-indigo',
    };
    $headingText = match ($attempt->state) {
        'settled' => 'پرداخت تأیید شد',
        'failed' => 'پرداخت انجام نشد',
        'review' => 'پرداخت نیاز به بررسی دارد',
        default => 'نتیجهٔ پرداخت در انتظار تأیید است',
    };
    $tomanInteger = \App\Support\FaNumber::digits(number_format(intdiv((int) $attempt->amount_minor, 100), 0, '.', '٬'));
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $headingText }} | {{ config('business.name') }}</title>
    <x-brand-head />
    <link rel="preload" href="/fonts/dana/DanaFaNum-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ versioned_asset('pay/assets/vpn-warning.css') }}">
</head>
<body class="{{ $themeClass }}">
    <main class="payment-warning-shell">
        <div class="payment-warning-brand"><x-home.wordmark /></div>
        <div class="container" aria-labelledby="payment-title">
            <div class="icon-wrap">
                @if ($attempt->state === 'settled')
                    <div class="icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path pathLength="20" d="M5.5 12.5l4.2 4.2L18.5 7.8"/></svg>
                    </div>
                @elseif ($attempt->state === 'failed')
                    <div class="icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path pathLength="20" d="M6 6l12 12"/><path pathLength="20" d="M18 6L6 18"/></svg>
                    </div>
                @else
                    <div class="icon live">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path class="hand" d="M12 12V7"/></svg>
                    </div>
                @endif
            </div>

            <h2 id="payment-title">{{ $headingText }}</h2>
            <p class="lead">{{ $attempt->statusMessage() }}</p>

            <div class="rows">
                <div class="row plain">
                    <span>نوع تراکنش</span>
                    <b>{{ $isOrder ? 'پرداخت سفارش' : 'پرداخت تعمیر' }}</b>
                </div>
                <div class="row">
                    <span>{{ $isOrder ? 'کد سفارش' : 'کد پیگیری تعمیر' }}</span>
                    <b><bdi dir="ltr">{{ \App\Support\FaNumber::digits((string) $sourceCode) }}</bdi></b>
                </div>
                @if ($attempt->track_id)
                    <div class="row">
                        <span>کد رهگیری زیبال</span>
                        <b><bdi dir="ltr">{{ \App\Support\FaNumber::digits((string) $attempt->track_id) }}</bdi></b>
                    </div>
                @endif
                <div class="row plain">
                    <span>مبلغ تراکنش</span>
                    <b style="font-variant-numeric: tabular-nums;">{{ $tomanInteger }} تومان</b>
                </div>
            </div>

            @if ($attempt->state === 'settled')
                <div class="info-box go">
                    <p><span class="ph">تراکنش شما با موفقیت ثبت شد</span> <span class="ph">و از طریق حساب کاربری قابل پیگیری است.</span></p>
                </div>
            @elseif ($attempt->state === 'failed')
                <div class="info-box">
                    <p><span class="ln">در صورت کسر مبلغ از حساب بانکی شما،</span> <span class="ln">حداکثر تا ۷۲ ساعت آینده به حساب مبدا بازگردانده خواهد شد.</span></p>
                </div>
            @elseif ($attempt->state === 'review')
                <div class="info-box warn">
                    <p><strong>دوباره پرداخت نکنید.</strong></p>
                    <p>در صورت کسر وجه، کد رهگیری زیبال را نزد خود نگه دارید تا نتیجه نهایی مشخص شود.</p>
                </div>
            @endif

            <nav class="btn-group" aria-label="گزینه‌های پیگیری پرداخت">
                @if ($attempt->state === 'failed')
                    <a class="btn" href="{{ $retryUrl }}">تلاش مجدد برای پرداخت</a>
                    @if (! empty($accountUrl))
                        <a class="btn btn--secondary" href="{{ $accountUrl }}">حساب کاربری</a>
                    @endif
                @else
                    <a class="btn" href="{{ $backUrl }}">مشاهدهٔ سند و رسیدها</a>
                    @if (! empty($accountUrl))
                        <a class="btn btn--secondary" href="{{ $accountUrl }}">حساب کاربری</a>
                    @endif
                    @if ($canContinue && $continueUrl)
                        <a class="btn btn--secondary" href="{{ $continueUrl }}">ادامه به درگاه</a>
                    @endif
                    @if (! in_array($attempt->state, ['settled', 'failed'], true))
                        <a class="btn btn--secondary" href="{{ request()->fullUrl() }}">به‌روزرسانی وضعیت</a>
                    @endif
                @endif
            </nav>
        </div>
    </main>
</body>
</html>
