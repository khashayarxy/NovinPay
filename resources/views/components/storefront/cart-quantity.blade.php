@props(['product', 'quantity', 'limit', 'proof', 'revision'])
<form method="post" action="{{ route('cart.update', $product) }}" class="commerce-stepper" data-cart-mutation
    data-cart-qty-form data-cart-slug="{{ $product->slug }}" data-cart-qty="{{ $quantity }}" data-cart-limit="{{ $limit }}"
    aria-label="تعداد {{ $product->name }}">
    @csrf
    <x-storefront.cart-context :proof="$proof" :revision="$revision" />
    <button type="submit" name="quantity" value="{{ max(1, min($quantity - 1, $limit)) }}"
        data-cart-step="dec" data-cart-focus="{{ $product->slug }}-minus" aria-label="کاهش تعداد {{ $product->name }}" @disabled($quantity <= 1)>
        <x-storefront.icon name="minus" />
    </button>
    <output class="commerce-stepper__value" aria-label="تعداد فعلی"><bdi data-cart-qty-value>{{ \App\Support\FaNumber::digits($quantity) }}</bdi></output>
    <button type="submit" name="quantity" value="{{ $quantity + 1 }}"
        data-cart-step="inc" data-cart-focus="{{ $product->slug }}-plus" aria-label="افزایش تعداد {{ $product->name }}" @disabled($quantity >= $limit)>
        <x-storefront.icon name="plus" />
    </button>
</form>
