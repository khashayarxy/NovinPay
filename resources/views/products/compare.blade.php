@extends('layouts.app')
@section('title', 'مقایسهٔ محصولات')
@section('content')
<div class="np-container public-section compare-page">
    <div class="compare-page__top">
        <div>
            <a class="public-inline-link" href="{{ route('products.index') }}">→ بازگشت به فروشگاه</a>
            <div class="compare-page__title-row">
                <h1>مقایسهٔ محصولات</h1>
                @if ($lockedCategory)
                    <span class="compare-category-pill">{{ $lockedCategory->label() }}</span>
                @endif
            </div>
        </div>

        <div class="compare-toolbar">
            @if ($products->count() > 1)
                <label class="compare-diff-toggle">
                    <input type="checkbox" data-compare-diff-toggle>
                    <span class="compare-diff-toggle__track" aria-hidden="true"></span>
                    <span class="compare-diff-toggle__label">فقط تفاوت‌ها را نشان بده</span>
                </label>
            @endif

            @if ($products->count() < 4 && $choices->isNotEmpty())
                <form class="compare-add-form" action="{{ route('products.compare') }}">
                    @foreach ($products as $product)
                        <input type="hidden" name="products[]" value="{{ $product->slug }}">
                    @endforeach
                    <label class="sr-only" for="compare-add">افزودن محصول به مقایسه</label>
                    <select class="input" id="compare-add" name="add" required>
                        <option value="">افزودن محصول از همین دسته…</option>
                        @foreach ($choices as $option)
                            <option value="{{ $option->slug }}">{{ $option->displayName() }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="np-button np-button--primary compare-add-btn">افزودن</button>
                </form>
            @endif
        </div>
    </div>

    @if ($products->isNotEmpty())
        <div class="compare-board" data-compare-board tabindex="0" role="region" aria-label="جدول مقایسه محصولات">
            <table class="compare-table" style="--compare-cols: {{ $products->count() }};">
                <thead>
                    <tr class="compare-sticky-row">
                        <th scope="col" class="compare-corner-cell">
                            <span class="compare-corner-label">مشخصات فنی</span>
                            <small class="compare-corner-count">{{ \App\Support\FaNumber::digits($products->count()) }} کالا</small>
                        </th>
                        @foreach ($products as $product)
                            @php
                                $sale = $sales[$product->id] ?? \App\Support\ProductSale::resolve($product, null);
                                $known = \App\Support\ProductSale::priceConfirmed($product);
                                $stockLabel = $sale->stockLabel($product);
                            @endphp
                            <th scope="col" class="compare-product-head">
                                <div class="compare-product-card">
                                    @if ($products->count() > 1)
                                        <a class="compare-product-card__remove"
                                           href="{{ route('products.compare', ['products' => $products->where('id', '!=', $product->id)->pluck('slug')->values()->all()]) }}"
                                           aria-label="حذف {{ $product->displayName() }} از مقایسه"
                                           title="حذف از مقایسه">×</a>
                                    @endif
                                    <a href="{{ route('products.show', $product) }}" class="compare-product-card__media">
                                        <x-product-image :product="$product" />
                                    </a>
                                    <h2 class="compare-product-card__title">
                                        <a href="{{ route('products.show', $product) }}">{{ \App\Support\LatinText::html($product->displayName()) }}</a>
                                    </h2>
                                    <div class="compare-product-card__meta">
                                        <span class="badge {{ $sale === \App\Support\ProductSale::Online ? 'badge-success' : 'badge-warning' }}">{{ $stockLabel }}</span>
                                        <p class="compare-product-card__price">
                                            @if ($known)
                                                <strong>{{ \App\Support\FaNumber::digits(number_format($product->price, 0, '.', '٬')) }}</strong>
                                                <small>تومان</small>
                                            @else
                                                <small>نیاز به استعلام</small>
                                            @endif
                                        </p>
                                    </div>
                                    <a href="{{ route('products.show', $product) }}" class="np-button np-button--primary compare-product-card__cta">مشاهده و خرید</a>
                                </div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($specGroups as $groupHeading => $rows)
                        <tr class="compare-group-row" data-compare-group>
                            <th scope="colgroup" colspan="{{ $products->count() + 1 }}">{{ $groupHeading }}</th>
                        </tr>
                        @foreach ($rows as $label => $valuesBySlug)
                            @php
                                $normalizedValues = $products->map(fn ($p) => trim((string) ($valuesBySlug[$p->slug] ?? '—')))->unique();
                                $isDifferent = $products->count() > 1 && $normalizedValues->count() > 1;
                            @endphp
                            <tr class="compare-spec-row" data-compare-row data-diff="{{ $isDifferent ? '1' : '0' }}">
                                <th scope="row" class="compare-spec-label">{{ $label }}</th>
                                @foreach ($products as $product)
                                    @php($val = trim((string) ($valuesBySlug[$product->slug] ?? '')))
                                    <td class="compare-spec-value">{{ $val !== '' ? \App\Support\LatinText::html($val) : '—' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="compare-no-diff-notice" data-compare-no-diff hidden>هیچ تفاوتی میان مشخصات ثبت‌شدهٔ این کالاها وجود ندارد.</p>
    @else
        <div class="public-panel compare-empty">
            <p>برای شروع مقایسه، از فهرست بالا یا صفحهٔ محصولات یک کالا انتخاب کنید.</p>
            <a class="np-button np-button--primary" href="{{ route('products.index') }}">مشاهدهٔ فروشگاه</a>
        </div>
    @endif
</div>
@endsection
