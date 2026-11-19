@php($count = app(\App\Services\ShoppingCart::class)->count(request()))
<span data-store-cart-count class="store-cart-count" @if ($count === 0) hidden @endif>
    <span class="sr-only">تعداد کالاها</span><bdi>{{ \App\Support\FaNumber::digits($count) }}</bdi>
</span>
