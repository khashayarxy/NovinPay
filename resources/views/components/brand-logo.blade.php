@props(['variant' => 'blue'])

@php
    $variant = in_array($variant, ['blue', 'white', 'black'], true) ? $variant : 'blue';
@endphp

<span {{ $attributes->class(['inline-flex shrink-0 p-1.5']) }}>
    <img src="{{ asset('brand/novinpay-np-' . $variant . '.svg') }}" alt="{{ config('business.name') }}"
        width="60" height="60" class="h-15 w-15 object-contain">
</span>
