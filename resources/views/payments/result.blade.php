@php
    $settled = $attempt->state === 'settled';
    $alreadyPaidFlag = $settled && (bool) ($alreadyPaid ?? false);
    $cancelled = (bool) ($isCancelled ?? false);
    $failed = $attempt->state === 'failed';
    $review = $attempt->state === 'review';
    $processing = ! $settled && ! $failed && ! $review;

    $theme = match (true) {
        $settled => 't-green',
        $cancelled => 't-amber',
        $failed || $review => 't-red',
        default => 't-indigo',
    };
    $heading = match (true) {
        $alreadyPaidFlag => 'این سفارش قبلاً پرداخت شده است',
        $settled => 'پرداخت تأیید شد',
        $cancelled => 'انصراف از پرداخت',
        $failed => 'تراکنش ناموفق',
        $review => 'پرداخت نیاز به بررسی دارد',
        default => 'نتیجهٔ پرداخت در انتظار تأیید است',
    };
    $lead = match (true) {
        $alreadyPaidFlag => 'پرداخت این سفارش پیش‌تر تأیید و ثبت شده است.',
        $settled => $attempt->statusMessage(),
        $cancelled => 'شما از ادامهٔ فرآیند پرداخت منصرف شدید.',
        $failed => 'متأسفانه تراکنش شما با مشکل مواجه شد.',
        default => $attempt->statusMessage(),
    };
    $amount = \App\Support\FaNumber::digits(number_format(intdiv((int) $attempt->amount_minor, 100), 0, '.', '٬'));
@endphp
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $heading }} | {{ config('business.name') }}</title>
    <x-brand-head />
    <link rel="preload" href="/fonts/dana/DanaFaNum-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/dana/DanaFaNum-Bold.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ versioned_asset('css/payment-flow.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('pay/assets/pay.css') }}">
