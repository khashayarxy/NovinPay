<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @isset($contentSeo)
        <title>{{ $contentSeo['title'] }} | {{ config('app.name') }}</title>
        <meta name="description" content="{{ $contentSeo['description'] }}">
        <link rel="canonical" href="{{ $contentSeo['canonical'] }}">
        <meta name="robots" content="{{ $contentSeo['robots'] }}">
    @else
    @hasSection('title')
        <title>@yield('title') | {{ config('app.name') }}</title>
    @else
        <title>{{ config('app.name') }} | فروش و تعمیر دستگاه کارتخوان</title>
    @endif
    <meta name="description" content="@yield('meta_description', 'فروش و تعمیر دستگاه‌های کارتخوان (POS) در شهریار')">
    @if (request()->routeIs('repair*', 'consultation*', 'terms', 'privacy', 'account.*', 'payments.*', 'delivery.*', 'cart.*'))
        <meta name="robots" content="noindex, nofollow">
    @endif
    @endisset
    <x-brand-head />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if(request()->routeIs('account.*'))
        <x-password-visibility-assets />
    @endif
    @if(request()->routeIs('account.*', 'repair*', 'consultation*', 'cart.*'))
        <script src="{{ asset('js/mobile-digits.js?v=1') }}" defer></script>
    @endif
</head>
<body @class(['public-site flex min-h-screen flex-col', 'homepage' => request()->routeIs('home')])>
    <a class="skip-link" href="#main-content">پرش به محتوای اصلی</a>
    @unless(View::hasSection('quiet-shell'))<x-home.header />@endunless
    <main id="main-content" class="flex-1" tabindex="-1">@yield('content')</main>
    @unless(View::hasSection('quiet-shell'))<x-home.footer />@endunless
@if(config('support_chat.enabled') && !View::hasSection('quiet-shell'))
    @php($chatAccount = auth('customer')->user())
    @if(!$chatAccount || $chatAccount->verified_at)
        <script src="{{ app(\App\Support\Chat\ChatAssets::class)->url('support-widget.js') }}" defer></script>
    @endif
@endif
</body>
</html>
