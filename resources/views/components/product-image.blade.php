@props(['product'])

@if ($product->image_path)
    <img src="{{ asset('storage/' . $product->image_path) }}" alt="تصویر {{ $product->name }}" loading="lazy"
        class="h-full w-full object-contain">
@else
    <div class="flex h-full w-full items-center justify-center bg-slate-100" role="img"
        aria-label="تصویری برای {{ $product->name }} ثبت نشده است">
        <svg class="h-2/3 w-2/3 text-slate-300" viewBox="0 0 120 96" fill="none" xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true" focusable="false">
            <rect x="34" y="8" width="52" height="80" rx="8" fill="currentColor" opacity="0.35" />
            <rect x="41" y="16" width="38" height="22" rx="3" fill="white" />
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
    </div>
@endif
