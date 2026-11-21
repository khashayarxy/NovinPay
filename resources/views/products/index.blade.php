@extends('layouts.app')
@section('title', 'محصولات')

@section('content')
<div class="np-container public-section public-catalogue">
    @php($filterQuery = array_filter(['q' => $term, 'condition' => $condition, 'sort' => $sort]))
    <header class="store-page-intro">
        <h1>فروشگاه کارتخوان</h1>
        <p>دستگاه مناسب کسب‌وکار خود را پیدا کنید.</p>
    </header>

    @if ($errors->any())
        <p id="error-summary" role="alert" tabindex="-1" class="public-notice">{{ $errors->first() }}</p>
    @endif
    <nav class="catalog-quick-links" aria-label="دسته‌بندی محصولات">
        <a href="{{ route('products.index', $filterQuery) }}" @if (!$category) aria-current="page" @endif>همهٔ محصولات</a>
        @foreach ($categories as $item)
            <a href="{{ route('products.index', $filterQuery + ['category' => $item->value]) }}"
                @if ($category === $item) aria-current="page" @endif>{{ $item->label() }}</a>
        @endforeach
    </nav>
    <form class="catalog-search" action="{{ route('products.index') }}" method="get" aria-label="جست‌وجو و مرتب‌سازی">
        @if ($category)<input type="hidden" name="category" value="{{ $category->value }}">@endif
        @if ($condition)<input type="hidden" name="condition" value="{{ $condition }}">@endif
        <div class="catalog-search-field">
            <label for="q" class="sr-only">جست‌وجوی محصول</label>
            <input id="q" name="q" type="search" class="input" value="{{ $term }}" maxlength="100"
                placeholder="نام یا مدل کالا" enterkeyhint="search">
            <button type="submit" aria-label="جست‌وجو"><x-storefront.icon name="search" /></button>
        </div>
        <div class="catalog-toolbar">
            <button type="button" class="btn btn-secondary catalog-filter-trigger" data-storefront-open="catalog-filter-dialog" aria-haspopup="dialog"><x-storefront.icon name="filter" /> فیلترها @if ($category || $condition)<span class="sr-only">فعال</span>@endif</button>
            <label for="sort" class="sr-only">مرتب‌سازی</label>
            <select name="sort" id="sort" class="input" aria-label="مرتب‌سازی" data-catalog-sort>
                <option value="">تازه‌ترین</option>
                <option value="price-low" @selected($sort === 'price-low')>ارزان‌ترین</option>
                <option value="price-high" @selected($sort === 'price-high')>گران‌ترین</option>
            </select>
            <button type="submit" class="btn btn-secondary catalog-sort-submit">اعمال</button>
        </div>
    </form>
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
                    <x-empty-state title="محصولی با این جست‌وجو پیدا نشد"
                        message="عبارت جست‌وجو یا دسته‌بندی را تغییر دهید و دوباره تلاش کنید.">
                        <a href="{{ route('products.index') }}" class="btn btn-secondary">نمایش همه محصولات</a>
                    </x-empty-state>
                @else
                    <x-empty-state title="هنوز محصولی ثبت نشده است"
                        message="محصولات پس از تأیید در این صفحه نمایش داده می‌شوند.">
                        <a href="{{ route('contact') }}" class="btn btn-secondary">راهنمایی خرید</a>
                    </x-empty-state>
                @endif
            @else
                <form action="{{ route('products.compare') }}" method="get">
                    @php($canCompare = $products->getCollection()->where('is_sample', false)->count() > 1)
                    @if ($canCompare)
                        <div class="mb-4 flex flex-wrap items-center gap-3">
                            <button class="btn btn-secondary" type="submit">مقایسهٔ انتخاب‌ها</button>
                            <p class="text-sm text-slate-600">۲ تا ۴ محصول را انتخاب کنید.</p>
                        </div>
                    @endif
                    <div class="public-product-grid">
                        @foreach ($products as $product)
                            <div class="flex flex-col">
                                <x-product-card :product="$product" :sale="$sales[$product->id]" />
                                @if ($canCompare && !$product->is_sample)
                                    <label class="flex min-h-[44px] cursor-pointer items-center gap-2.5 px-3 py-2 text-sm">
                                        <input type="checkbox" class="h-5 w-5 rounded border-slate-300 text-brand-700" name="products[]" value="{{ $product->slug }}" aria-label="مقایسهٔ {{ $product->name }}">
                                        انتخاب برای مقایسه
                                    </label>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </form>

                @if ($products->hasPages())
                    <div class="mt-10">
                        {{ $products->links() }}
                    </div>
                @endif
            @endif
        </div>
        </section>
    </div>
    <section class="catalog-description" aria-labelledby="catalog-about">
        <h2 id="catalog-about">کارتخوان مناسب کسب‌وکار شما</h2>
        <p>محصولات ثبت‌شده را بررسی و مقایسه کنید. برای استعلام قیمت و انتخاب دستگاه با نوین‌پی تماس بگیرید.</p>
    </section>
    <dialog class="store-filter-dialog" id="catalog-filter-dialog" data-storefront-dialog aria-labelledby="filter-title">
        <header class="overlay-heading"><h2 id="filter-title">فیلتر محصولات</h2><button type="button" data-storefront-close aria-label="بستن فیلترها"><x-storefront.icon name="close" /></button></header>
        <div data-filter-slot></div>
    </dialog>
</div>
@endsection
