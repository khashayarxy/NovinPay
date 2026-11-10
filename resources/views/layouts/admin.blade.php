<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <x-brand-head />
    <title>@yield('title', 'مدیریت') | پنل مدیریت {{ config('business.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-site">
    <a class="skip-link" href="#main-content">پرش به محتوای اصلی</a>
    @php($adminAccount = auth()->check() ? \App\Support\AdminAccess::account(auth()->user()) : null)
    @if($adminAccount)
        <div class="admin-shell">
            <aside class="admin-sidebar" aria-label="منوی مدیریت">
                <a class="admin-brand" href="{{ \App\Support\AdminAccess::landingUrl(auth()->user()) }}"><x-brand-logo /><span><strong>{{ config('business.name') }}</strong><small>پنل مدیریت</small></span></a>
                <details class="admin-navigation" data-admin-navigation open>
                    <summary>فهرست مدیریت <x-home.icon name="settings" /></summary>
                    <x-admin.navigation :account="$adminAccount" />
                </details>
            </aside>
            <div class="admin-workspace">
                <header class="admin-topbar">
                    <div class="admin-topbar-title"><span class="admin-icon"><x-home.icon name="building-bank" /></span><strong>@yield('title', 'مدیریت')</strong></div>
                    <div class="admin-user"><span><strong>{{ auth()->user()->name }}</strong><small>{{ $adminAccount->isSuperAdmin() ? 'مدیر کل' : 'کارمند' }}</small></span><form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit" class="admin-logout">خروج <x-home.icon name="arrow-left" /></button></form></div>
                </header>
                <main id="main-content" class="admin-main" tabindex="-1">
                    @if(session('success'))<div class="admin-notice admin-notice--success" role="status">{{ session('success') }}</div>@endif
                    @yield('content')
                </main>
                <footer class="admin-footer">{{ config('business.name') }}</footer>
            </div>
        </div>
    @else
        <header class="admin-login-header"><a href="{{ route('home') }}" aria-label="وب‌سایت {{ config('business.name') }}"><x-brand-logo /></a><a class="admin-text-link" href="{{ route('home') }}">بازگشت به وب‌سایت ←</a></header>
        <main id="main-content" class="admin-login-main" tabindex="-1">@yield('content')</main>
    @endif
</body>
</html>
