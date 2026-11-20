@php
    $dialogCustomerAccount = app(\App\Services\CustomerLogin::class)->authenticatedAccount(request());
@endphp
<dialog id="cart-dialog" class="cart-dialog store-cart-drawer" data-storefront-dialog data-cart-url="{{ route('cart.index') }}" aria-labelledby="cart-title">
    <div class="overlay-heading"><h2 id="cart-title">سبد خرید</h2><button type="button" data-storefront-close aria-label="بستن سبد خرید"><x-storefront.icon name="close" /></button></div>
    <p class="store-cart-status" data-cart-status role="status" hidden></p>
    <div class="live-cart" data-live-cart></div>
    <p class="store-cart-error" data-cart-error role="alert" hidden></p>
    <div class="cart-sheet-footer" data-cart-footer hidden>
        <div data-cart-summary></div>
        <div class="commerce-actions commerce-cart-fallback"><a class="commerce-action commerce-action--secondary" href="{{ route('cart.index') }}">مشاهدهٔ سبد</a></div>
        <button type="button" class="commerce-action commerce-action--secondary" data-cart-retry hidden>تلاش دوباره</button>
    </div>
    <template data-empty-cart><div class="commerce-empty empty-cart"><span class="empty-cart-icon"><x-storefront.icon name="cart" /></span><h3>سبد خرید شما خالی است</h3><a class="commerce-action commerce-action--secondary" href="{{ route('products.index') }}">مشاهدهٔ محصولات</a></div></template>
</dialog>
<dialog id="search-dialog" class="search-dialog store-search-dialog" data-storefront-dialog aria-labelledby="search-title">
    <div class="overlay-heading"><h2 id="search-title">جست‌وجو در فروشگاه</h2><button type="button" data-storefront-close aria-label="بستن جست‌وجو"><x-storefront.icon name="close" /></button></div>
    <form action="{{ route('products.index') }}" method="get"><label for="product-search">نام یا مدل دستگاه</label><input id="product-search" name="q" type="search" maxlength="100" placeholder="مثلاً ME31" autocomplete="off" enterkeyhint="search"><button type="submit" class="primary"><x-storefront.icon name="search" />جست‌وجو</button></form>
</dialog>
<dialog id="account-dialog" class="account-dialog store-account-dialog" data-storefront-dialog data-account-url="{{ route('customer.header') }}" aria-labelledby="account-title">
    <div class="overlay-heading"><h2 id="account-title">حساب کاربری</h2><button type="button" data-storefront-close aria-label="بستن حساب کاربری"><x-storefront.icon name="close" /></button></div>
    <div class="guest-identity"><span><x-storefront.icon name="account" /></span><div><strong>به {{ config('business.name') }} خوش آمدید</strong><p data-account-guest-copy @if($dialogCustomerAccount) hidden @endif>برای مشاهدهٔ حساب خود وارد شوید.</p></div></div>
    <div data-storefront-account-content><x-home.customer-entry :account="$dialogCustomerAccount" /></div>
    <a class="account-link" href="{{ route('cart.index') }}" data-account-guest-copy @if($dialogCustomerAccount) hidden @endif><x-storefront.icon name="cart" />مشاهدهٔ سبد خرید<x-storefront.icon name="arrow" /></a>
</dialog>
