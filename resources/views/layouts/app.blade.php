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
    @if (request()->routeIs('repair*', 'consultation*', 'terms', 'privacy'))
        <meta name="robots" content="noindex, nofollow">
    @endif
    @endisset
    <x-brand-head />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @class(['public-site flex min-h-screen flex-col', 'homepage' => request()->routeIs('home')])>
    <a class="skip-link" href="#main-content">پرش به محتوای اصلی</a>
    <x-home.header />
    <main id="main-content" class="flex-1" tabindex="-1">@yield('content')</main>
    <x-home.footer />
</body>
</html>
