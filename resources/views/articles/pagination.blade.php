@if($paginator->hasPages())
<nav class="article-page-links" aria-label="صفحه‌بندی مقاله‌ها">
    @if($paginator->onFirstPage())<span aria-disabled="true">قبلی</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">قبلی</a>@endif
    @foreach($elements as $element)
        @if(is_string($element))<span aria-hidden="true">…</span>@else
            @foreach($element as $page => $url)
                @if($page === $paginator->currentPage())<span aria-current="page" aria-label="صفحهٔ {{ \App\Support\FaNumber::digits($page) }}، صفحهٔ فعلی">{{ \App\Support\FaNumber::digits($page) }}</span>@else<a href="{{ $url }}" aria-label="صفحهٔ {{ \App\Support\FaNumber::digits($page) }}">{{ \App\Support\FaNumber::digits($page) }}</a>@endif
            @endforeach
        @endif
    @endforeach
    @if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">بعدی</a>@else<span aria-disabled="true">بعدی</span>@endif
</nav>
@endif
