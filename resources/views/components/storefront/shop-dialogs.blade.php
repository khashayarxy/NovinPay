<dialog id="cart-dialog" class="cart-dialog store-cart-drawer" data-storefront-dialog data-cart-url="{{ route('cart.index') }}" aria-labelledby="cart-title">
    <div class="overlay-heading"><h2 id="cart-title">سبد خرید</h2><button type="button" data-storefront-close aria-label="بستن سبد خرید"><x-storefront.icon name="close" /></button></div>
    <div class="live-cart" data-live-cart aria-live="polite"><p>در حال دریافت سبد خرید…</p></div>
    <p class="store-cart-error" data-cart-error role="alert" hidden></p>
    <div class="cart-sheet-footer" data-cart-footer hidden><div data-cart-summary></div><button type="button" data-cart-retry hidden>تلاش دوباره</button><a href="{{ route('cart.index') }}">مشاهدهٔ صفحهٔ سبد خرید<x-storefront.icon name="arrow" /></a></div>
    <template data-empty-cart><div class="empty-cart"><div class="empty-cart-icon"><x-storefront.icon name="cart" /></div><h3>سبد خرید شما خالی است</h3><a class="primary" href="{{ route('products.index') }}">رفتن به فروشگاه</a></div></template>
</dialog>
<dialog id="search-dialog" class="search-dialog store-search-dialog" data-storefront-dialog aria-labelledby="search-title">
    <div class="overlay-heading"><h2 id="search-title">جست‌وجو در فروشگاه</h2><button type="button" data-storefront-close aria-label="بستن جست‌وجو"><x-storefront.icon name="close" /></button></div>
    <form action="{{ route('products.index') }}" method="get"><label for="product-search">نام یا مدل دستگاه</label><input id="product-search" name="q" type="search" maxlength="100" placeholder="مثلاً ME31" autocomplete="off" enterkeyhint="search"><button type="submit" class="primary"><x-storefront.icon name="search" />جست‌وجو</button></form>
</dialog>
