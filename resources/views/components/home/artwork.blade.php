@props(['name', 'label'])

@php
    $source = config('homepage.artwork.' . $name);
    $hasImage = is_string($source) && $source !== '' && is_file(public_path($source));
@endphp

<figure {{ $attributes->class(['np-artwork', 'np-artwork--ready' => $hasImage]) }} data-artwork-slot="{{ $name }}">
    @if ($hasImage)
        <img src="{{ asset($source) }}" alt="{{ $label }}" width="640" height="520"
            @if ($name === 'hero') fetchpriority="high" @else loading="lazy" @endif decoding="async">
    @else
        <div class="np-artwork__placeholder">
            <x-home.icon name="photo" />
            <figcaption>{{ $label }}<span>تصویر در حال آماده‌سازی</span></figcaption>
        </div>
    @endif
</figure>
