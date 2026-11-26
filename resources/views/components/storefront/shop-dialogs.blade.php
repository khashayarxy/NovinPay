@php
    $onShopPage = request()->routeIs('products.*');
    $cartService = app(\App\Services\ShoppingCart::class);
    $hasPotentialCart = $cartService->hasPotentialCart(request());
    $ssrCart = (request()->routeIs('cart.index') || ! $hasPotentialCart) ? null : $cartService->viewData(request());
@endphp
<dialog id="cart-dialog" class="cart-dialog store-cart-drawer" data-storefront-dialog data-cart-url="{{ route('cart.index') }}" @if($hasPotentialCart) data-cart-preload @else data-cart-empty-guest="1" @endif aria-labelledby="cart-title">
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
                :stock-available="$ssrCart['stockAvailable']"
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
    <div class="overlay-heading"><h2 id="search-title">جستجو در فروشگاه</h2><button type="button" data-storefront-close aria-label="بستن جستجو"><x-storefront.icon name="close" /></button></div>
    <form action="{{ route('products.index') }}" method="get">
        <label for="product-search">نام یا مدل دستگاه</label>
        <input id="product-search" name="q" type="search" maxlength="100" class="np-latin-mixed" placeholder="مثلاً ME31" autocomplete="off" enterkeyhint="search">
        <div class="search-quick-suggestions" aria-label="جستجوهای پیشنهادی">
            <span class="search-quick-suggestions__label">جستجوی سریع:</span>
            <div class="search-quick-suggestions__chips">
                <a class="search-quick-chip" href="{{ route('products.index', ['q' => 'رول کاغذ']) }}">رول کاغذ</a>
                <a class="search-quick-chip" href="{{ route('products.index', ['q' => 'کارتخوان سیار']) }}">کارتخوان سیار</a>
                <a class="search-quick-chip" href="{{ route('products.index', ['q' => 'ME31']) }}"><bdi class="np-latin" dir="ltr">ME31</bdi></a>
            </div>
        </div>
        <button type="submit" class="primary"><x-storefront.icon name="search" />جستجو</button>
    </form>
</dialog>
