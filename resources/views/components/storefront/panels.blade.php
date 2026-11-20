@props(['account' => null])
@php
    $activeCategory = request()->routeIs('products.index') ? request()->query('category') : null;
@endphp
<dialog id="category-dialog" class="category-dialog" data-storefront-dialog aria-labelledby="category-title">
    <div class="overlay-heading"><a href="{{ route('home') }}" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-home.wordmark /></a><button type="button" data-storefront-close aria-label="بستن منو"><x-storefront.icon name="close" /></button></div>
    <nav class="menu-scroll" aria-label="ناوبری فروشگاه">
        <h2 id="category-title">فروشگاه {{ config('business.name') }}</h2>
        <a class="menu-row" href="{{ route('home') }}" @if (request()->routeIs('home')) aria-current="page" @endif><x-storefront.icon name="home" /><span>صفحه اصلی</span><x-storefront.icon name="arrow" class="menu-chevron" /></a>
        <a class="menu-row" href="{{ route('products.index') }}" @if (request()->routeIs('products.index') && ! $activeCategory) aria-current="page" @endif><x-storefront.icon name="shop" /><span>همهٔ محصولات</span><x-storefront.icon name="arrow" class="menu-chevron" /></a>
        <details class="category-group" open>
            <summary><x-storefront.icon name="categories" /><span>دسته‌بندی محصولات</span><x-storefront.icon name="chevron-down" class="category-chevron" /></summary>
            <div class="category-sublist">
                @foreach (\App\Enums\ProductCategory::cases() as $category)
                    <a href="{{ route('products.index', ['category' => $category->value]) }}" @if ($activeCategory === $category->value) aria-current="page" @endif><span>{{ $category->label() }}</span><x-storefront.icon name="arrow" class="menu-chevron" /></a>
                @endforeach
            </div>
        </details>
        <a class="menu-row" href="{{ route('repair') }}" @if (request()->routeIs('repair', 'repair.store', 'repair.confirmation')) aria-current="page" @endif><x-storefront.icon name="repair" /><span>تعمیرات کارتخوان</span><x-storefront.icon name="arrow" class="menu-chevron" /></a>
        <a class="menu-row" href="{{ route('repair.track') }}" @if (request()->routeIs('repair.track*')) aria-current="page" @endif><x-storefront.icon name="search" /><span>پیگیری تعمیرات</span><x-storefront.icon name="arrow" class="menu-chevron" /></a>
        <a class="menu-row" href="{{ route('articles.index') }}" @if (request()->routeIs('articles.*')) aria-current="page" @endif><x-storefront.icon name="book" /><span>مقالات و آموزش‌ها</span><x-storefront.icon name="arrow" class="menu-chevron" /></a>
        <a class="menu-row" href="{{ route('contact') }}" @if (request()->routeIs('contact')) aria-current="page" @endif><x-storefront.icon name="phone" /><span>تماس با ما</span><x-storefront.icon name="arrow" class="menu-chevron" /></a>
    </nav>
    <div class="menu-bottom">
        <a class="menu-account-link" href="{{ route('account.index') }}" data-storefront-open="account-dialog" aria-haspopup="dialog"><x-storefront.icon name="account" /><span>ورود / حساب کاربری</span><x-storefront.icon name="arrow" class="menu-chevron" /></a>
        <div class="menu-phones" aria-label="شماره‌های تماس {{ config('business.name') }}">
            <x-business-phone><x-storefront.icon name="phone" /></x-business-phone>
            @if (config('business.secondary_phone'))
                <x-business-phone secondary><x-storefront.icon name="phone" /></x-business-phone>
            @endif
        </div>
    </div>
</dialog>
