@if ($product->color)
    <div class="product-colors product-color-picker">
        <p id="product-color-heading">رنگ: <strong>{{ $product->color }}</strong></p>
        <ul class="product-swatches" aria-labelledby="product-color-heading">
            @foreach ($variants as $variant)
                @php
                    $selected = $variant->is($product);
                    $variantSale = $variantSales[$variant->id];
                    $unavailable = in_array($variantSale, [\App\Support\ProductSale::OutOfStock, \App\Support\ProductSale::Paused], true);
                    $swatch = preg_match('/\A#[a-fA-F0-9]{6}\z/', $variant->color_swatch ?? '') ? $variant->color_swatch : null;
                    $label = $variant->color.($unavailable ? '، '.$variantSale->stockLabel($variant) : '');
                @endphp
                <li class="product-color-option" @if($unavailable) data-color-unavailable @endif>
                    <a class="product-color-chip" href="{{ route('products.show', $variant) }}"
                       aria-label="{{ $selected ? $label : 'مشاهدهٔ رنگ '.$label }}" @if($selected) aria-current="true" @endif>
                        <span class="product-color-dot" @if($swatch) style="--swatch: {{ $swatch }}" @endif aria-hidden="true"></span>
                    </a>
                    <span class="product-color-tooltip" aria-hidden="true">{{ $label }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
