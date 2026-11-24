@props(['product', 'sale' => null, 'quantity' => null])
@php
    $sale ??= \App\Support\ProductSale::resolve($product, null);
    $known = \App\Support\ProductSale::priceConfirmed($product);
    $status = $product->is_sample ? $product->stock_status->label() : $sale->stockLabel($product);
    $action = match (true) {
        $product->is_sample, $sale === \App\Support\ProductSale::OutOfStock,
            $sale === \App\Support\ProductSale::AwaitingStock, $sale === \App\Support\ProductSale::Paused => 'جزئیات',
        $sale === \App\Support\ProductSale::Online => 'جزئیات و خرید',
        default => $sale->label(),
    };
@endphp

<article class="card public-product-card relative flex flex-col overflow-hidden">
    <div class="public-product-card__media">
        <div class="aspect-[4/3]">
            <x-product-image :product="$product" />
        </div>
    </div>

    <div class="public-product-card__body flex flex-1 flex-col gap-3 p-4 sm:p-5">
        <div class="flex flex-wrap gap-2">
            <span class="badge {{ $product->condition->value === 'new' ? 'badge-success' : 'badge-warning' }}">
                {{ $product->condition->label() }}
            </span>
            <span
                class="badge {{ $sale === \App\Support\ProductSale::Online ? 'badge-success' : 'badge-warning' }}">
                {{ $status }}
            </span>
            @if ($sale === \App\Support\ProductSale::Online && $quantity > 0)
                <span class="text-sm">{{ \App\Support\FaNumber::digits($quantity) }} عدد</span>
            @endif
            @if ($product->isPrivatePaymentTest())
                <span class="badge badge-sample">خصوصی · تست درگاه</span>
            @endif
            @if ($product->is_sample)
                <span class="badge badge-sample">داده نمونه</span>
            @endif
        </div>

        <h3 class="text-base font-semibold leading-7 break-words">
            <a href="{{ route('products.show', $product) }}" class="transition-colors hover:text-brand-700 after:absolute after:inset-0">
                {{ \App\Support\LatinText::html($product->name) }}
            </a>
        </h3>

        @if ($product->summary)
            <p class="text-sm leading-7 text-slate-600 public-product-summary">{{ $product->summary }}</p>
        @endif

        <div class="mt-auto flex items-end justify-between gap-3 border-t border-slate-100 pt-3">
            <p class="text-sm font-bold text-slate-900">
                @if ($known || ($product->is_sample && $product->price !== null))
                    {{ \App\Support\FaNumber::digits(number_format($product->price, 0, '.', '٬')) }}
                    <span class="text-xs font-normal text-slate-500">تومان</span>
                @else
                    <span class="text-xs font-normal text-slate-600">قیمت نیاز به استعلام دارد</span>
                @endif
            </p>
            <span class="text-sm font-semibold text-brand-700">{{ $action }}</span>
        </div>
    </div>
</article>
