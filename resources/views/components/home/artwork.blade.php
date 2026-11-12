@props(['name', 'label'])

@php
    $source = config('homepage.artwork.' . $name);
    $hasImage = is_string($source) && $source !== '' && is_file(public_path($source));
@endphp

@if ($hasImage)
    <figure {{ $attributes->class(['np-artwork', 'np-artwork--ready']) }} data-artwork-slot="{{ $name }}">
        <img src="{{ asset($source) }}" alt="{{ $label }}" width="853" height="933"
            @if ($source === 'images/home/novinpay-terminal-clean.webp')
                srcset="{{ asset('images/home/novinpay-terminal-clean-400.webp') }} 400w, {{ asset('images/home/novinpay-terminal-clean-640.webp') }} 640w, {{ asset($source) }} 853w"
                sizes="(max-width: 360px) 215px, (max-width: 850px) 250px, 400px"
            @endif
            @if ($name === 'hero') fetchpriority="high" @else loading="lazy" @endif decoding="async">
    </figure>
@endif
