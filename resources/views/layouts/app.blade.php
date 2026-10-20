<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @hasSection('title')
        <title>@yield('title') | {{ config('app.name') }}</title>
    @else
        <title>{{ config('app.name') }} | فروش و تعمیر دستگاه کارتخوان</title>
    @endif
    <meta name="description" content="@yield('meta_description', 'فروش و تعمیر دستگاه‌های کارتخوان (POS) در شهریار')">
    <x-brand-head />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="flex min-h-screen flex-col">
    <a class="skip-link" href="#main-content">پرش به محتوای اصلی</a>

    @php
        $navItems = [
            ['label' => 'خانه', 'route' => 'home', 'active' => 'home'],
            ['label' => 'محصولات', 'route' => 'products.index', 'active' => 'products.*'],
            ['label' => 'خدمات', 'route' => 'services', 'active' => 'services'],
            ['label' => 'تعمیر دستگاه', 'route' => 'repair', 'active' => 'repair'],
            ['label' => 'تماس', 'route' => 'contact', 'active' => 'contact'],
        ];
    @endphp

    <header class="border-b border-slate-200 bg-white">
        <div class="container-page flex flex-wrap items-center justify-between gap-x-6 gap-y-3 py-4">
            <a href="{{ route('home') }}" class="inline-flex rounded-lg"
                aria-label="صفحهٔ اصلی {{ config('business.name') }}">
                <x-brand-logo />
            </a>

            <nav aria-label="ناوبری اصلی" class="order-last w-full sm:order-none sm:w-auto">
                <ul class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                    @foreach ($navItems as $item)
                        <li>
                            <a href="{{ route($item['route']) }}"
                                @class([
                                    'inline-flex min-h-11 items-center rounded-sm' => true,
                                    'transition-colors hover:text-slate-900' => true,
                                    'font-semibold text-brand-700' => request()->routeIs($item['active']),
                                    'text-slate-600' => !request()->routeIs($item['active']),
                                ])
                                @if (request()->routeIs($item['active'])) aria-current="page" @endif>
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </div>
    </header>

    <main id="main-content" class="flex-1">
        @yield('content')
    </main>

    <footer class="border-t border-slate-800 bg-slate-900 text-slate-200">
        <div class="container-page grid gap-8 py-10 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <a href="{{ route('home') }}" class="inline-flex rounded-lg" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-brand-logo variant="white" /></a>
                <p class="mt-3 text-sm leading-7">
                    فروش و تعمیر دستگاه‌های کارتخوان (POS) برای کسب‌وکارهای شهریار. این وب‌سایت در مرحلهٔ اولیهٔ
                    توسعه است و بخش‌هایی از آن همچنان در حال تکمیل می‌باشد.
                </p>
            </div>

            <nav aria-label="پیوندهای کاربردی">
                <p class="text-sm font-semibold text-white">دسترسی سریع</p>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach (['محصولات' => 'products.index', 'خدمات' => 'services', 'درخواست تعمیر' => 'repair', 'تماس با ما' => 'contact', 'شرایط استفاده' => 'terms', 'حریم خصوصی' => 'privacy'] as $label => $route)
                        <li>
                            <a href="{{ route($route) }}" class="inline-flex min-h-11 items-center rounded-sm hover:underline">{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div>
                <p class="mb-3 text-sm font-semibold text-white">اطلاعات تماس</p>
                <x-business-contact />
                <x-map-links class="mt-3" />
            </div>
        </div>

        <div class="border-t border-slate-800">
            <div class="container-page flex flex-col items-center justify-between gap-2 py-4 text-xs text-slate-300 sm:flex-row">
                <p>© {{ \App\Support\FaNumber::digits(now()->year) }} {{ config('business.name') }}</p>
                <p>نسخهٔ اولیهٔ وب‌سایت — برخی اطلاعات در حال تکمیل است.</p>
            </div>
        </div>
    </footer>
</body>

</html>
