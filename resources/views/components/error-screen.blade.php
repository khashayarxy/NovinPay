@props([
    'code' => '۵۰۰',
    'badge' => 'خطای ۵۰۰',
    'tone' => 'info',
    'title' => 'خطایی رخ داد',
    'description' => '',
    'incidentId' => null,
    'primaryLabel' => 'بازگشت به صفحهٔ اصلی',
    'primaryUrl' => '/',
    'secondaryLabel' => null,
    'secondaryUrl' => null,
    'tertiaryLabel' => null,
    'tertiaryUrl' => null,
    'showPhones' => false,
])
@php
    $businessName = (string) config('business.name', 'نوین‌پی');
    $phonePrimary = (string) config('business.phone', '');
    $phoneSecondary = (string) config('business.secondary_phone', '');
    $faDigits = static fn (string $v): string => \App\Support\FaNumber::digits($v);
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }} | {{ $businessName }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <style>
        @font-face {
            font-family: 'Dana';
            src: url('{{ asset('fonts/dana/DanaFaNum-Regular.woff2') }}') format('woff2');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Dana';
            src: url('{{ asset('fonts/dana/DanaFaNum-Medium.woff2') }}') format('woff2');
            font-weight: 500;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Dana';
            src: url('{{ asset('fonts/dana/DanaFaNum-Bold.woff2') }}') format('woff2');
            font-weight: 700;
            font-style: normal;
            font-display: swap;
        }

        :root {
            --err-bg: #f3f8fe;
            --err-orb-1: #dceeff;
            --err-orb-2: #e7f3ff;
            --err-card-bg: #ffffff;
            --err-card-border: #d7e6f5;
            --err-card-shadow: 0 22px 54px rgba(12, 34, 66, 0.08), 0 2px 8px rgba(12, 34, 66, 0.04);
            --err-heading: #0b1f3a;
            --err-text: #47607c;
            --err-muted: #68809b;
            --err-divider: #e4eef8;
            --err-primary-bg: #075da6;
            --err-primary-hover: #064d8b;
            --err-primary-text: #ffffff;
            --err-sec-bg: #f6faff;
            --err-sec-border: #c8def4;
            --err-sec-text: #163d66;
            --err-sec-hover: #eaf4ff;
            --err-code-watermark: rgba(9, 140, 247, 0.08);
            --err-chip-bg: #f1f7fd;
            --err-chip-border: #cfe1f3;
            --err-chip-text: #1e3f63;

            --badge-bg: #eaf5ff;
            --badge-border: #b9dcff;
            --badge-text: #075da6;
            --icon-bg: linear-gradient(145deg, #ebf6ff 0%, #dbeeff 100%);
            --icon-border: #bfdcf9;
            --icon-color: #075da6;
        }

        [data-tone="warning"] {
            --badge-bg: #fffbeb;
            --badge-border: #fde68a;
            --badge-text: #b45309;
            --icon-bg: linear-gradient(145deg, #fffbeb 0%, #fef3c7 100%);
            --icon-border: #fcd34d;
            --icon-color: #d97706;
        }

        [data-tone="danger"] {
            --badge-bg: #fff1f2;
            --badge-border: #fecdd3;
            --badge-text: #be123c;
            --icon-bg: linear-gradient(145deg, #fff1f2 0%, #ffe4e6 100%);
            --icon-border: #fda4af;
            --icon-color: #e11d48;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --err-bg: #0b1526;
                --err-orb-1: rgba(9, 140, 247, 0.14);
                --err-orb-2: rgba(14, 116, 144, 0.12);
                --err-card-bg: #111f36;
                --err-card-border: #1f3556;
                --err-card-shadow: 0 24px 60px rgba(2, 8, 20, 0.55);
                --err-heading: #f1f6fd;
                --err-text: #a8bed8;
                --err-muted: #7f98b5;
                --err-divider: #1d3251;
                --err-primary-bg: #098cf7;
                --err-primary-hover: #32a3ff;
                --err-primary-text: #061325;
                --err-sec-bg: #162844;
                --err-sec-border: #27436b;
                --err-sec-text: #dbeafe;
                --err-sec-hover: #1d3458;
                --err-code-watermark: rgba(56, 189, 248, 0.08);
                --err-chip-bg: #152642;
                --err-chip-border: #274269;
                --err-chip-text: #d8e8fa;

                --badge-bg: rgba(9, 140, 247, 0.16);
                --badge-border: rgba(56, 189, 248, 0.35);
                --badge-text: #7dd3fc;
                --icon-bg: linear-gradient(145deg, #152b4a 0%, #10223b 100%);
                --icon-border: #264875;
                --icon-color: #38bdf8;
            }

            [data-tone="warning"] {
                --badge-bg: rgba(245, 158, 11, 0.16);
                --badge-border: rgba(251, 191, 36, 0.35);
                --badge-text: #fcd34d;
                --icon-bg: linear-gradient(145deg, #2d2312 0%, #231b0e 100%);
                --icon-border: #6b4e16;
                --icon-color: #fbbf24;
            }

            [data-tone="danger"] {
                --badge-bg: rgba(244, 63, 94, 0.16);
                --badge-border: rgba(251, 113, 133, 0.35);
                --badge-text: #fda4af;
                --icon-bg: linear-gradient(145deg, #311522 0%, #24101a 100%);
                --icon-border: #6e243a;
                --icon-color: #fb7185;
            }
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; min-height: 100%; }
        body {
            min-height: 100svh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: clamp(20px, 4vh, 44px) 16px;
            font-family: 'Dana', Tahoma, ui-sans-serif, system-ui, sans-serif;
            background: var(--err-bg);
            color: var(--err-text);
            position: relative;
            overflow-x: hidden;
            line-height: 1.85;
        }

        body::before, body::after {
            content: "";
            position: fixed;
            z-index: 0;
            border-radius: 50%;
            pointer-events: none;
            filter: blur(48px);
        }
        body::before {
            width: 440px;
            height: 440px;
            top: -140px;
            right: -120px;
            background: var(--err-orb-1);
        }
        body::after {
            width: 420px;
            height: 420px;
            bottom: -150px;
            left: -120px;
            background: var(--err-orb-2);
        }

        .err-shell {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 580px;
            background: var(--err-card-bg);
            border: 1px solid var(--err-card-border);
            border-radius: 26px;
            box-shadow: var(--err-card-shadow);
            padding: clamp(24px, 4.5vw, 42px) clamp(20px, 4.5vw, 40px);
            text-align: center;
            overflow: hidden;
        }

        .err-watermark {
            position: absolute;
            top: 12px;
            left: 20px;
            font-size: clamp(68px, 14vw, 108px);
            font-weight: 700;
            line-height: 1;
            color: var(--err-code-watermark);
            pointer-events: none;
            user-select: none;
            font-variant-numeric: tabular-nums;
        }

        .err-brand {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            margin-bottom: 22px;
            position: relative;
            z-index: 1;
        }
        .err-brand img {
            display: block;
            width: clamp(132px, 28vw, 166px);
            height: auto;
        }
        .err-brand__dark { display: none; }
        @media (prefers-color-scheme: dark) {
            .err-brand__light { display: none; }
            .err-brand__dark { display: block; }
        }

        .err-visual {
            position: relative;
            z-index: 1;
            width: 72px;
            height: 72px;
            margin: 0 auto 16px;
            border-radius: 22px;
            background: var(--icon-bg);
            border: 1px solid var(--icon-border);
            color: var(--icon-color);
            display: grid;
            place-items: center;
        }
        .err-visual svg {
            width: 34px;
            height: 34px;
        }

        .err-badge {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 14px;
            border-radius: 999px;
            background: var(--badge-bg);
            border: 1px solid var(--badge-border);
            color: var(--badge-text);
            font-size: 12.5px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        h1 {
            position: relative;
            z-index: 1;
            margin: 0 0 12px;
            color: var(--err-heading);
            font-size: clamp(21px, 3.2vw, 28px);
            font-weight: 700;
            line-height: 1.55;
        }

        .err-desc {
            position: relative;
            z-index: 1;
            margin: 0 auto;
            max-width: 460px;
            font-size: 14.5px;
            color: var(--err-text);
            line-height: 1.95;
        }

        .err-incident {
            position: relative;
            z-index: 1;
            margin: 18px auto 0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 14px;
            border-radius: 11px;
            background: var(--err-chip-bg);
            border: 1px solid var(--err-chip-border);
            color: var(--err-chip-text);
            font-size: 12.5px;
            font-weight: 500;
        }
        .err-incident bdi {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-weight: 700;
            letter-spacing: 0.03em;
        }

        .err-actions {
            position: relative;
            z-index: 1;
            margin-top: 26px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .err-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 46px;
            padding: 10px 20px;
            border-radius: 13px;
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none;
            transition: background-color 150ms ease, border-color 150ms ease, transform 150ms ease;
        }
        .err-btn:focus-visible {
            outline: 2px solid var(--err-primary-bg);
            outline-offset: 3px;
        }
        .err-btn--primary {
            background: var(--err-primary-bg);
            color: var(--err-primary-text);
            border: 1px solid transparent;
        }
        .err-btn--primary:hover {
            background: var(--err-primary-hover);
            transform: translateY(-1px);
        }
        .err-btn--secondary {
            background: var(--err-sec-bg);
            color: var(--err-sec-text);
            border: 1px solid var(--err-sec-border);
        }
        .err-btn--secondary:hover {
            background: var(--err-sec-hover);
            transform: translateY(-1px);
        }

        .err-support {
            position: relative;
            z-index: 1;
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid var(--err-divider);
            font-size: 12.5px;
            color: var(--err-muted);
        }
        .err-phones {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px 12px;
        }
        .err-phones a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 13px;
            border-radius: 10px;
            background: var(--err-sec-bg);
            border: 1px solid var(--err-sec-border);
            color: var(--err-sec-text);
            font-weight: 700;
            font-size: 13px;
            text-decoration: none;
            font-variant-numeric: tabular-nums;
        }
        .err-phones a:hover {
            background: var(--err-sec-hover);
        }

        @media (max-width: 480px) {
            .err-actions {
                flex-direction: column;
                align-items: stretch;
            }
            .err-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body data-tone="{{ $tone }}">
    <main class="err-shell" aria-labelledby="err-heading">
        <span class="err-watermark" aria-hidden="true">{{ $code }}</span>

        <a href="{{ url('/') }}" class="err-brand" aria-label="صفحهٔ اصلی {{ $businessName }}">
            <img class="err-brand__light" src="{{ asset('brand/lockups/logo-wordmark-black-on-white.svg') }}" width="160" height="32" alt="{{ $businessName }}">
            <img class="err-brand__dark" src="{{ asset('brand/lockups/logo-wordmark-white-on-black.svg') }}" width="160" height="32" alt="{{ $businessName }}">
        </a>

        <div class="err-visual" aria-hidden="true">
            {{ $slot }}
        </div>

        <p class="err-badge">{{ $badge }}</p>
        <h1 id="err-heading">{{ $title }}</h1>
        <p class="err-desc">{{ $description }}</p>

        @if (is_string($incidentId) && $incidentId !== '')
            <div class="err-incident">
                <span>کد پیگیری خطا:</span>
                <bdi dir="ltr" data-incident-id>{{ $incidentId }}</bdi>
            </div>
        @endif

        <div class="err-actions">
            <a href="{{ $primaryUrl }}" class="err-btn err-btn--primary">{{ $primaryLabel }}</a>
            @if ($secondaryLabel && $secondaryUrl)
                <a href="{{ $secondaryUrl }}" class="err-btn err-btn--secondary">{{ $secondaryLabel }}</a>
            @endif
            @if ($tertiaryLabel && $tertiaryUrl)
                <a href="{{ $tertiaryUrl }}" class="err-btn err-btn--secondary">{{ $tertiaryLabel }}</a>
            @endif
        </div>

        @if ($showPhones && ($phonePrimary !== '' || $phoneSecondary !== ''))
            <div class="err-support">
                <div>در صورت نیاز به راهنمایی فوری با پشتیبانی {{ $businessName }} تماس بگیرید:</div>
                <div class="err-phones">
                    @foreach (array_filter([$phoneSecondary, $phonePrimary]) as $phone)
                        <a href="tel:{{ $phone }}"><bdi dir="ltr">{{ $faDigits($phone) }}</bdi></a>
                    @endforeach
                </div>
            </div>
        @endif
    </main>
</body>
</html>