</head>
<body class="{{ $theme }}">
    @if ($settled && ! $alreadyPaidFlag)
        <div class="confetti-container" id="confettiContainer" aria-hidden="true"></div>
    @endif
    <main class="payment-warning-shell">
        <div class="payment-warning-brand"><x-home.wordmark /></div>
        <section class="container" aria-labelledby="payment-title">
            <div class="icon-wrap" aria-hidden="true">
                <div @class(['icon', 'live' => $processing])>
                    @if ($settled)
                        <svg viewBox="0 0 24 24"><path pathLength="20" d="M5.5 12.5l4.2 4.2L18.5 7.8"/></svg>
                    @elseif ($cancelled || $failed || $review)
                        <svg viewBox="0 0 24 24"><path pathLength="20" d="M6 6l12 12"/><path pathLength="20" d="M18 6L6 18"/></svg>
                    @else
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path class="hand" d="M12 7v5l3 2"/></svg>
                    @endif
                </div>
            </div>

            <p class="hint" style="margin:0 0 6px;">{{ $isOrder ? 'پرداخت سفارش' : 'پرداخت تعمیر' }}</p>
            <h1 id="payment-title">{{ $heading }}</h1>
            <p class="lead">{{ $lead }}</p>
            @if ($failed && ! $cancelled && $attempt->statusMessage())
                <p class="reason">{{ $attempt->statusMessage() }}</p>
            @endif

            <div class="rows">
                <div class="row">
                    <span>{{ $isOrder ? 'کد سفارش' : 'کد پیگیری تعمیر' }}</span>
                    <span class="pay-copy-wrap">
                        <b class="np-latin" id="payOrderCode">{{ $sourceCode }}</b>
                        @if ($settled)
                            <button type="button" class="pay-copy-btn" data-copy-order-code aria-label="کپی کد">کپی</button>
                        @endif
                    </span>
                </div>
                @if ($attempt->track_id)
                    <div class="row">
                        <span>کد رهگیری زیبال</span>
                        <b>{{ \App\Support\FaNumber::digits((string) $attempt->track_id) }}</b>
                    </div>
                @endif
                <div class="row plain">
                    <span>مبلغ تراکنش</span>
                    <b>{{ $amount }} تومان</b>
                </div>
                @if ($settled && ! empty($paidAtDisplay))
                    <div class="row">
                        <span>تاریخ و ساعت پرداخت</span>
                        <b dir="ltr">{{ $paidAtDisplay }}</b>
                    </div>
                @elseif (! $settled && ! empty($attemptAtDisplay))
                    <div class="row">
                        <span>تاریخ و ساعت تلاش</span>
                        <b dir="ltr">{{ $attemptAtDisplay }}</b>
                    </div>
                @endif
            </div>

            @if ($settled)
                <div class="info-box go">
                    <p>{{ $isOrder
                        ? 'سفارش شما ثبت و تأیید شد؛ مراحل آماده‌سازی و ارسال از طریق پیامک و صفحهٔ پیگیری سفارش اطلاع‌رسانی می‌شود.'
                        : 'پرداخت هزینهٔ تعمیر با موفقیت ثبت شد؛ مراحل بعدی از طریق پیامک و صفحهٔ پیگیری تعمیر اطلاع‌رسانی می‌شود.' }}</p>
                </div>
                <div class="btn-group">
                    <a class="btn" href="{{ $trackingUrl }}">{{ $isOrder ? 'پیگیری سفارش' : 'پیگیری تعمیر' }}</a>
                    <a class="btn btn--secondary" href="{{ route('home') }}">بازگشت به صفحه اصلی</a>
                </div>
            @elseif ($cancelled)
                <div class="info-box go">
                    <p>هیچ مبلغی از حساب شما کسر نشده و سبد خرید شما همچنان محفوظ است؛ هر زمان مایل بودید می‌توانید مجدد اقدام کنید.</p>
                </div>
                <x-payment-issue-actions :retry-url="$retryUrl" :retry-post-url="$retryPostUrl ?? null" variant="vpn" />
                <p class="pay-support-link">نیاز به راهنمایی دارید؟ <a href="{{ route('contact') }}">تماس با پشتیبانی</a></p>
            @elseif ($review)
                <div class="info-box warn">
                    <p><strong>پرداخت مجدد نکنید.</strong> اگر مبلغی از حساب شما کسر شده، کد سفارش و کد پیگیری را به پشتیبانی اعلام کنید تا بررسی شود.</p>
                </div>
                <div class="btn-group">
                    <a class="btn" href="{{ route('contact') }}">تماس با پشتیبانی</a>
                    <a class="btn btn--secondary" href="{{ $trackingUrl }}">{{ $isOrder ? 'پیگیری سفارش' : 'پیگیری تعمیر' }}</a>
                </div>
            @elseif ($failed)
                <div class="info-box">
                    <p>در صورت کسر مبلغ از حساب بانکی شما، تا ۷۲ ساعت آینده به حساب مبدأ بازگردانده خواهد شد.</p>
                </div>
                <x-payment-issue-actions :retry-url="$retryUrl" :retry-post-url="$retryPostUrl ?? null" variant="vpn" />
                <p class="pay-support-link"><a href="{{ route('contact') }}">تماس با پشتیبانی</a></p>
            @else
                <div class="info-box warn">
                    <p><strong>پرداخت مجدد نکنید.</strong> تا مشخص‌شدن نتیجه، چند لحظه صبر کنید یا وضعیت پرداخت را بررسی کنید.</p>
                </div>
                <x-payment-issue-actions :retry-url="$retryUrl" variant="vpn" />
            @endif
        </section>
    </main>

    @if ($settled)
        <script>
            (function () {
                var copyBtn = document.querySelector('[data-copy-order-code]');
                var codeEl = document.getElementById('payOrderCode');
                if (copyBtn && codeEl && navigator.clipboard) {
                    copyBtn.addEventListener('click', function () {
                        navigator.clipboard.writeText(codeEl.textContent.trim()).then(function () {
                            copyBtn.textContent = 'کپی شد';
                            setTimeout(function () { copyBtn.textContent = 'کپی'; }, 1800);
                        });
                    });
                }
                var container = document.getElementById('confettiContainer');
                if (!container || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                var colors = ['#22c55e', '#10b981', '#098cf7', '#f59e0b', '#ec4899', '#8b5cf6'];
                for (var i = 0; i < 80; i++) {
                    var piece = document.createElement('span');
                    piece.className = 'confetti';
                    piece.style.left = (Math.random() * 100) + '%';
                    piece.style.background = colors[i % colors.length];
                    piece.style.width = (6 + Math.random() * 6) + 'px';
                    piece.style.height = (6 + Math.random() * 6) + 'px';
                    piece.style.borderRadius = (i % 2 === 0) ? '50%' : '2px';
                    piece.style.animationDuration = (2.2 + Math.random() * 2.2) + 's';
                    piece.style.animationDelay = (Math.random() * 1.4) + 's';
                    container.appendChild(piece);
                }
                setTimeout(function () { container.innerHTML = ''; }, 6500);
            })();
        </script>
    @endif
</body>
</html>
