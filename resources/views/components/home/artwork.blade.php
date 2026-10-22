@props(['name', 'label'])

@php
    $source = config('homepage.artwork.' . $name);
    $hasImage = is_string($source) && $source !== '' && is_file(public_path($source));
@endphp

<figure {{ $attributes->class(['np-artwork', 'np-artwork--ready' => $hasImage, 'np-artwork--composition' => ! $hasImage]) }} data-artwork-slot="{{ $name }}" aria-label="{{ $label }}">
    @if ($hasImage)
        <img src="{{ asset($source) }}" alt="{{ $label }}" width="640" height="520"
            @if ($name === 'hero') fetchpriority="high" @else loading="lazy" @endif decoding="async">
    @elseif ($name === 'hero')
        <div class="np-model-selection">
            <p>مدل‌های قابل ارائه</p>
            <ul class="np-model-selection__cards">
                @foreach ([['Newland', 'ME31'], ['PAX', 'D230'], ['NewPOS', '7210']] as [$brand, $model])
                    <li class="np-model-selection__card"><x-home.manufacturer :name="$brand" /><strong dir="ltr">{{ $model }}</strong><span>کارتخوان {{ $brand }}</span></li>
                @endforeach
            </ul>
        </div>
    @elseif (in_array($name, ['sales', 'repairs'], true))
        <div class="np-model-focus">
            <x-home.manufacturer :name="$name === 'sales' ? 'NewPOS' : 'PAX'" />
            <strong dir="ltr">{{ $name === 'sales' ? '7210' : 'S915' }}</strong>
            <span>{{ $name === 'sales' ? 'انتخاب و راه‌اندازی' : 'بررسی و تعمیر' }}</span>
            <x-home.icon :name="$name === 'sales' ? 'shopping-bag' : 'tool'" />
        </div>
    @else
        <div class="np-support-mark"><x-home.icon name="shield-check" /><p>انتخاب روشن<br><span>پیگیری انسانی</span></p></div>
    @endif
</figure>
