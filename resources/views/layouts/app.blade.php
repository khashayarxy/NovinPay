<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    @php($hasStoreNavigation = request()->routeIs('home', 'products.*', 'cart.*', 'repair*', 'account.*', 'articles.*', 'contact', 'consultation*', 'terms', 'privacy'))
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1{{ $hasStoreNavigation ? ', viewport-fit=cover' : '' }}">
    @php($stagingRobots = request()->getHost() === 'novinpaypos.test' ? 'noindex, nofollow' : null)
    @isset($contentSeo)
        <title>{{ $contentSeo['title'] }} | {{ config('app.name') }}</title>
        <meta name="description" content="{{ $contentSeo['description'] }}">
        <link rel="canonical" href="{{ $contentSeo['canonical'] }}">
        <meta name="robots" content="{{ $stagingRobots ?? $contentSeo['robots'] }}">
    @else
    @isset($canonical)<link rel="canonical" href="{{ $canonical }}">@endisset
    @hasSection('title')
        <title>@yield('title') | {{ request()->routeIs('products.show') ? config('business.name') : config('app.name') }}</title>
    @else
        <title>{{ config('app.name') }} | فروش و تعمیر دستگاه کارتخوان</title>
    @endif
    <meta name="description" content="@yield('meta_description', 'فروش و تعمیر دستگاه‌های کارتخوان (POS) در شهریار')">
    @if ($stagingRobots || isset($publicRobots))
        <meta name="robots" content="{{ $stagingRobots ?? $publicRobots }}">
    @elseif (request()->routeIs('repair*', 'consultation*', 'terms', 'privacy', 'account.*', 'payments.*', 'delivery.*', 'cart.*'))
        <meta name="robots" content="noindex, nofollow">
    @endif
    @endisset
    <x-public-seo :canonical="$canonical ?? null" :content-seo="$contentSeo ?? null" :public-robots="$publicRobots ?? null" :product="$product ?? null" :product-sale="$sale ?? null" :product-rating="$productRating ?? null" :article="$article ?? null" :version="$version ?? null" />
    <x-brand-head />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if ($hasStoreNavigation && !View::hasSection('quiet-shell'))
        <script src="{{ versioned_asset('js/storefront-scroll-lock.js') }}" defer></script>
        <script src="{{ versioned_asset('js/storefront-premium.js') }}" defer></script>
    @endif
    @stack('page-head')
    @if ($hasStoreNavigation)
        <link rel="stylesheet" href="{{ versioned_asset('css/store-navigation.css') }}">
    @endif
    @if ($hasStoreNavigation && !View::hasSection('quiet-shell'))
        <link rel="stylesheet" href="{{ versioned_asset('css/storefront-refinement.css') }}">
    @endif
    @if(request()->routeIs('account.*'))
        <x-password-visibility-assets />
    @endif
    @if(request()->routeIs('account.*', 'repair*', 'consultation*', 'cart.*'))
        <script src="{{ versioned_asset('js/mobile-digits.js') }}" defer></script>
    @endif
</head>
<body @class(['public-site flex min-h-screen flex-col', 'homepage' => request()->routeIs('home'), 'premium-storefront' => View::hasSection('premium-storefront')]) @if(View::hasSection('premium-storefront')) data-navigation="a" @endif>
    <a class="skip-link" href="#main-content">پرش به محتوای اصلی</a>
    @unless(View::hasSection('quiet-shell'))
        <x-home.header :premium="View::hasSection('premium-storefront')" />
    @endunless
    <main id="main-content" class="flex-1" tabindex="-1">@yield('content')</main>
    @unless(View::hasSection('quiet-shell'))
        @if(request()->routeIs('home', 'contact', 'repair*'))
            <x-home.location />
        @endif
        <x-home.footer :compact="View::hasSection('compact-footer')" />
    @endunless
    @if ($hasStoreNavigation && !View::hasSection('quiet-shell'))
        <x-storefront.mobile-navigation />
        <x-storefront.shop-dialogs />
    @endif
@if(config('support_chat.enabled') && !View::hasSection('quiet-shell'))
    @php($chatAccount = auth('customer')->user())
    @if(!$chatAccount || $chatAccount->verified_at)
        <script src="{{ app(\App\Support\Chat\ChatAssets::class)->url('support-widget.js') }}" defer></script>
    @endif
@endif
</body>
</html>
