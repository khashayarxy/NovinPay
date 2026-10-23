@extends('layouts.app')
@section('content')
<div class="np-container public-section public-articles">
    <x-article-breadcrumbs :category="$category" />
    <header class="articles-heading">
        <p class="articles-eyebrow">دانستنی‌های نوین‌پی</p>
        <h1>{{ $category ? $category->name : 'راهنمای انتخاب و نگهداری کارتخوان' }}</h1>
        <p>مقاله‌های منتشرشده را بخوانید و با آگاهی بیشتری از کارتخوان خود استفاده کنید.</p>
    </header>
    @if($categories->isNotEmpty())
    <nav class="article-categories" aria-label="دسته‌های مقاله">
        <a href="{{ route('articles.index') }}" @if(!$category) aria-current="page" @endif>همهٔ مقاله‌ها</a>
        @foreach($categories as $item)<a href="{{ route('articles.category', ['slug' => $item->slug]) }}" @if($category?->id === $item->id) aria-current="page" @endif>{{ $item->name }}</a>@endforeach
    </nav>
    @endif
    @if($articles->isNotEmpty())
        <p class="articles-count">{{ \App\Support\FaNumber::digits($articles->total()) }} مقالهٔ منتشرشده @if($articles->currentPage() > 1) · صفحهٔ {{ \App\Support\FaNumber::digits($articles->currentPage()) }} @endif</p>
        <div class="public-article-grid">
        @foreach($articles as $article)
            @php($version = $article->publishedVersion)
            <article class="public-article-card">
                <p class="article-category-label">{{ $version->category->name }}</p>
                <h2><a href="{{ route('articles.show', ['slug' => $article->slug]) }}">{{ $version->title }}</a></h2>
                <p>{{ $version->summary }}</p>
                <span class="article-reading-hint" aria-hidden="true">خواندن مقاله <x-home.icon name="arrow-left" /></span>
            </article>
        @endforeach
        </div>
        <div class="article-pagination">{{ $articles->onEachSide(1)->links('articles.pagination') }}</div>
    @else
        <section class="article-empty" aria-labelledby="article-empty-title">
            <x-home.icon name="photo" />
            <h2 id="article-empty-title">هنوز مقاله‌ای در این بخش منتشر نشده است</h2>
            <p>پس از بازبینی و انتشار، راهنماهای نوین‌پی در اینجا نمایش داده می‌شوند.</p>
            @if($category)<a href="{{ route('articles.index') }}" class="public-inline-link">همهٔ مقاله‌ها <x-home.icon name="arrow-left" /></a>@endif
        </section>
    @endif
</div>
@endsection
