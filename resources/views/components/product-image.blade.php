@props(['product', 'image' => null, 'eager' => false, 'sizes' => '(max-width: 767px) 350px, 420px'])
@php
    $image ??= $product->primaryImage();
    $staticMedia = ($image && ! $product->isPrivatePaymentTest() && $product->is_published && ! $product->is_sample)
        ? \App\Support\ProductMediaPublisher::resolveStatic($image['path'], isset($image['width']) ? (int) $image['width'] : null)
        : null;
@endphp

@if ($image)
    @php($fallbackUrl = route('products.media', basename($image['path'])))
    <img src="{{ $staticMedia['src'] ?? $fallbackUrl }}"
        @if (! empty($staticMedia['srcset'])) srcset="{{ $staticMedia['srcset'] }}" sizes="{{ $sizes }}" @endif
        data-full-src="{{ $staticMedia['full'] ?? $fallbackUrl }}"
        alt="{{ $image['alt'] ?? 'تصویر '.$product->name }}"
        @if (isset($image['width'], $image['height'])) width="{{ $image['width'] }}" height="{{ $image['height'] }}" @endif
        @if ($eager) loading="eager" fetchpriority="high" data-gallery-main @else loading="lazy" decoding="async" @endif
        class="h-full w-full bg-white p-3 object-contain">
@else
    <div class="flex h-full w-full flex-col items-center justify-center gap-1.5 bg-slate-50/80 p-3 text-center" role="img"
        aria-label="تصویری برای {{ $product->name }} ثبت نشده است">
        <svg class="h-14 w-14 text-sky-200" viewBox="0 0 120 96" fill="none" xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true" focusable="false">
            <rect x="34" y="8" width="52" height="80" rx="10" fill="currentColor" stroke="#93c5fd" stroke-width="2" />
            <rect x="41" y="16" width="38" height="22" rx="4" fill="white" />
            <rect x="46" y="45" width="8" height="7" rx="2" fill="white" />
            <rect x="58" y="45" width="8" height="7" rx="2" fill="white" />
            <rect x="70" y="45" width="8" height="7" rx="2" fill="white" />
            <rect x="46" y="57" width="8" height="7" rx="2" fill="white" />
            <rect x="58" y="57" width="8" height="7" rx="2" fill="white" />
            <rect x="70" y="57" width="8" height="7" rx="2" fill="white" />
            <rect x="46" y="69" width="8" height="7" rx="2" fill="white" />
            <rect x="58" y="69" width="8" height="7" rx="2" fill="white" />
            <rect x="70" y="69" width="8" height="7" rx="2" fill="white" />
        </svg>
        <span class="text-[11px] font-medium text-slate-400">بدون تصویر</span>
    </div>
@endif
