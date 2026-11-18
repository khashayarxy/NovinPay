@props(['premium' => false])

@php
    request()->attributes->set('customer_header_rendered', true);
    $customerAccount = app(\App\Services\CustomerLogin::class)->authenticatedAccount(request());
    $navigation = [['home', 'home', 'صفحه اصلی'], ['products.index', 'products.*', 'فروشگاه'], ['repair', 'repair*', 'تعمیرات'], ['contact', 'contact', 'تماس با ما'], ['articles.index', 'articles.*', 'مقاله‌ها']];
    $mobileNavigation = [
        ['home', 'home', 'صفحه اصلی', 'home'],
        ['products.index', 'products.*', 'فروشگاه کارتخوان', 'shopping-bag'],
        ['repair', ['repair', 'repair.store', 'repair.confirmation'], 'تعمیرات کارتخوان', 'tool'],
        ['repair.track', 'repair.track*', 'پیگیری تعمیرات', 'search'],
        ['articles.index', 'articles.*', 'مقالات و آموزش‌ها', 'book'],
        ['contact', 'contact', 'تماس با ما', 'phone'],
    ];
@endphp

<header class="np-header" data-mobile-header>
    <div class="np-container np-header__inner">
        <a href="{{ route('home') }}" class="np-logo-link" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-home.wordmark /></a>
        <nav aria-label="ناوبری اصلی" class="np-nav">
            @foreach ($navigation as [$route, $active, $label])
                <a href="{{ route($route) }}" @if (request()->routeIs($active)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="np-header__actions">
            <div class="np-mobile-menu">
                @if ($premium)
                    <button type="button" data-storefront-open="category-dialog" aria-controls="category-dialog" aria-haspopup="dialog" aria-expanded="false" aria-label="فهرست ناوبری"><x-storefront.icon name="menu" width="22" height="22" /></button>
                @else
                <button type="button" data-navigation-toggle aria-label="فهرست ناوبری" aria-controls="mobile-drawer" aria-haspopup="dialog" aria-expanded="false">
                    <svg class="np-menu-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                </button>
                <dialog id="mobile-drawer" class="np-mobile-drawer" aria-label="ناوبری موبایل">
                    <div class="np-mobile-drawer__panel">
                        <div class="np-mobile-drawer__heading">
                            <a href="{{ route('home') }}" class="np-mobile-drawer__logo" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-home.wordmark /></a>
                            <button type="button" data-navigation-close aria-label="بستن فهرست ناوبری" autofocus>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6" /></svg>
                            </button>
                        </div>
                        <nav id="mobile-navigation" aria-label="ناوبری موبایل">
                            @foreach ($mobileNavigation as [$route, $active, $label, $icon])
                                <a href="{{ route($route) }}" @if (request()->routeIs($active)) aria-current="page" @endif><x-home.icon :name="$icon" />{{ $label }}</a>
                            @endforeach
                        </nav>
                    </div>
                </dialog>
                @endif
            </div>
        </div>
        @if ($premium)
            <a class="np-customer-trigger store-mobile-search" href="{{ route('products.index') }}" data-storefront-open="search-dialog" aria-haspopup="dialog" aria-controls="search-dialog" aria-label="جست‌وجوی محصولات"><x-storefront.icon name="search" width="20" height="20" /></a>
            <a class="np-customer-trigger premium-account-trigger" href="{{ route('account.index') }}" data-storefront-open="account-dialog" aria-haspopup="dialog" aria-controls="account-dialog" aria-label="حساب کاربری"><x-home.icon name="user" /></a>
        @endif
        <div class="np-customer-header" data-customer-header data-session-url="{{ route('customer.header') }}">
            @if ($premium)
                <div class="premium-desktop-tools" aria-label="ابزارهای فروشگاه">
                    <a class="np-customer-trigger" href="{{ route('products.index') }}" data-storefront-open="search-dialog" aria-haspopup="dialog" aria-label="جست‌وجوی محصولات"><x-home.icon name="search" /></a>
                    <a class="np-customer-trigger" href="{{ route('cart.index') }}" data-storefront-open="cart-dialog" aria-haspopup="dialog" aria-label="سبد خرید"><x-storefront.icon name="cart" width="18" height="18" /></a>
                </div>
            @endif
            <x-home.customer-entry :account="$customerAccount" />
        </div>
    </div>
</header>
@if ($premium)<x-storefront.panels :account="$customerAccount" />@endif
