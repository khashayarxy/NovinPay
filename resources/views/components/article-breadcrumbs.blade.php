@props(['category' => null, 'title' => null])
<nav aria-label="مسیر صفحه" class="article-breadcrumbs">
    <ol>
        <li><a href="{{ route('home') }}">صفحه اصلی</a></li>
        <li>@if($category || $title)<a href="{{ route('articles.index') }}">مقاله‌ها</a>@else<span aria-current="page">مقاله‌ها</span>@endif</li>
        @if($category)
            <li>@if($title)<a href="{{ route('articles.category', ['slug' => $category->slug]) }}">{{ $category->name }}</a>@else<span aria-current="page">{{ $category->name }}</span>@endif</li>
        @endif
        @if($title)<li><span aria-current="page">{{ $title }}</span></li>@endif
    </ol>
</nav>
