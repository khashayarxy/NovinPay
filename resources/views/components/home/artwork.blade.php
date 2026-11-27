@props(['name', 'label'])

@php
    $source = config('homepage.artwork.' . $name);
    $hasImage = is_string($source) && $source !== '' && is_file(public_path($source));
    $terminalSizes = '(max-width: 380px) 195px, (max-width: 850px) 215px, 300px';
@endphp

@if ($hasImage)
    <figure {{ $attributes->class(['np-artwork', 'np-artwork--ready']) }} data-artwork-slot="{{ $name }}">
        <picture>
        @if ($source === 'images/home/novinpay-terminal-clean.webp')
            <source type="image/avif" srcset="{{ asset('images/home/novinpay-terminal-clean-320.avif') }} 320w, {{ asset('images/home/novinpay-terminal-clean-400.avif') }} 400w, {{ asset('images/home/novinpay-terminal-clean-480.avif') }} 480w, {{ asset('images/home/novinpay-terminal-clean-640.avif') }} 640w, {{ asset('images/home/novinpay-terminal-clean-853.avif') }} 853w"
                sizes="{{ $terminalSizes }}">
        @endif
        <img src="{{ asset($source) }}" alt="{{ $label }}" width="853" height="933"
            @if ($source === 'images/home/novinpay-terminal-clean.webp')
                srcset="{{ asset('images/home/novinpay-terminal-clean-320.webp') }} 320w, {{ asset('images/home/novinpay-terminal-clean-400.webp') }} 400w, {{ asset('images/home/novinpay-terminal-clean-480.webp') }} 480w, {{ asset('images/home/novinpay-terminal-clean-640.webp') }} 640w, {{ asset($source) }} 853w"
                sizes="{{ $terminalSizes }}"
            @endif
            @if ($name === 'hero') fetchpriority="high" @else loading="lazy" @endif decoding="async">
        </picture>
    </figure>
@endif
