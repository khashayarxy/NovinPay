@props(['name', 'label'])

@php
    $source = config('homepage.artwork.' . $name);
    $hasImage = is_string($source) && $source !== '' && is_file(public_path($source));
@endphp

<figure {{ $attributes->class(['np-artwork', 'np-artwork--ready' => $hasImage]) }} data-artwork-slot="{{ $name }}">
    @if ($hasImage)
        <img src="{{ asset($source) }}" alt="{{ $label }}" width="1254" height="1254"
            @if ($name === 'hero') fetchpriority="high" @else loading="lazy" @endif decoding="async">
    @else
        <div class="np-artwork__brand"><x-brand-logo /><figcaption>{{ $label }}</figcaption></div>
    @endif
</figure>
