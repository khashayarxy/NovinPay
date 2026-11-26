@extends('layouts.app')
@section('title', 'محصولات')

@section('content')
@php
    $sortOptions = [
        '' => 'تازه‌ترین',
        'price-low' => 'ارزان‌ترین',
        'price-high' => 'گران‌ترین',
    ];
    $activeSortLabel = $sortOptions[$sort ?? ''] ?? 'تازه‌ترین';
    $clearFiltersUrl = route('products.index', array_filter(['sort' => $sort]));
@endphp
<div class="np-container public-section public-catalogue">

    <header class="store-page-intro">
        <h1>فروشگاه کارتخوان</h1>
        <p>دستگاه مناسب کسب‌وکار خود را پیدا کنید.</p>
    </header>

    @if ($errors->any())
        <p id="error-summary" role="alert" tabindex="-1" class="public-notice">{{ $errors->first() }}</p>
    @endif

    {{-- Mobile & Tablet (< 1024px) Search & Toolbar --}}
    <div class="catalog-mobile-bar">
        <form class="catalog-search" action="{{ route('products.index') }}" method="get" aria-label="جستجو">
            @if ($category)<input type="hidden" name="category" value="{{ $category->value }}">@endif
            @if ($condition)<input type="hidden" name="condition" value="{{ $condition }}">@endif
            @if ($onlyAvailable)<input type="hidden" name="available" value="1">@endif
            @if ($sort)<input type="hidden" name="sort" value="{{ $sort }}">@endif
            <div class="catalog-search-field">
                <label for="q-mobile" class="sr-only">جستجوی محصول</label>
                <input id="q-mobile" name="q" type="search" class="input np-latin-mixed" value="{{ $term }}" maxlength="100"
                    placeholder="نام یا مدل کالا" enterkeyhint="search">
                <button type="submit" aria-label="جستجو"><x-storefront.icon name="search" /></button>
            </div>
            <div class="catalog-toolbar">
                <button type="button" class="btn btn-secondary catalog-filter-trigger" data-storefront-open="catalog-filter-dialog" aria-haspopup="dialog">
                    <x-storefront.icon name="filter" />
                    <span>فیلترها</span>
                    @if ($activeFilterCount > 0)
                        <span class="catalog-filter-badge">{{ \App\Support\FaNumber::digits($activeFilterCount) }}</span>
                    @endif
                </button>
                <button type="button" class="btn btn-secondary catalog-sort-trigger" data-storefront-open="catalog-sort-dialog" aria-haspopup="dialog">
                    <span>ترتیب: {{ $activeSortLabel }}</span>
                </button>
            </div>
        </form>
        <p class="catalog-count catalog-count--mobile">{{ \App\Support\FaNumber::digits($products->total()) }} محصول یافت شد</p>
    </div>

    <div class="catalog-layout">
        {{-- Desktop Sticky Sidebar (>= 1024px) --}}
        <aside class="catalog-sidebar" aria-label="فیلتر محصولات">
            <div class="catalog-sidebar__card">
                <div class="catalog-sidebar__head">
                    <h2>فیلترها</h2>
                    @if ($hasFilters)
                        <a href="{{ route('products.index') }}" class="catalog-sidebar__clear">حذف فیلترها</a>
                    @endif
                </div>
                <form data-desktop-filter-form action="{{ route('products.index') }}" method="get" class="catalog-sidebar__form">
                    @if ($sort)<input type="hidden" name="sort" value="{{ $sort }}">@endif

                    <div class="catalog-sidebar__section">
                        <label for="q" class="catalog-sidebar__label">جستجو</label>
                        <div class="catalog-sidebar__search">
                            <input id="q" name="q" type="search" class="input np-latin-mixed" value="{{ $term }}" maxlength="100"
                                placeholder="نام یا مدل کالا" enterkeyhint="search">
                            <button type="submit" aria-label="جستجو"><x-storefront.icon name="search" /></button>
                        </div>
                    </div>

                    <fieldset class="catalog-filter-fieldset">
                        <legend>دسته‌بندی</legend>
                        <div class="catalog-category-list" role="radiogroup" aria-label="دسته‌بندی">
                            <label class="catalog-category-item">
                                <input type="radio" name="category" value="" @checked(! $category) data-auto-submit-filter>
                                <span>همه محصولات</span>
                            </label>
                            @foreach ($categories as $item)
                                <label class="catalog-category-item">
                                    <input type="radio" name="category" value="{{ $item->value }}" @checked($category?->value === $item->value) data-auto-submit-filter>
                                    <span>{{ $item->label() }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset class="catalog-filter-fieldset">
                        <legend>وضعیت کالا</legend>
                        <div class="catalog-filter-chips" role="radiogroup" aria-label="وضعیت کالا">
                            <label class="catalog-filter-chip">
                                <input type="radio" name="condition" value="" @checked(! $condition) data-auto-submit-filter>
                                <span>همه وضعیت‌ها</span>
                            </label>
                            @foreach (\App\Enums\ProductCondition::cases() as $item)
                                <label class="catalog-filter-chip">
                                    <input type="radio" name="condition" value="{{ $item->value }}" @checked($condition === $item->value) data-auto-submit-filter>
                                    <span>{{ $item->label() }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="catalog-sidebar__toggle-row">
                        <label class="catalog-toggle" for="desktop-available">
                            <span class="catalog-toggle__text">فقط کالاهای موجود</span>
                            <input id="desktop-available" type="checkbox" name="available" value="1" @checked($onlyAvailable) data-auto-submit-filter>
                            <span class="catalog-toggle__track" aria-hidden="true"></span>
                        </label>
                    </div>

                    <noscript>
                        <button class="btn btn-primary w-full" type="submit">نمایش نتایج</button>
                    </noscript>
                </form>
            </div>
        </aside>

        <section aria-label="فهرست محصولات" class="catalog-main">
            <h2 class="sr-only">فهرست محصولات</h2>

            {{-- Desktop Inline Sort Bar (>= 1024px) --}}
            <div class="catalog-inline-sort">
                <div class="catalog-inline-sort__tabs" role="navigation" aria-label="مرتب‌سازی محصولات">
                    <span class="catalog-inline-sort__label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
                        مرتب‌سازی:
                    </span>
                    @foreach ($sortOptions as $sortKey => $sortLabel)
                        @php($isCurrentSort = ($sort ?? '') === $sortKey)
                        <a href="{{ route('products.index', array_filter(['q' => $term ?: null, 'category' => $category?->value, 'condition' => $condition, 'available' => $onlyAvailable ? '1' : null, 'sort' => $sortKey ?: null])) }}"
                           class="catalog-inline-sort__tab"
                           @if ($isCurrentSort) aria-current="true" @endif>{{ $sortLabel }}</a>
                    @endforeach
                </div>
                <p class="catalog-count">{{ \App\Support\FaNumber::digits($products->total()) }} محصول یافت شد</p>
            </div>

            <div class="catalog-results">
                @if ($products->isEmpty())
                    @if ($hasFilters)
                        <x-empty-state title="محصولی با این جستجو پیدا نشد"
                            message="عبارت جستجو یا فیلترها را تغییر دهید و دوباره تلاش کنید.">
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
                            <x-product-card :product="$product" :sale="$sales[$product->id]" :quantity="$stock[$product->id] ?? null" :eager="$loop->index < 2" />
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

    {{-- Sort Bottom Sheet --}}
    <dialog class="store-filter-dialog store-sheet-dialog" id="catalog-sort-dialog" data-storefront-dialog aria-labelledby="sort-title">
        <div class="sheet-drag-handle" aria-hidden="true"><span></span></div>
        <header class="overlay-heading"><h2 id="sort-title">مرتب‌سازی محصولات</h2><button type="button" data-storefront-close aria-label="بستن"><x-storefront.icon name="close" /></button></header>
        <nav class="catalog-sort-list" aria-label="ترتیب نمایش">
            @foreach ($sortOptions as $sortKey => $sortLabel)
                @php($isCurrentSort = ($sort ?? '') === $sortKey)
                <a class="catalog-sort-option"
                   href="{{ route('products.index', array_filter(['q' => $term ?: null, 'category' => $category?->value, 'condition' => $condition, 'available' => $onlyAvailable ? '1' : null, 'sort' => $sortKey ?: null])) }}"
                   @if ($isCurrentSort) aria-current="true" @endif>
                    <span>{{ $sortLabel }}</span>
                    @if ($isCurrentSort)
                        <span class="catalog-sort-option__check" aria-hidden="true">✓</span>
                    @endif
                </a>
            @endforeach
        </nav>
    </dialog>

    {{-- Filter Bottom Sheet --}}
    <dialog class="store-filter-dialog store-sheet-dialog" id="catalog-filter-dialog" data-storefront-dialog aria-labelledby="filter-title">
        <div class="sheet-drag-handle" aria-hidden="true"><span></span></div>
        <header class="overlay-heading"><h2 id="filter-title">فیلتر محصولات</h2><button type="button" data-storefront-close aria-label="بستن فیلترها"><x-storefront.icon name="close" /></button></header>
        <form action="{{ route('products.index') }}" method="get" class="catalog-sheet-form">
            @if ($term !== '')<input type="hidden" name="q" value="{{ $term }}">@endif
            @if ($sort)<input type="hidden" name="sort" value="{{ $sort }}">@endif
            <div class="catalog-sheet-body">
                <fieldset class="catalog-filter-fieldset">
                    <legend>دسته‌بندی</legend>
                    <div class="catalog-filter-chips" role="radiogroup" aria-label="دسته‌بندی">
                        <label class="catalog-filter-chip">
                            <input type="radio" name="category" value="" @checked(! $category)>
                            <span>همه محصولات</span>
                        </label>
                        @foreach ($categories as $item)
                            <label class="catalog-filter-chip">
                                <input type="radio" name="category" value="{{ $item->value }}" @checked($category?->value === $item->value)>
                                <span>{{ $item->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <fieldset class="catalog-filter-fieldset">
                    <legend>وضعیت کالا</legend>
                    <div class="catalog-filter-chips" role="radiogroup" aria-label="وضعیت کالا">
                        <label class="catalog-filter-chip">
                            <input type="radio" name="condition" value="" @checked(! $condition)>
                            <span>همه وضعیت‌ها</span>
                        </label>
                        @foreach (\App\Enums\ProductCondition::cases() as $item)
                            <label class="catalog-filter-chip">
                                <input type="radio" name="condition" value="{{ $item->value }}" @checked($condition === $item->value)>
                                <span>{{ $item->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="catalog-sidebar__toggle-row">
                    <label class="catalog-toggle" for="mobile-available">
                        <span class="catalog-toggle__text">فقط کالاهای موجود</span>
                        <input id="mobile-available" type="checkbox" name="available" value="1" @checked($onlyAvailable)>
                        <span class="catalog-toggle__track" aria-hidden="true"></span>
                    </label>
                </div>
            </div>
            <footer class="catalog-sheet-footer">
                <a href="{{ $clearFiltersUrl }}" class="btn btn-secondary">حذف فیلترها</a>
                <button class="btn btn-primary" type="submit">مشاهده نتایج</button>
            </footer>
        </form>
    </dialog>
</div>
@endsection
