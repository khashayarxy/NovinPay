@props(['secondary' => false, 'both' => false])

@if ($both)
    <div {{ $attributes->class('store-contact-phones') }}>
        <x-business-phone><x-storefront.icon name="phone" /></x-business-phone>
        <x-business-phone secondary><x-storefront.icon name="phone" /></x-business-phone>
    </div>
@else
    <a href="{{ config($secondary ? 'business.secondary_phone_uri' : 'business.phone_uri') }}" dir="ltr" {{ $attributes }}><bdi dir="ltr">{{ \App\Support\FaNumber::digits(config($secondary ? 'business.secondary_phone' : 'business.phone')) }}</bdi>{{ $slot }}</a>
@endif
