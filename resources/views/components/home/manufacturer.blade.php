@props(['name'])

@php
    $dimensions = ['PAX' => [1220, 410], 'NewPOS' => [656, 187], 'Newland' => [333, 73]];
@endphp

@if (isset($dimensions[$name]))
    <img {{ $attributes->class(['np-manufacturer']) }} src="{{ asset('brand/manufacturers/' . $name . '.svg') }}"
        alt="{{ $name }}" width="{{ $dimensions[$name][0] }}" height="{{ $dimensions[$name][1] }}" decoding="async">
@endif
