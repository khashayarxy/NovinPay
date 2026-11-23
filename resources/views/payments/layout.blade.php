<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    @yield('navigation')
    <title>@yield('title') | {{ config('business.name') }}</title>
    <x-brand-head />
    <link rel="preload" href="/fonts/dana/DanaFaNum-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/dana/DanaFaNum-Bold.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ versioned_asset('css/payment-flow.css') }}">
</head>
<body>
    <main class="payment-shell">
        <div class="payment-brand"><x-home.wordmark /></div>
        <section class="payment-panel" aria-labelledby="payment-title">
            @yield('payment')
        </section>
    </main>
</body>
</html>
