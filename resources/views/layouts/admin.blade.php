<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @hasSection('title')
        <title>@yield('title') | پنل مدیریت {{ config('app.name') }}</title>
    @else
        <title>پنل مدیریت | {{ config('app.name') }}</title>
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="flex min-h-screen flex-col">
    <a class="skip-link" href="#main-content">پرش به محتوای اصلی</a>

    <header class="border-b border-slate-200 bg-white">
        <div class="container-page flex flex-wrap items-center justify-between gap-x-6 gap-y-3 py-4">
            <div class="flex items-baseline gap-3">
                <a href="{{ route('home') }}" class="flex items-baseline gap-2"
                    aria-label="بازگشت به وب‌سایت {{ config('app.name') }}">
                    <span class="text-lg font-bold tracking-tight text-slate-900">{{ config('app.name') }}</span>
                </a>
                <span class="badge badge-neutral">پنل مدیریت</span>
            </div>

            <nav aria-label="ناوبری مدیریت">
                <ul class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                    @auth
                        <li>
                            <a href="{{ route('admin.products.index') }}"
                                @class([
                                    'transition-colors hover:text-slate-900' => true,
                                    'font-semibold text-brand-700' => request()->routeIs('admin.products.*'),
                                    'text-slate-600' => !request()->routeIs('admin.products.*'),
                                ])
                                @if (request()->routeIs('admin.products.*')) aria-current="page" @endif>
                                محصولات
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('home') }}" class="text-slate-600 transition-colors hover:text-slate-900">
                                مشاهدهٔ وب‌سایت
                            </a>
                        </li>
                        <li>
                            <form method="POST" action="{{ route('admin.logout') }}">
                                @csrf
                                <button type="submit"
                                    class="text-slate-600 transition-colors hover:text-slate-900">
                                    خروج
                                </button>
                            </form>
                        </li>
                    @else
                        <li>
                            <a href="{{ route('admin.login') }}" class="text-slate-600 transition-colors hover:text-slate-900">
                                ورود مدیر
                            </a>
                        </li>
                    @endauth
                </ul>
            </nav>
        </div>
    </header>

    @if (session('success'))
        <div class="container-page mt-6">
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"
                role="status">
                {{ session('success') }}
            </div>
        </div>
    @endif

    <main id="main-content" class="flex-1">
        <div class="container-page py-8">
            @yield('content')
        </div>
    </main>
</body>

</html>
