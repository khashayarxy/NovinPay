@props(['product', 'sale' => null])
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

<article class="card public-product-card flex flex-col overflow-hidden">
    <a href="{{ route('products.show', $product) }}" class="block bg-slate-100" tabindex="-1" aria-hidden="true">
        <div class="aspect-[4/3]">
            <x-product-image :product="$product" />
        </div>
    </a>

    <div class="flex flex-1 flex-col gap-3 p-4 sm:p-5">
        <div class="flex flex-wrap gap-2">
            <span class="badge {{ $product->condition->value === 'new' ? 'badge-success' : 'badge-warning' }}">
                {{ $product->condition->label() }}
            </span>
            <span
                class="badge {{ $sale === \App\Support\ProductSale::Online ? 'badge-success' : 'badge-warning' }}">
                {{ $status }}
            </span>
            @if ($product->is_sample)
                <span class="badge badge-sample">داده نمونه</span>
            @endif
        </div>

        <h3 class="text-base font-semibold leading-7 break-words">
            <a href="{{ route('products.show', $product) }}" class="transition-colors hover:text-brand-700">
                {{ $product->name }}
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
            <a href="{{ route('products.show', $product) }}" class="text-sm font-semibold text-brand-700 hover:underline"
                aria-label="{{ $action }} برای {{ $product->name }}">
                {{ $action }}
            </a>
        </div>
    </div>
</article>
