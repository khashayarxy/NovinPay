@props(['variant' => 'duotone'])
@php($variant = $variant === 'white' ? 'white' : 'duotone')
<img class="design-logo" src="{{ asset('brand/novinpay-logo-horizontal-'.$variant.'.svg') }}" alt="{{ config('business.name') }}" width="1354" height="384" decoding="async">
