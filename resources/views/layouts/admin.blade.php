<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <x-brand-head />
    <title>@yield('title', 'مدیریت') | پنل مدیریت {{ config('business.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-password-visibility-assets />
    <script src="{{ versioned_asset('js/mobile-digits.js') }}" defer></script>
</head>
<body class="admin-site">
    <a class="skip-link" href="#main-content">پرش به محتوای اصلی</a>
    @php($adminAccount = auth()->check() ? \App\Support\AdminAccess::account(auth()->user()) : null)
    @if($adminAccount)
        <div class="admin-shell">
            <aside id="admin-sidebar" class="admin-sidebar" aria-label="منوی مدیریت">
                <div class="admin-brand-row">
                    <a class="admin-brand" href="{{ \App\Support\AdminAccess::landingUrl(auth()->user()) }}"><x-brand-logo /><span><strong>{{ config('business.name') }}</strong><small>پنل مدیریت</small></span></a>
                    <a class="admin-brand-site" href="{{ route('home') }}" target="_blank" rel="nofollow noopener noreferrer" aria-label="مشاهده وب‌سایت" title="مشاهده وب‌سایت"><x-home.icon name="world" /></a>
                </div>
                <button type="button" class="admin-mobile-menu-btn" data-admin-mobile-toggle aria-controls="admin-navigation-panel" aria-expanded="false">
                    <svg class="admin-menu-icon admin-menu-icon--open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    <svg class="admin-menu-icon admin-menu-icon--close" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    <span>فهرست مدیریت</span>
                </button>
                <div id="admin-navigation-panel" class="admin-navigation-panel" data-admin-navigation>
                    <x-admin.navigation :account="$adminAccount" />
                </div>
            </aside>
            <div class="admin-workspace">
                <header class="admin-topbar">
                    <div class="admin-topbar-title">
                        <button type="button" class="admin-sidebar-toggle" data-admin-sidebar-toggle aria-controls="admin-sidebar" aria-expanded="true" aria-label="نمایش یا پنهان‌کردن منوی مدیریت" hidden>
                            <span class="admin-sidebar-glyph" aria-hidden="true"></span>
                        </button>
                        <span class="admin-topbar-root">پنل مدیریت {{ config('business.name') }}</span>
                    </div>
                    <div class="admin-user"><span><strong>{{ auth()->user()->name }}</strong><small>{{ $adminAccount->isSuperAdmin() ? 'مدیر کل' : ($adminAccount->isManager() ? 'مدیر' : 'کارمند') }}</small></span><form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit" class="admin-logout">خروج <x-home.icon name="arrow-left" /></button></form></div>
                </header>
                <main id="main-content" class="admin-main" tabindex="-1">
                    @if(session('success'))<div class="admin-notice admin-notice--success" role="status"><x-home.icon name="check" />{{ session('success') }}</div>@endif
                    @yield('content')
                </main>
            </div>
        </div>
    @else
        <header class="admin-login-header"><a href="{{ route('home') }}" aria-label="وب‌سایت {{ config('business.name') }}"><x-brand-logo /></a><a class="admin-text-link" href="{{ route('home') }}">بازگشت به وب‌سایت ←</a></header>
        <main id="main-content" class="admin-login-main" tabindex="-1">@yield('content')</main>
    @endif
</body>
</html>
