@extends('layouts.app')
@section('content')
<div class="np-container public-section public-articles">
    <x-article-breadcrumbs :category="$version->category" :title="$version->title" />
    <article class="public-article-detail" aria-labelledby="article-title">
        <header>
            <a class="article-category-label" href="{{ route('articles.category', ['slug' => $version->category->slug]) }}">{{ $version->category->name }}</a>
            <h1 id="article-title">{{ $version->title }}</h1>
            <p class="article-published-date">انتشار: <time datetime="{{ $article->published_at->toIso8601String() }}">{{ \App\Support\FaNumber::digits($article->published_at->format('Y/m/d')) }} میلادی</time></p>
            <p class="article-summary">{{ $version->summary }}</p>
        </header>
        <x-article-content :blocks="$version->blocks" />
        <footer class="article-detail-footer"><a class="public-inline-link" href="{{ route('articles.index') }}">بازگشت به مقاله‌ها <x-home.icon name="arrow-left" /></a></footer>
    </article>
</div>
@endsection
