@php
    request()->attributes->set('customer_header_rendered', true);
    $customerAccount = app(\App\Services\CustomerLogin::class)->authenticatedAccount(request());
    $navigation = [['home', 'home', 'صفحه اصلی'], ['products.index', 'products.*', 'فروشگاه'], ['repair', 'repair*', 'تعمیرات'], ['contact', 'contact', 'تماس با ما'], ['articles.index', 'articles.*', 'مقاله‌ها']];
    $mobileNavigation = [['account.index', 'account.*', 'حساب کاربری'], ['repair.track', 'repair.track*', 'پیگیری تعمیرات'], ['articles.index', 'articles.*', 'مقالات'], $navigation[3]];
    $quickNavigation = match (true) {
        request()->routeIs('products.*') => [$navigation[0], $navigation[2]],
        request()->routeIs('repair', 'repair.*') && ! request()->routeIs('repair.track*') => [$navigation[0], $navigation[1]],
        default => [$navigation[1], $navigation[2]],
    };
@endphp

<header class="np-header" data-mobile-header>
    <div class="np-container np-header__inner">
        <a href="{{ route('home') }}" class="np-logo-link" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-home.wordmark /></a>
        <nav aria-label="ناوبری اصلی" class="np-nav">
            @foreach ($navigation as [$route, $active, $label])
                <a href="{{ route($route) }}" @if (request()->routeIs($active)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            <a href="{{ route('account.index') }}" @if (request()->routeIs('account.*')) aria-current="page" @endif>حساب کاربری</a>
        </nav>
        <div class="np-header__actions">
            <details class="np-mobile-menu">
                <summary aria-label="فهرست ناوبری" aria-controls="mobile-navigation">
                    <svg class="np-menu-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                    <svg class="np-menu-close" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6" /></svg>
                </summary>
                <nav id="mobile-navigation" aria-label="ناوبری موبایل">
                    @foreach ($mobileNavigation as [$route, $active, $label])
                        <a href="{{ route($route) }}" @if (request()->routeIs($active)) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>
            </details>
        </div>
        <div class="np-customer-header" data-customer-header data-session-url="{{ route('customer.header') }}">
            <x-home.customer-entry :account="$customerAccount" />
        </div>
    </div>
    <nav class="np-quick-nav np-container" aria-label="دسترسی سریع">
        @foreach ($quickNavigation as [$route, $active, $label])
            <a href="{{ route($route) }}">{{ $label }}</a>
        @endforeach
    </nav>
</header>
