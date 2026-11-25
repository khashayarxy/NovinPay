@props(['premium' => false])

@php
    $shopTools = request()->routeIs('home', 'products.*', 'cart.*', 'repair*', 'account.*', 'articles.*', 'contact', 'consultation*', 'terms', 'privacy');
    request()->attributes->set('customer_header_rendered', true);
    $customerAccount = app(\App\Services\CustomerLogin::class)->authenticatedAccount(request());
    $activeCategory = request()->routeIs('products.index') ? request()->query('category') : null;
    $shopCategories = \App\Enums\ProductCategory::storefrontCases();
    $navigation = [['home', 'home', 'صفحه اصلی'], ['products.index', 'products.*', 'فروشگاه'], ['repair', 'repair*', 'تعمیرات'], ['contact', 'contact', 'تماس با ما'], ['articles.index', 'articles.*', 'مقاله‌ها']];
@endphp

<header class="np-header" data-mobile-header>
    <div class="np-container np-header__inner">
        <a href="{{ route('home') }}" class="np-logo-link" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-home.wordmark /></a>
        <nav aria-label="ناوبری اصلی" class="np-nav">
            @foreach ($navigation as [$route, $active, $label])
                @if ($route === 'products.index')
                    <div class="np-nav__item np-nav__item--dropdown" data-desktop-shop-dropdown>
                        <a href="{{ route($route) }}" class="np-nav__trigger" @if (request()->routeIs($active)) aria-current="page" @endif>
                            <span>{{ $label }}</span>
                            <x-storefront.icon name="chevron-down" class="np-nav__chevron" />
                        </a>
                        <div class="np-nav__dropdown" role="region" aria-label="دسته‌بندی‌های فروشگاه">
                            <div class="np-nav__dropdown-list">
                                @foreach ($shopCategories as $category)
                                    <a href="{{ route('products.index', ['category' => $category->value]) }}" @if ($activeCategory === $category->value) aria-current="page" @endif>
                                        <span>{{ $category->label() }}</span>
                                        <x-storefront.icon name="arrow" class="np-nav__dropdown-arrow" />
                                    </a>
                                @endforeach
                            </div>
                            <div class="np-nav__dropdown-footer">
                                <a href="{{ route('products.index') }}" class="np-nav__dropdown-all" @if (request()->routeIs('products.*') && ! $activeCategory) aria-current="page" @endif>
                                    <span>مشاهده همه محصولات</span>
                                    <x-storefront.icon name="arrow" class="np-nav__dropdown-arrow" />
                                </a>
                            </div>
                        </div>
                    </div>
                @elseif ($route === 'repair')
                    <div class="np-nav__item np-nav__item--dropdown" data-desktop-repair-dropdown>
                        <a href="{{ route($route) }}" class="np-nav__trigger" @if (request()->routeIs($active)) aria-current="page" @endif>
                            <span>{{ $label }}</span>
                            <x-storefront.icon name="chevron-down" class="np-nav__chevron" />
                        </a>
                        <div class="np-nav__dropdown np-nav__dropdown--compact" role="region" aria-label="خدمات تعمیرات">
                            <div class="np-nav__dropdown-list">
                                <a href="{{ route('repair') }}" @if (request()->routeIs('repair', 'repair.store', 'repair.confirmation')) aria-current="page" @endif>
                                    <span>ثبت درخواست تعمیر</span>
                                    <x-storefront.icon name="arrow" class="np-nav__dropdown-arrow" />
                                </a>
                                <a href="{{ route('repair.track') }}" @if (request()->routeIs('repair.track*')) aria-current="page" @endif>
                                    <span>پیگیری تعمیر</span>
                                    <x-storefront.icon name="arrow" class="np-nav__dropdown-arrow" />
                                </a>
                            </div>
                        </div>
                    </div>
                @else
                    <a href="{{ route($route) }}" @if (request()->routeIs($active)) aria-current="page" @endif>{{ $label }}</a>
                @endif
            @endforeach
        </nav>
        <div class="np-header__actions">
            <div class="np-mobile-menu">
                <button type="button" data-navigation-toggle aria-label="فهرست ناوبری" aria-controls="mobile-drawer" aria-haspopup="dialog" aria-expanded="false">
                    <svg class="np-menu-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                </button>
            </div>
        </div>
        @if ($shopTools)
            <a class="np-customer-trigger store-mobile-search" href="{{ route('products.index') }}" data-storefront-open="search-dialog" aria-haspopup="dialog" aria-controls="search-dialog" aria-label="جستجوی محصولات"><x-storefront.icon name="search" width="20" height="20" /></a>
        @endif
        <div class="np-customer-header" data-customer-header data-session-url="{{ route('customer.header') }}">
            @if ($shopTools)
                <div class="premium-desktop-tools store-desktop-tools" aria-label="ابزارهای فروشگاه">
                    <a class="np-customer-trigger" href="{{ route('products.index') }}" data-storefront-open="search-dialog" aria-haspopup="dialog" aria-label="جستجوی محصولات"><x-storefront.icon name="search" /></a>
                    <a class="np-customer-trigger" href="{{ route('cart.index') }}" data-storefront-open="cart-dialog" aria-haspopup="dialog" aria-label="سبد خرید"><x-storefront.icon name="cart" /><x-storefront.cart-count /></a>
                </div>
            @endif
            <x-home.customer-entry :account="$customerAccount" />
        </div>
    </div>
