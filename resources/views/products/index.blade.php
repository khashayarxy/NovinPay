@extends('layouts.app')
@section('title', 'محصولات')

@section('content')
<div class="np-container public-section public-catalogue">

    <header class="store-page-intro">
        <h1>فروشگاه کارتخوان</h1>
        <p>دستگاه مناسب کسب‌وکار خود را پیدا کنید.</p>
    </header>

    @if ($errors->any())
        <p id="error-summary" role="alert" tabindex="-1" class="public-notice">{{ $errors->first() }}</p>
    @endif

    <form class="catalog-search" action="{{ route('products.index') }}" method="get" aria-label="جستجو و مرتب‌سازی">
        @if ($category)<input type="hidden" name="category" value="{{ $category->value }}">@endif
        @if ($condition)<input type="hidden" name="condition" value="{{ $condition }}">@endif
        @if ($sort)<input type="hidden" name="sort" value="{{ $sort }}">@endif
        <div class="catalog-search-field">
            <label for="q" class="sr-only">جستجوی محصول</label>
            <input id="q" name="q" type="search" class="input" value="{{ $term }}" maxlength="100"
                placeholder="نام یا مدل کالا" enterkeyhint="search">
            <button type="submit" aria-label="جستجو"><x-storefront.icon name="search" /></button>
        </div>
        <div class="catalog-toolbar">
            <button type="button" class="btn btn-secondary catalog-filter-trigger" data-storefront-open="catalog-filter-dialog" aria-haspopup="dialog"><x-storefront.icon name="filter" /> فیلترها @if ($category || $condition)<span class="sr-only">فعال</span>@endif</button>
            <button type="button" class="btn btn-secondary" data-storefront-open="catalog-sort-dialog" aria-haspopup="dialog">مرتب‌سازی</button>
        </div>
    </form>

    <div class="catalog-quick-pills" aria-label="فیلتر سریع دسته‌بندی و وضعیت">
        <div class="catalog-quick-pills__row" role="navigation" aria-label="دسته‌بندی محصولات">
            <a href="{{ route('products.index', array_filter(['q' => $term ?: null, 'condition' => $condition, 'sort' => $sort])) }}"
               class="catalog-pill"
               @if (! $category) aria-current="true" @endif>همه محصولات</a>
            @foreach ($categories as $item)
                <a href="{{ route('products.index', array_filter(['category' => $item->value, 'q' => $term ?: null, 'condition' => $condition, 'sort' => $sort])) }}"
                   class="catalog-pill"
                   @if ($category?->value === $item->value) aria-current="true" @endif>{{ $item->label() }}</a>
            @endforeach
        </div>
        <div class="catalog-quick-pills__row catalog-quick-pills__row--secondary" role="navigation" aria-label="فیلتر وضعیت کالا">
            <a href="{{ route('products.index', array_filter(['category' => $category?->value, 'q' => $term ?: null, 'sort' => $sort])) }}"
               class="catalog-pill catalog-pill--sm"
               @if (! $condition) aria-current="true" @endif>همه وضعیت‌ها</a>
            @foreach (\App\Enums\ProductCondition::cases() as $item)
                <a href="{{ route('products.index', array_filter(['category' => $category?->value, 'q' => $term ?: null, 'condition' => $item->value, 'sort' => $sort])) }}"
                   class="catalog-pill catalog-pill--sm"
                   @if ($condition === $item->value) aria-current="true" @endif>{{ $item->label() }}</a>
            @endforeach
        </div>
    </div>
    <div class="catalog-layout">
        <aside class="catalog-sidebar" aria-label="فیلتر محصولات">
            <form data-catalog-filters action="{{ route('products.index') }}" method="get">
                @if ($term !== '')<input type="hidden" name="q" value="{{ $term }}">@endif
                @if ($sort)<input type="hidden" name="sort" value="{{ $sort }}">@endif
                <div>
                    <label for="category" class="label">دسته‌بندی</label>
                    <select id="category" name="category" class="input">
                        <option value="">همه دسته‌بندی‌ها</option>
                        @foreach ($categories as $item)
                            <option value="{{ $item->value }}" @selected($category?->value === $item->value)>
                                {{ $item->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label" for="condition">وضعیت کالا</label>
                    <select class="input" name="condition" id="condition">
                        <option value="">همه وضعیت‌ها</option>
                        @foreach (\App\Enums\ProductCondition::cases() as $item)
                            <option value="{{ $item->value }}" @selected($condition === $item->value)>{{ $item->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="catalog-filter-actions">
                    <button class="btn btn-primary" type="submit">نمایش نتایج</button>
                    @if ($hasFilters)<a href="{{ route('products.index') }}" class="btn btn-secondary">پاک کردن فیلترها</a>@endif
                </div>
            </form>
        </aside>
        <section aria-label="فهرست محصولات">
            <h2 class="sr-only">فهرست محصولات</h2>
            <p class="catalog-count">{{ \App\Support\FaNumber::digits($products->total()) }} محصول یافت شد</p>
        <div class="catalog-results">
            @if ($products->isEmpty())
                @if ($hasFilters)
                    <x-empty-state title="محصولی با این جستجو پیدا نشد"
                        message="عبارت جستجو یا دسته‌بندی را تغییر دهید و دوباره تلاش کنید.">
                        <a href="{{ route('products.index') }}" class="btn btn-secondary">نمایش همه محصولات</a>
                    </x-empty-state>
                @else
                    <x-empty-state title="هنوز محصولی ثبت نشده است"
                        message="محصولات پس از تأیید در این صفحه نمایش داده می‌شوند.">
                        <a href="{{ route('contact') }}" class="btn btn-secondary">راهنمایی خرید</a>
                    </x-empty-state>
                @endif
            @else
                <div class="public-product-grid">
                    @foreach ($products as $product)
                        <x-product-card :product="$product" :sale="$sales[$product->id]" :quantity="$stock[$product->id] ?? null" />
                    @endforeach
                </div>

                @if ($products->hasPages())
                    <div class="mt-10">
                        {{ $products->links() }}
                    </div>
                @endif
            @endif
        </div>
        </section>
    </div>
    <dialog class="store-filter-dialog" id="catalog-sort-dialog" data-storefront-dialog aria-labelledby="sort-title">
        <header class="overlay-heading"><h2 id="sort-title">مرتب‌سازی محصولات</h2><button type="button" data-storefront-close aria-label="بستن"><x-storefront.icon name="close" /></button></header>
        <form class="public-form p-4" action="{{ route('products.index') }}">
            @if ($term !== '')<input type="hidden" name="q" value="{{ $term }}">@endif
            @if ($category)<input type="hidden" name="category" value="{{ $category->value }}">@endif
            @if ($condition)<input type="hidden" name="condition" value="{{ $condition }}">@endif
            <label for="sort">ترتیب نمایش</label>
            <select name="sort" id="sort" class="input">
                <option value="">تازه‌ترین</option>
                <option value="price-low" @selected($sort === 'price-low')>ارزان‌ترین</option>
                <option value="price-high" @selected($sort === 'price-high')>گران‌ترین</option>
            </select>
            <button class="btn btn-primary">نمایش نتایج</button>
        </form>
    </dialog>
    <dialog class="store-filter-dialog" id="catalog-filter-dialog" data-storefront-dialog aria-labelledby="filter-title">
        <header class="overlay-heading"><h2 id="filter-title">فیلتر محصولات</h2><button type="button" data-storefront-close aria-label="بستن فیلترها"><x-storefront.icon name="close" /></button></header>
        <div data-filter-slot></div>
    </dialog>
</div>
@endsection
