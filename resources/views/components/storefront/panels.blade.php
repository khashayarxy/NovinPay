@props(['account' => null])
<dialog id="category-dialog" class="category-dialog" data-storefront-dialog aria-labelledby="category-title">
    <div class="overlay-heading"><a href="{{ route('home') }}" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-home.wordmark /></a><button type="button" data-storefront-close aria-label="بستن منو"><x-storefront.icon name="close" /></button></div>
    <div class="menu-scroll"><h2 id="category-title">فروشگاه {{ config('business.name') }}</h2>
        <a class="menu-row" href="{{ route('home') }}"><x-storefront.icon name="home" />خانه</a>
        <a class="menu-row" href="{{ route('products.index') }}"><x-storefront.icon name="shop" />همهٔ محصولات<x-storefront.icon name="arrow" /></a>
        <details class="category-group" open><summary><x-storefront.icon name="shop" />دسته‌بندی محصولات<span aria-hidden="true">⌄</span></summary>
            @foreach (\App\Enums\ProductCategory::cases() as $category)
                <a href="{{ route('products.index', ['category' => $category->value]) }}">{{ $category->label() }}<x-storefront.icon name="arrow" /></a>
            @endforeach
        </details>
        <a class="menu-row" href="{{ route('repair') }}"><x-storefront.icon name="repair" />درخواست تعمیرات<x-storefront.icon name="arrow" /></a>
    </div>
    <div class="menu-bottom"><a href="{{ route('account.index') }}" data-storefront-open="account-dialog" aria-haspopup="dialog"><x-storefront.icon name="account" />ورود / حساب کاربری<x-storefront.icon name="arrow" /></a><a href="{{ config('business.phone_uri') }}"><x-storefront.icon name="phone" />تماس با {{ config('business.name') }}<bdi>{{ \App\Support\FaNumber::digits(config('business.phone')) }}</bdi></a></div>
</dialog>
<dialog id="account-dialog" class="account-dialog" data-storefront-dialog data-account-url="{{ route('customer.header') }}" aria-labelledby="account-title">
    <div class="overlay-heading"><h2 id="account-title">حساب کاربری</h2><button type="button" data-storefront-close aria-label="بستن حساب کاربری"><x-storefront.icon name="close" /></button></div>
    <div class="guest-identity"><span><x-storefront.icon name="account" /></span><div><strong>به {{ config('business.name') }} خوش آمدید</strong><p data-account-guest-copy @if($account) hidden @endif>برای مشاهدهٔ حساب خود وارد شوید.</p></div></div>
    <div data-storefront-account-content><x-home.customer-entry :account="$account" /></div>
    <a class="account-link" href="{{ route('cart.index') }}" data-account-guest-copy @if($account) hidden @endif><x-storefront.icon name="cart" />مشاهدهٔ سبد خرید<x-storefront.icon name="arrow" /></a>
    <a class="account-link" href="{{ config('business.phone_uri') }}"><x-storefront.icon name="phone" />پشتیبانی {{ config('business.name') }}<x-storefront.icon name="arrow" /></a>
</dialog>
