<a href="{{ config('business.phone_uri') }}" dir="ltr" {{ $attributes }}><bdi dir="ltr">{{ \App\Support\FaNumber::digits(config('business.phone')) }}</bdi>{{ $slot }}</a>
