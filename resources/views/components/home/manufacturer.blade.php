@props(['name'])

@php
    $dimensions = ['PAX' => [1220, 410], 'NewPOS' => [656, 187], 'Newland' => [1100, 295]];
    $versions = ['Newland' => '7edcc7f7a62c'];
@endphp

@if (isset($dimensions[$name]))
    <img {{ $attributes->class(['np-manufacturer']) }} src="{{ asset('brand/manufacturers/' . $name . '.svg') }}{{ isset($versions[$name]) ? '?v='.$versions[$name] : '' }}"
        alt="{{ $name }}" width="{{ $dimensions[$name][0] }}" height="{{ $dimensions[$name][1] }}" decoding="async">
@endif
