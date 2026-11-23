<aside class="purchase card" id="product-purchase" aria-label="قیمت و شرایط خرید" tabindex="-1">
    <div class="price-group">
        <p class="muted">{{ $isTerminal ? 'قیمت فروش دستگاه' : 'قیمت فروش' }}</p>
        <p class="price">@if ($formattedPrice !== null)<bdi>{{ $formattedPrice }}</bdi> <small>تومان</small>@else<small>قیمت اعلام نشده</small>@endif</p>
        @if ($priceDate)
            <p class="muted">آخرین به‌روزرسانی قیمت: <time datetime="{{ $product->price_changed_at->toIso8601String() }}">{{ $priceDate }}</time></p>
        @endif
    </div>
    <div @class(['stock', 'is-available' => $canAddToCart])>
        <strong>{{ $product->is_sample ? $product->stock_status->label() : $sale->stockLabel($product) }}</strong>
        @if ($canAddToCart && isset($inventory) && $inventory->available > 0)
            <span class="stock-availability-note">
                {{ \App\Support\FaNumber::digits($inventory->available) }} عدد
            </span>
        @endif
    </div>
    @if ($canAddToCart)
        <form id="product-cart-form" method="post" action="{{ route('cart.add', $product) }}" class="product-cart-form" data-cart-mutation>
            @csrf
            <x-storefront.cart-context />
            <div>
                <label class="label" for="store-quantity">تعداد</label>
                <input class="input" id="store-quantity" type="number" name="quantity" value="1" min="1" max="{{ min(10, $inventory->available) }}" required>
            </div>
            <button class="primary" type="submit" data-product-primary-action>خرید محصول</button>

        </form>
    @elseif ($inquiry)
        <a class="primary" href="{{ config('business.phone_uri') }}" data-product-primary-action><x-storefront.icon name="phone" />{{ $sale->label() }}</a>
    @elseif ($product->is_sample)
        <p class="muted">این کالا نمونهٔ نمایشی است. قیمت و موجودی آن واقعی نیست.</p>
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
