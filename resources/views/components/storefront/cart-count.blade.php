@php($count = array_sum(session('store_cart', [])))
<span data-store-cart-count class="store-cart-count" @if ($count === 0) hidden @endif>
    <span class="sr-only">تعداد کالاها</span><bdi>{{ \App\Support\FaNumber::digits($count) }}</bdi>
</span>
