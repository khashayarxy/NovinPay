<header class="np-header">
    <div class="np-container np-header__inner">
        <a href="{{ route('home') }}" class="np-logo-link" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-brand-logo /></a>
        <nav aria-label="ناوبری اصلی" class="np-nav">
            <a href="{{ route('home') }}" aria-current="page">صفحه اصلی</a>
            <a href="{{ route('products.index') }}">محصولات</a>
            <a href="{{ route('repair') }}">تعمیرات</a>
            <a href="{{ route('services') }}">خدمات</a>
            <a href="{{ route('contact') }}">تماس با ما</a>
        </nav>
        <div class="np-header__actions">
            <a href="{{ route('products.index') }}" class="np-search" aria-label="جستجوی محصولات"><x-home.icon name="search" /></a>
            <a href="{{ config('business.phone_uri') }}" class="np-button np-button--primary np-inquiry"><span>استعلام قیمت</span><x-home.icon name="arrow-left" /></a>
        </div>
    </div>
</header>