</header>
<dialog id="mobile-drawer" class="np-mobile-drawer" aria-label="ناوبری موبایل">
    <div class="np-mobile-drawer__panel">
        <div class="np-mobile-drawer__heading">
            <button type="button" data-navigation-close aria-label="بستن فهرست ناوبری" autofocus>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6" /></svg>
            </button>
        </div>
        <nav id="mobile-navigation" aria-label="ناوبری موبایل">
            <a href="{{ route('home') }}" @if (request()->routeIs('home')) aria-current="page" @endif><x-storefront.icon name="home" class="np-nav-icon" /><span>صفحه اصلی</span></a>
            <section class="np-drawer-categories">
                <h3><x-storefront.icon name="shop" class="np-nav-icon" /><span>فروشگاه</span></h3>
                <div class="np-drawer-categories__list">
                    <a href="{{ route('products.index') }}" @if (request()->routeIs('products.*') && ! $activeCategory) aria-current="page" @endif><span>همه محصولات</span><x-storefront.icon name="arrow" class="np-drawer-arrow" /></a>
                    @foreach (\App\Enums\ProductCategory::storefrontCases() as $category)
                        <a href="{{ route('products.index', ['category' => $category->value]) }}" @if ($activeCategory === $category->value) aria-current="page" @endif><span>{{ $category->label() }}</span><x-storefront.icon name="arrow" class="np-drawer-arrow" /></a>
                    @endforeach
                </div>
            </section>
            <a href="{{ route('repair') }}" @if (request()->routeIs('repair', 'repair.store', 'repair.confirmation')) aria-current="page" @endif><x-storefront.icon name="repair" class="np-nav-icon" /><span>تعمیرات کارتخوان</span></a>
            <a href="{{ route('repair.track') }}" @if (request()->routeIs('repair.track*')) aria-current="page" @endif><x-storefront.icon name="search" class="np-nav-icon" /><span>پیگیری تعمیرات</span></a>
            <a href="{{ route('articles.index') }}" @if (request()->routeIs('articles.*')) aria-current="page" @endif><x-storefront.icon name="book" class="np-nav-icon" /><span>مقالات و آموزش‌ها</span></a>
            <a href="{{ route('contact') }}" @if (request()->routeIs('contact')) aria-current="page" @endif><x-storefront.icon name="phone" class="np-nav-icon" /><span>تماس با ما</span></a>
        </nav>
        <div class="np-mobile-drawer__footer" aria-label="حساب کاربری {{ config('business.name') }}">
            @if ($shopTools)
                <a class="np-mobile-drawer__account np-mobile-drawer__cart" href="{{ route('cart.index') }}">
                    <x-storefront.icon name="cart" class="np-nav-icon" />
                    <span>سبد خرید</span>
                    <x-storefront.cart-count />
                    <x-storefront.icon name="arrow" class="np-drawer-arrow" />
                </a>
            @endif
            <a class="np-mobile-drawer__account" href="{{ $customerAccount ? route('account.index') : route('account.login') }}">
                <x-storefront.icon name="account" class="np-nav-icon" />
                <span>{{ $customerAccount ? 'حساب کاربری' : 'ورود / ثبت‌نام' }}</span>
                <x-storefront.icon name="arrow" class="np-drawer-arrow" />
            </a>
        </div>
    </div>
</dialog>
