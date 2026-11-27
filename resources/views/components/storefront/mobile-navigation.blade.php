@php
    $destinations = [
        ['home', 'home', 'خانه', 'home'],
        ['products.index', 'products.*', 'فروشگاه', 'shop'],
        ['cart.index', 'cart.*', 'سبد خرید', 'cart'],
        ['repair', 'repair*', 'تعمیرات', 'repair'],
        ['account.index', 'account.*', 'حساب کاربری', 'account'],
    ];
@endphp
<nav class="global-store-nav" data-mobile-bottom-nav aria-label="راهبری فروشگاه">
    @foreach ($destinations as [$route, $family, $label, $icon])
        <a href="{{ route($route) }}" @if ($route === 'cart.index') data-storefront-open="cart-dialog" aria-haspopup="dialog" @endif @if (request()->routeIs($family)) aria-current="{{ request()->routeIs($route) ? 'page' : 'location' }}" @endif>
            <span class="global-store-nav__icon-wrap">
                <x-storefront.icon :name="$icon" />
                @if ($route === 'cart.index')<x-storefront.cart-count />@endif
            </span>
            <span>{{ $label }}</span>
        </a>
    @endforeach
</nav>
