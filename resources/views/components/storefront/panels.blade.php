@props(['navigation'])

<dialog id="mobile-drawer" class="store-menu-dialog" data-storefront-dialog aria-label="ناوبری موبایل">
    <div class="overlay-heading">
        <a href="{{ route('home') }}" class="np-mobile-drawer__logo" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-home.wordmark /></a>
        <button type="button" data-storefront-close aria-label="بستن فهرست ناوبری" autofocus><x-storefront.icon name="close" /></button>
    </div>
    <nav id="mobile-navigation" aria-label="ناوبری موبایل">
        @foreach ($navigation as [$route, $active, $label, $icon])
            <a class="store-menu-row" href="{{ route($route) }}" @if(request()->routeIs($active)) aria-current="page" @endif>
                <x-storefront.icon :name="$icon" /><span>{{ $label }}</span><x-storefront.icon name="arrow" class="store-menu-chevron" />
            </a>
            @if ($route === 'products.index')
                <details class="store-menu-categories" @if(request()->routeIs('products.index') && request()->filled('category')) open @endif>
                    <summary class="store-menu-row">
                        <span>دسته‌بندی محصولات</span><x-storefront.icon name="chevron-down" class="store-menu-chevron" />
                    </summary>
                    @foreach (\App\Enums\ProductCategory::cases() as $category)
                        <a href="{{ route('products.index', ['category' => $category->value]) }}"
                            @if(request()->routeIs('products.index') && request()->query('category') === $category->value) aria-current="page" @endif>
                            {{ $category->label() }}
                        </a>
                    @endforeach
                </details>
            @endif
        @endforeach
    </nav>
    <div class="store-menu-contact">
        <p>تماس با {{ config('business.name') }}</p>
        <x-business-phone both />
    </div>
</dialog>
