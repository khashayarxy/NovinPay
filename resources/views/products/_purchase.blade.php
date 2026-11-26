<aside class="purchase card" id="product-purchase" aria-label="قیمت و شرایط خرید" tabindex="-1">
    <div class="price-group">
        <p class="muted">{{ $isTerminal ? 'قیمت فروش دستگاه' : 'قیمت فروش' }}</p>
        <p class="price">@if ($formattedPrice !== null)<bdi>{{ $formattedPrice }}</bdi> <small>تومان</small>@else<small>قیمت اعلام نشده</small>@endif</p>
        @if ($priceDate)
            <p class="muted">آخرین به‌روزرسانی قیمت: <time datetime="{{ $product->price_changed_at->toIso8601String() }}">{{ $priceDate }}</time></p>
        @endif
    </div>
    @if ($canAddToCart)
        @php
            $maxProductQty = \App\Services\ShoppingCart::maxQuantityForOffer($inventory ?? null);
            $currentCartQty = max(0, (int) ($cartQuantity ?? 0));
            $removeScope = ($cartSeparate ?? false) ? 'hide' : 'device';
        @endphp
        <div class="product-cart-control"
             data-product-primary-action
             data-product-cart-control
             data-product-slug="{{ $product->slug }}"
             data-product-qty="{{ $currentCartQty }}"
             data-product-max="{{ $maxProductQty }}"
             data-update-url="{{ route('cart.update', $product) }}"
             data-remove-url="{{ route('cart.remove', $product->slug) }}"
             data-remove-scope="{{ $removeScope }}">
            <div class="purchase-stock-row">
                <div @class(['stock', 'is-available' => $canAddToCart])>
                    <strong>{{ $product->is_sample ? $product->stock_status->label() : $sale->stockLabel($product) }}</strong>
                    @if ($canAddToCart && isset($inventory) && $inventory->available > 0)
                        <span class="stock-availability-note">
                            {{ \App\Support\FaNumber::digits($inventory->available) }} عدد
                        </span>
                    @endif
                </div>
                <div class="np-inline-stepper np-inline-stepper--purchase" data-inline-stepper @if($currentCartQty <= 0) hidden @endif>
                    <button type="button" class="np-inline-stepper__btn np-inline-stepper__btn--inc" data-stepper-inc aria-label="افزایش تعداد در سبد خرید" @disabled($currentCartQty >= $maxProductQty)>+</button>
                    <span class="np-inline-stepper__count" data-stepper-count aria-live="polite">{{ \App\Support\FaNumber::digits(max(1, $currentCartQty)) }}</span>
                    <button type="button" class="np-inline-stepper__btn np-inline-stepper__btn--dec" data-stepper-dec data-mode="{{ $currentCartQty <= 1 ? 'remove' : 'decrement' }}" aria-label="{{ $currentCartQty <= 1 ? 'حذف از سبد خرید' : 'کاهش تعداد' }}">
                        <span class="np-inline-stepper__minus" @if($currentCartQty <= 1) hidden @endif>−</span>
                        <span class="np-inline-stepper__trash" @if($currentCartQty > 1) hidden @endif aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                        </span>
                    </button>
                </div>
            </div>
            <form id="product-cart-form" method="post" action="{{ route('cart.add', $product) }}" class="product-cart-form" data-cart-mutation @if($currentCartQty > 0) hidden @endif>
                @csrf
                <x-storefront.cart-context :proof="$cartProof ?? null" :revision="$cartRevision ?? null" />
                <input type="hidden" name="quantity" value="1">
                <button class="primary" type="submit">خرید محصول</button>
            </form>
        </div>
    @else
        <div @class(['stock', 'is-available' => false])>
            <strong>{{ $product->is_sample ? $product->stock_status->label() : $sale->stockLabel($product) }}</strong>
        </div>
        @if ($inquiry)
            <a class="primary" href="{{ config('business.phone_uri') }}" data-product-primary-action><x-storefront.icon name="phone" />{{ $sale->label() }}</a>
        @elseif ($product->is_sample)
            <p class="muted">این کالا نمونهٔ نمایشی است. قیمت و موجودی آن واقعی نیست.</p>
        @endif
    @endif
    @if ($warranty || $benefits)
        <ul class="services" aria-label="گارانتی و خدمات همراه">
            @if ($warranty)
                <li><span class="service-icon"><x-storefront.icon name="shield" /></span><strong>{{ $warranty }}@if ($warrantyProvider) {{ $warrantyProvider }}@endif</strong></li>
            @endif
            @foreach ($benefits as $benefit)
                <li><span class="service-icon"><x-storefront.icon name="check" /></span><strong>{{ $benefit }}</strong></li>
            @endforeach
        </ul>
    @endif
    @if (!$benefits && $serviceRows)
        <dl class="product-benefits" aria-label="خدمات همراه">
            @foreach ($serviceRows as [$label, $value])<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
        </dl>
    @endif
    <dl class="purchase-meta">
        @if ($sku)
            <div><dt><x-storefront.icon name="identifier" />شناسه کالا</dt><dd><bdi dir="ltr">{{ $sku }}</bdi></dd></div>
        @endif
        @if ($product->category)
            <div><dt><x-storefront.icon name="shop" />دسته‌بندی</dt><dd><a href="{{ route('products.index', ['category' => $product->category->value]) }}">{{ $product->category->label() }}</a></dd></div>
        @endif
    </dl>
</aside>
