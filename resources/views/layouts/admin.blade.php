<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <x-brand-head />
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
            <div class="flex items-center gap-6">
                <a href="{{ route('home') }}" class="inline-flex rounded-lg"
                    aria-label="بازگشت به وب‌سایت {{ config('business.name') }}">
                    <x-brand-logo />
                </a>
                <span class="badge badge-neutral">پنل مدیریت</span>
            </div>

            <nav aria-label="ناوبری مدیریت">
                <ul class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                    @auth
                        @can('access-admin')
                        <li>
                            <a href="{{ route('admin.products.index') }}"
                                @class([
                                    'inline-flex min-h-11 items-center rounded-sm' => true,
                                    'transition-colors hover:text-slate-900' => true,
                                    'font-semibold text-brand-700' => request()->routeIs('admin.products.*'),
                                    'text-slate-600' => !request()->routeIs('admin.products.*'),
                                ])
                                @if (request()->routeIs('admin.products.*')) aria-current="page" @endif>
                                محصولات
                            </a>
                        </li>
                        @endcan
                        <li>
                            <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded-sm text-slate-600 transition-colors hover:text-slate-900">
                                مشاهدهٔ وب‌سایت
                            </a>
                        </li>
                        <li>
                            <form method="POST" action="{{ route('admin.logout') }}">
                                @csrf
                                <button type="submit"
                                    class="inline-flex min-h-11 items-center rounded-sm text-slate-600 transition-colors hover:text-slate-900">
                                    خروج
                                </button>
                            </form>
                        </li>
                    @else
                        <li>
                            <a href="{{ route('admin.login') }}" class="inline-flex min-h-11 items-center rounded-sm text-slate-600 transition-colors hover:text-slate-900">
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
