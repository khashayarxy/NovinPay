<header class="np-header">
    <div class="np-container np-header__inner">
        <a href="{{ route('home') }}" class="np-logo-link" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-site.logo /></a>
        <nav aria-label="ناوبری اصلی" class="np-nav">
            @foreach ([['home', 'home', 'صفحه اصلی'], ['products.index', 'products.*', 'محصولات'], ['repair', 'repair*', 'تعمیرات'], ['services', 'services', 'خدمات'], ['about', 'about', 'درباره ما'], ['contact', 'contact', 'تماس با ما']] as [$route, $active, $label])
                <a href="{{ route($route) }}" @if (request()->routeIs($active)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <details class="design-mobile-nav"><summary aria-label="فهرست اصلی"><span>فهرست</span><x-home.icon name="arrow-left"/></summary><nav aria-label="ناوبری موبایل">
@foreach ([['home','صفحه اصلی'],['products.index','محصولات'],['repair','تعمیرات'],['services','خدمات'],['about','درباره ما'],['contact','تماس با ما']] as [$route,$label])<a href="{{ route($route) }}" @if(request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>@endforeach
</nav></details>
        <div class="np-header__actions">
            <a href="{{ route('products.index') }}" class="np-search" aria-label="جستجوی محصولات"><x-home.icon name="search" /></a>
            <a href="{{ config('business.phone_uri') }}" class="np-button np-button--primary np-inquiry"><span>استعلام قیمت</span><x-home.icon name="arrow-left" /></a>
        </div>
    </div>
</header>
