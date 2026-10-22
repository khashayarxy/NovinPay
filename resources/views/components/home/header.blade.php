<header class="np-header">
    <div class="np-container np-header__inner">
        <a href="{{ route('home') }}" class="np-logo-link" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-home.wordmark /></a>
        <nav aria-label="ناوبری اصلی" class="np-nav">
            @foreach ([['home', 'home', 'صفحه اصلی'], ['products.index', 'products.*', 'محصولات'], ['repair', 'repair*', 'تعمیرات'], ['services', 'services', 'خدمات'], ['about', 'about', 'درباره ما'], ['contact', 'contact', 'تماس با ما']] as [$route, $active, $label])
                <a href="{{ route($route) }}" @if (request()->routeIs($active)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="np-header__actions">
            <a href="{{ route('products.index') }}" class="np-search" aria-label="جستجوی محصولات"><x-home.icon name="search" /></a>
            <a href="{{ config('business.phone_uri') }}" class="np-button np-button--primary np-inquiry"><span>استعلام قیمت</span><x-home.icon name="arrow-left" /></a>
        </div>
    </div>
</header>
