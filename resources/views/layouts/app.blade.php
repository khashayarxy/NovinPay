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
            <a href="{{ route('home') }}" class="flex items-baseline gap-2"
                aria-label="صفحهٔ اصلی {{ config('app.name') }}">
                <span class="text-lg font-bold tracking-tight text-slate-900">{{ config('app.name') }}</span>
                <span class="hidden text-xs text-slate-500 sm:inline">فروش و تعمیر دستگاه کارتخوان</span>
            </a>

            <nav aria-label="ناوبری اصلی" class="order-last w-full sm:order-none sm:w-auto">
                <ul class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                    @foreach ($navItems as $item)
                        <li>
                            <a href="{{ route($item['route']) }}"
                                @class([
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

    <footer class="border-t border-slate-200 bg-white">
        <div class="container-page grid gap-8 py-10 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <p class="text-base font-bold text-slate-900">{{ config('app.name') }}</p>
                <p class="mt-3 text-sm leading-7 text-slate-600">
                    فروش و تعمیر دستگاه‌های کارتخوان (POS) برای کسب‌وکارهای شهریار. این وب‌سایت در مرحلهٔ اولیهٔ
                    توسعه است و بخش‌هایی از آن همچنان در حال تکمیل می‌باشد.
                </p>
            </div>

            <nav aria-label="پیوندهای کاربردی">
                <p class="text-sm font-semibold text-slate-900">دسترسی سریع</p>
                <ul class="mt-3 space-y-2 text-sm text-slate-600">
                    @foreach (['محصولات' => 'products.index', 'خدمات' => 'services', 'درخواست تعمیر' => 'repair', 'تماس با ما' => 'contact', 'شرایط استفاده' => 'terms', 'حریم خصوصی' => 'privacy'] as $label => $route)
                        <li>
                            <a href="{{ route($route) }}" class="transition-colors hover:text-brand-700">{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div>
                <p class="text-sm font-semibold text-slate-900">اطلاعات تماس</p>
                <ul class="mt-3 space-y-2 text-sm text-slate-600">
                    <li>نشانی: <span class="text-slate-500">[در حال تکمیل]</span></li>
                    <li>تلفن: <span class="text-slate-500">[در حال تکمیل]</span></li>
                    <li>ساعات کاری: <span class="text-slate-500">[در حال تکمیل]</span></li>
                </ul>
                <p class="mt-3 text-xs leading-6 text-slate-500">
                    اطلاعات تماس رسمی هنوز اعلام نشده است و پیش از انتشار نهایی تکمیل خواهد شد.
                </p>
            </div>
        </div>

        <div class="border-t border-slate-100">
            <div class="container-page flex flex-col items-center justify-between gap-2 py-4 text-xs text-slate-500 sm:flex-row">
                <p>© {{ \App\Support\FaNumber::digits(now()->year) }} {{ config('app.name') }}</p>
                <p>نسخهٔ اولیهٔ وب‌سایت — برخی اطلاعات در حال تکمیل است.</p>
            </div>
        </div>
    </footer>
</body>

</html>
