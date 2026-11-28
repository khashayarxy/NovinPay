<div {{ $attributes->class(['repair-handover-panel', 'repair-handover-panel--office']) }}>
    <h3>نشانی مراجعهٔ حضوری نوین‌پی</h3>
    <p class="repair-office-address">{{ config('business.address') }}</p>
    <p class="repair-office-hours">ساعات کاری: <x-business-hours /></p>
    <p class="repair-office-hours">تلفن: <a href="{{ config('business.phone_uri') }}" dir="ltr">{{ \App\Support\FaNumber::digits(config('business.phone')) }}</a></p>
    <div class="repair-inperson-routing">
        <span class="repair-inperson-routing__label">مسیریابی با:</span>
        <nav class="repair-inperson-routing__links" aria-label="مسیریابی مراجعه حضوری">
            @foreach (['نشان' => ['neshan.svg', 16, 16], 'بلد' => ['balad.svg', 16, 16], 'Google Maps' => ['google-maps-2026.png', 13, 17]] as $mapLabel => [$iconFile, $iconW, $iconH])
                <a href="{{ config('business.maps')[$mapLabel] }}" target="_blank" rel="noopener noreferrer">
                    @if ($iconFile === 'google-maps-2026.png' && is_file(public_path('brand/maps/google-maps-2026-28.webp')))
                        <picture class="inline-flex items-center">
                            <source type="image/webp" srcset="{{ asset('brand/maps/google-maps-2026-28.webp') }} 28w, {{ asset('brand/maps/google-maps-2026-56.webp') }} 56w" sizes="14px">
                            <source type="image/avif" srcset="{{ asset('brand/maps/google-maps-2026-28.avif') }} 28w, {{ asset('brand/maps/google-maps-2026-56.avif') }} 56w" sizes="14px">
                            <img src="{{ asset('brand/maps/'.$iconFile) }}" width="{{ $iconW }}" height="{{ $iconH }}" alt="" aria-hidden="true" loading="lazy" decoding="async">
                        </picture>
                    @else
                        <img src="{{ asset('brand/maps/'.$iconFile) }}" width="{{ $iconW }}" height="{{ $iconH }}" alt="" aria-hidden="true" loading="lazy" decoding="async">
                    @endif
                    <span>{{ $mapLabel === 'Google Maps' ? 'گوگل مپ' : $mapLabel }}</span>
                </a>
            @endforeach
        </nav>
    </div>
</div>
