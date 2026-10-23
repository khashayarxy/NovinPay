@props(['secondary' => false])
<a href="{{ config($secondary ? 'business.secondary_phone_uri' : 'business.phone_uri') }}" dir="ltr" {{ $attributes }}><bdi dir="ltr">{{ \App\Support\FaNumber::digits(config($secondary ? 'business.secondary_phone' : 'business.phone')) }}</bdi>{{ $slot }}</a>
