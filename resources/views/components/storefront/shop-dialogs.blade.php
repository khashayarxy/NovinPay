@php
    $onShopPage = request()->routeIs('products.*');
    $ssrCart = request()->routeIs('cart.index') ? null : app(\App\Services\ShoppingCart::class)->viewData(request());
@endphp
<dialog id="cart-dialog" class="cart-dialog store-cart-drawer" data-storefront-dialog data-cart-url="{{ route('cart.index') }}" data-cart-preload aria-labelledby="cart-title">
    <div class="overlay-heading"><h2 id="cart-title">سبد خرید</h2><button type="button" data-storefront-close aria-label="بستن سبد خرید"><x-storefront.icon name="close" /></button></div>
    <p class="store-cart-status" data-cart-status role="status" hidden></p>
    <div class="live-cart" data-live-cart></div>
    <p class="store-cart-error" data-cart-error role="alert" hidden></p>
    <div class="cart-sheet-footer" data-cart-footer hidden>
        <div data-cart-summary></div>
        <div class="commerce-actions commerce-cart-fallback" data-cart-view-link><a class="commerce-action commerce-action--secondary" href="{{ route('cart.index') }}">مشاهدهٔ سبد</a></div>
        <button type="button" class="commerce-action commerce-action--secondary" data-cart-retry hidden>تلاش دوباره</button>
    </div>
    <template data-empty-cart>
        <div class="commerce-empty empty-cart">
            <span class="empty-cart-icon"><x-storefront.icon name="cart" /></span>
            <h3>سبد خرید شما خالی است</h3>
            @if ($onShopPage)
                <p class="empty-cart-hint">اولین محصول را به سبد خرید اضافه کنید.</p>
            @else
                <a class="commerce-action commerce-action--secondary" href="{{ route('products.index') }}">مشاهدهٔ محصولات</a>
            @endif
        </div>
    </template>
    @if ($ssrCart !== null)
        <template data-ssr-cart>
            <x-storefront.cart-content
                :items="$ssrCart['items']"
                :products="$ssrCart['products']"
                :quantity="$ssrCart['quantity']"
                :unavailable="$ssrCart['unavailable']"
                :available="$ssrCart['available']"
                :total="$ssrCart['total']"
                :separate="$ssrCart['separate']"
                :proof="$ssrCart['proof']"
                :revision="$ssrCart['revision']"
                :excluded="$ssrCart['excluded']"
                :legacy-status="$ssrCart['legacyStatus']"
                :over-limit="$ssrCart['overLimit']"
                :drawer="true"
            />
        </template>
    @endif
</dialog>
<dialog id="search-dialog" class="search-dialog store-search-dialog" data-storefront-dialog aria-labelledby="search-title">
    <div class="overlay-heading"><h2 id="search-title">جست‌وجو در فروشگاه</h2><button type="button" data-storefront-close aria-label="بستن جست‌وجو"><x-storefront.icon name="close" /></button></div>
    <form action="{{ route('products.index') }}" method="get"><label for="product-search">نام یا مدل دستگاه</label><input id="product-search" name="q" type="search" maxlength="100" placeholder="مثلاً ME31" autocomplete="off" enterkeyhint="search"><button type="submit" class="primary"><x-storefront.icon name="search" />جست‌وجو</button></form>
</dialog>
