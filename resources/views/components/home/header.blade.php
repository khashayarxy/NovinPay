@php
    $navigation = [['home', 'home', 'صفحه اصلی'], ['products.index', 'products.*', 'فروشگاه'], ['repair', 'repair*', 'تعمیرگاه'], ['contact', 'contact', 'تماس با ما']];
@endphp

<header class="np-header">
    <div class="np-container np-header__inner">
        <a href="{{ route('home') }}" class="np-logo-link" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-home.wordmark /></a>
        <nav aria-label="ناوبری اصلی" class="np-nav">
            @foreach ($navigation as [$route, $active, $label])
                <a href="{{ route($route) }}" @if (request()->routeIs($active)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <details class="np-mobile-menu">
            <summary>فهرست <x-home.icon name="arrow-left" /></summary>
            <nav aria-label="ناوبری موبایل">
                @foreach ($navigation as [$route, $active, $label])
                    <a href="{{ route($route) }}" @if (request()->routeIs($active)) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </details>
    </div>
</header>
