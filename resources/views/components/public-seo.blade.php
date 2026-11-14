@props(['canonical' => null, 'contentSeo' => null, 'publicRobots' => null, 'product' => null, 'article' => null, 'version' => null])
@php
    $public = request()->routeIs('home', 'contact', 'products.*', 'articles.*');
    $robots = $contentSeo['robots'] ?? $publicRobots ?? 'index, follow';
    $public = $public && ! str_contains($robots, 'noindex');
    $origin = rtrim(config('app.url'), '/');
    $url = $contentSeo['canonical'] ?? $canonical;
    $title = $contentSeo['title'] ?? html_entity_decode(trim(strip_tags(View::yieldContent('title', config('app.name').' | فروش و تعمیر دستگاه کارتخوان'))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $description = $contentSeo['description'] ?? html_entity_decode(trim(strip_tags(View::yieldContent('meta_description', 'فروش و تعمیر دستگاه‌های کارتخوان (POS) در شهریار'))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $graph = [];
    if ($public && $url) {
        if (request()->routeIs('home', 'contact')) {
            $graph[] = ['@type' => 'Organization', '@id' => $origin.'/#organization', 'name' => config('business.name'), 'url' => $origin.'/', 'telephone' => config('business.phone'), 'address' => config('business.address')];
        }
        if (request()->routeIs('home')) {
            $graph[] = ['@type' => 'Service', 'name' => 'تعمیرات تخصصی کارتخوان', 'serviceType' => 'تعمیر دستگاه کارتخوان', 'url' => $origin.'/', 'provider' => ['@id' => $origin.'/#organization']];
        }
        if (request()->routeIs('products.show') && $product) {
            // Toman prices are not silently converted into ISO currencies. No
            // fabricated offer, stock quantity, review or aggregate rating.
            $graph[] = ['@type' => 'Product', 'name' => $product->name, 'description' => $description, 'url' => $url];
            $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'خانه', 'item' => $origin.'/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'فروشگاه', 'item' => $origin.'/products'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $product->name, 'item' => $url],
            ]];
        }
        if (request()->routeIs('articles.show') && $article && $version) {
            $graph[] = ['@type' => 'Article', 'headline' => $version->title, 'description' => $version->summary, 'url' => $url, 'datePublished' => $article->published_at->toIso8601String()];
        }
    }
@endphp
@if ($public && $url)
    <meta property="og:type" content="{{ request()->routeIs('articles.show') ? 'article' : 'website' }}">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:site_name" content="{{ config('business.name') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $url }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    @if ($graph)
    <script type="application/ld+json">{!! json_encode(['@'.'context' => 'https://schema.org', '@graph' => $graph], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
    @endif
@endif
