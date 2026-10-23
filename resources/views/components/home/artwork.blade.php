@props(['name', 'label'])

@php
    $source = config('homepage.artwork.' . $name);
    $hasImage = is_string($source) && $source !== '' && is_file(public_path($source));
@endphp

@if ($hasImage)
    <figure {{ $attributes->class(['np-artwork', 'np-artwork--ready']) }} data-artwork-slot="{{ $name }}">
        <img src="{{ asset($source) }}" alt="{{ $label }}" width="853" height="933"
            @if ($name === 'hero') fetchpriority="high" @else loading="lazy" @endif decoding="async">
    </figure>
@endif
