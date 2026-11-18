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
        <a href="{{ route($route) }}" @if (request()->routeIs($family)) aria-current="{{ request()->routeIs($route) ? 'page' : 'location' }}" @endif>
            <x-storefront.icon :name="$icon" />
            <span>{{ $label }}</span>
        </a>
    @endforeach
</nav>
