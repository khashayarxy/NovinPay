@props(['name', 'label'])

@php
    $source = config('homepage.artwork.' . $name);
    $hasImage = is_string($source) && $source !== '' && is_file(public_path($source));
@endphp

<figure {{ $attributes->class(['np-artwork', 'np-artwork--ready' => $hasImage, 'np-artwork--composition' => ! $hasImage]) }} data-artwork-slot="{{ $name }}" aria-label="{{ $label }}">
    @if ($hasImage)
        <img src="{{ asset($source) }}" alt="{{ $label }}" width="640" height="520"
            @if ($name === 'hero') fetchpriority="high" @else loading="lazy" @endif decoding="async">
    @else
        <div class="np-hero-signature" aria-hidden="true"><span>انتخاب</span><span>راه‌اندازی</span><span>تعمیر</span></div>
    @endif
</figure>
