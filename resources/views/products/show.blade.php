@extends('layouts.app')
@section('title', e($product->product_details['seo_title'] ?? $product->name))
@section('meta_description', e($product->product_details['seo_description'] ?? \Illuminate\Support\Str::limit(strip_tags($product->summary ?? $product->description ?? ''), 150)))
@section('premium-storefront', '1')

@push('page-head')
    <link rel="stylesheet" href="{{ versioned_asset('css/product-experience.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/storefront-premium.css') }}">
    <script src="{{ versioned_asset('js/product-gallery.js') }}" defer></script>
@endpush

@section('content')
    @php
        $details = $product->product_details ?? [];
        $isTerminal = $product->category === \App\Enums\ProductCategory::PosTerminal;
        $content = $details['content'] ?? [];
        $images = array_values(array_filter([$product->primaryImage(), ...$product->galleryImages()]));
        $specRows = [];
        foreach (\App\Models\Product::SPEC_GROUPS as $key => $heading) {
            $specRows[$key] = \App\Models\Product::specificationRows($details['groups'][$key] ?? null);
        }
        $publicSpecLabel = static fn (string $label) => match ($label) {
            'شبکهٔ سلولی در کاتالوگ', 'شبکه سلولی در کاتالوگ' => 'شبکه‌های ارتباطی مدل',
            'باتری اسمیِ مدل', 'باتری اسمی مدل' => 'باتری مدل (ظرفیت اسمی)',
            'کارت‌خوان در کاتالوگ', 'کارتخوان در کاتالوگ' => 'روش‌های خواندن کارت در این مدل',
            'عرض کاغذ' => 'عرض کاغذ رسید',
            'قطر رول' => 'قطر رول کاغذ',
            default => $label,
        };
        $serviceRows = $specRows['services'];
        unset($specRows['services']);
        $highlights = \App\Models\Product::specificationRows($details['key_features'] ?? null);
        $keyRows = array_slice($highlights ?: [...$specRows['display'], ...$specRows['printer'], ...$specRows['physical']], 0, 10);
        $hasSpecs = $highlights || $product->specifications || array_filter($specRows);

        $knownPrice = \App\Support\ProductSale::priceConfirmed($product);
        $hasDisplayPrice = $knownPrice || ($product->is_sample && $product->price !== null);
        $formattedPrice = $hasDisplayPrice ? \App\Support\FaNumber::digits(number_format($product->price, 0, '.', '٬')) : null;
        $priceDate = $knownPrice ? \App\Support\FinanceTime::persianDate($product->price_changed_at) : null;
        $inquiry = $sale->contactAction() && !$product->is_sample;
        $warranty = $product->warranty_months > 0 ? \App\Support\FaNumber::digits($product->warranty_months).' ماه گارانتی' : null;
        $warrantyProvider = config('business.product_warranty_providers.'.$product->slug);
        $benefits = preg_split('/\R/u', trim($content['advantages'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

        $intro = trim($product->description ?: ($product->summary ?? ''));
        $faq = trim($content['faq'] ?? '');
        $editorial = [];
        foreach ([
            'کاربرد و روش استفاده' => ['uses', 'design', 'printer'],
            'اتصال و شارژدهی' => ['requirements', 'battery'],
            'نکات پیش از خرید' => $canAddToCart || $inquiry ? ['limitations', 'purchase'] : ['limitations'],
            'خدمات پس از خرید' => ['services'],
        ] as $heading => $keys) {
            foreach ($keys as $key) {
                $copy = trim($content[$key] ?? '');
                if ($copy !== '') {
                    $editorial[$heading][] = $copy;
                }
            }
        }

        $sections = array_filter([
            'product-description' => $intro !== '' || $editorial ? 'معرفی محصول' : null,
            'product-specs' => $hasSpecs ? 'مشخصات فنی' : null,
            'product-review' => !$product->is_sample ? 'دیدگاه‌ها' : null,
            'product-faq' => $faq !== '' ? 'پرسش‌های متداول' : null,
            'product-related' => $related->isNotEmpty() ? 'کالاهای مرتبط' : null,
        ]);
        $hasSectionNav = count($sections) > 1;
    @endphp
    <div class="storefront-content">
        <nav class="breadcrumb" aria-label="مسیر صفحه"><a href="{{ route('home') }}">خانه</a><span aria-hidden="true">/</span><a href="{{ route('products.index') }}">فروشگاه</a><span aria-hidden="true">/</span><span aria-current="page">{{ \App\Support\LatinText::html($product->name) }}</span></nav>
        <div class="product-layout">
            @include('products._gallery')
            <div class="product-overview">
                <section class="identity card" aria-labelledby="product-title">
                    <div class="identity-top">
                        <span @class(['used', 'is-new' => $product->condition === \App\Enums\ProductCondition::New])>{{ $product->condition->label() }}</span>
                        @if ($product->isPrivatePaymentTest())
                            <span class="badge badge-sample">خصوصی · تست درگاه</span>
                        @elseif ($product->is_sample)
                            <span class="badge badge-sample">داده نمونه</span>
                        @else
                            <a class="public-inline-link product-compare-link" href="{{ route('products.compare', ['products' => [$product->slug]]) }}"><x-storefront.icon name="compare" /> مقایسهٔ محصول</a>
                        @endif
                    </div>
                    <h1 id="product-title">{{ \App\Support\LatinText::html($product->name) }}</h1>
                    @if ($product->model && !str_contains($product->name, $product->model))<p class="model"><bdi class="np-latin" dir="ltr">{{ $product->model }}</bdi></p>@endif
                    @include('products._colors')
                </section>
                @if ($keyRows)<div class="key-mobile card">@include('products._premium-key-specs')</div>@endif
            </div>
            <div class="purchase-column" data-purchase-column>@include('products._purchase')</div>
            @if ($sections)
                <div class="reading">
                    @if ($hasSectionNav)
                        <nav class="section-nav card" aria-label="بخش‌های محصول" data-product-sections>
                            @foreach ($sections as $id => $label)<a href="#{{ $id }}">{{ $label }}</a>@endforeach
                        </nav>
                    @endif
                    @if (isset($sections['product-description']))
                        <section class="article card product-editorial" id="product-description" aria-labelledby="description-heading">
                            <h2 id="description-heading">معرفی محصول</h2>
                            @if ($intro !== '')
                                <div class="product-editorial-lead">@include('products._paragraphs', ['copy' => $intro])</div>
                            @endif
                            @if ($editorial)
                                <div class="product-editorial-grid">
                                    @foreach ($editorial as $heading => $paragraphs)
                                        <div>
                                            <h3>{{ $heading }}</h3>
                                            @foreach ($paragraphs as $paragraph)
                                                @include('products._paragraphs', ['copy' => $paragraph])
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @endif
                    @if ($hasSpecs)
                        <section class="technical card" id="product-specs" aria-labelledby="specs-heading">
                            <h2 id="specs-heading">مشخصات فنی</h2>
                            @include('products._specs')
                        </section>
                    @endif
                    @unless ($product->is_sample)@include('products._feedback')@endunless
                    @if ($faq !== '')@include('products._faq', ['copy' => $faq])@endif
                </div>
            @endif
        </div>
        @if ($related->isNotEmpty() || ($isTerminal && !$product->is_sample))
            <section class="related card" id="product-related" aria-labelledby="related-heading">
                <h2 id="related-heading">کالاهای مرتبط</h2>
                @if ($related->isNotEmpty())
                    <div class="public-product-grid">@foreach ($related as $item)<x-product-card :product="$item" :sale="$relatedSales[$item->id]" />@endforeach</div>
                @else
                    <p class="related-empty">محصول مشابهی برای نمایش وجود ندارد.</p>
                    <a class="public-inline-link" href="{{ route('products.index') }}">مشاهدهٔ همهٔ محصولات</a>
                @endif
            </section>
        @elseif (!$product->is_sample)
            <div class="mt-4">
                <a class="public-inline-link" href="{{ route('products.index') }}">مشاهدهٔ همهٔ محصولات</a>
            </div>
        @endif
    </div>
    @if ($inquiry || $canAddToCart)
        @php
            $dockMaxQty = $canAddToCart ? \App\Services\ShoppingCart::maxQuantityForOffer($inventory ?? null) : 1;
            $dockCartQty = $canAddToCart ? max(0, (int) ($cartQuantity ?? 0)) : 0;
            $dockRemoveScope = ($cartSeparate ?? false) ? 'hide' : 'device';
        @endphp
        @if ($canAddToCart)
            <div class="np-added-bar" data-added-bar role="status" aria-live="polite" hidden>
                <span class="np-added-bar__text">کالا اضافه شد!</span>
                <div class="np-added-bar__actions">
                    <a class="np-added-bar__link" href="{{ route('cart.index') }}">برو به سبد خرید</a>
                    <button type="button" class="np-added-bar__close" data-added-bar-close aria-label="بستن اعلان">×</button>
                </div>
            </div>
        @endif
        <div class="sticky-contact" data-product-dock inert>
            @if ($canAddToCart)
                <div class="product-dock-action"
                     data-product-cart-control
                     data-product-slug="{{ $product->slug }}"
                     data-product-qty="{{ $dockCartQty }}"
                     data-product-max="{{ $dockMaxQty }}"
                     data-update-url="{{ route('cart.update', $product) }}"
                     data-remove-url="{{ route('cart.remove', $product->slug) }}"
                     data-remove-scope="{{ $dockRemoveScope }}">
                    <button class="primary" type="submit" form="product-cart-form" data-dock-add-btn @if($dockCartQty > 0) hidden @endif><x-storefront.icon name="cart" />افزودن به سبد خرید</button>
                    <div class="np-inline-stepper np-inline-stepper--dock" data-inline-stepper @if($dockCartQty <= 0) hidden @endif>
                        <button type="button" class="np-inline-stepper__btn np-inline-stepper__btn--inc" data-stepper-inc aria-label="افزایش تعداد در سبد خرید" @disabled($dockCartQty >= $dockMaxQty)>+</button>
                        <span class="np-inline-stepper__count" data-stepper-count>{{ \App\Support\FaNumber::digits(max(1, $dockCartQty)) }}</span>
                        <button type="button" class="np-inline-stepper__btn np-inline-stepper__btn--dec" data-stepper-dec data-mode="{{ $dockCartQty <= 1 ? 'remove' : 'decrement' }}" aria-label="{{ $dockCartQty <= 1 ? 'حذف از سبد خرید' : 'کاهش تعداد' }}">
                            <span class="np-inline-stepper__minus" @if($dockCartQty <= 1) hidden @endif>−</span>
                            <span class="np-inline-stepper__trash" @if($dockCartQty > 1) hidden @endif aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                            </span>
                        </button>
                    </div>
                </div>
            @else
                <a class="primary" href="{{ config('business.phone_uri') }}"><x-storefront.icon name="phone" />{{ $sale->label() }}</a>
            @endif
            <p class="price">@if ($formattedPrice !== null)<bdi>{{ $formattedPrice }}</bdi> <small>تومان</small>@else<small>قیمت نیاز به استعلام دارد</small>@endif</p>
        </div>
    @endif
@endsection
