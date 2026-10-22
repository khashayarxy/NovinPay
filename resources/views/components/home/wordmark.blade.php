@props(['dark' => false])

<img {{ $attributes->class(['np-wordmark']) }}
    src="{{ asset('brand/lockups/logo-wordmark-' . ($dark ? 'white-on-black' : 'black-on-white') . '.svg') }}"
    alt="{{ config('business.name') }}" width="1195" height="236" decoding="async">
