<section class="np-location" aria-labelledby="location-title" data-map-preview data-map-url="{{ config('business.map_embed') }}">
    <div class="np-container np-location__grid">
        <div class="np-location__copy">
            <div class="np-location__heading">
                <h2 id="location-title"><x-home.icon name="map-pin" />مراجعهٔ حضوری</h2>
            </div>
            <address id="visit-address">{{ config('business.address') }}</address>
            <p class="np-location__notice"><x-home.icon name="phone" />لطفاً پیش از مراجعه حضوری، تماس بگیرید.</p>
            <p class="np-location__hours"><x-business-hours /></p>
            <nav class="np-location__maps" aria-label="مسیریابی فروشگاه">
                @foreach (['Google Maps' => ['google-maps-2026.png', 14, 18, 'google'], 'نشان' => ['neshan.svg', 16, 16, 'neshan'], 'بلد' => ['balad.svg', 17, 17, 'balad']] as $label => $icon)
                    <a href="{{ config('business.maps')[$label] }}" target="_blank" rel="noopener noreferrer">
                        <img class="np-map-brand-icon np-map-brand-icon--{{ $icon[3] }}" src="{{ asset('brand/maps/'.$icon[0]) }}" width="{{ $icon[1] }}" height="{{ $icon[2] }}" alt="" aria-hidden="true" loading="lazy" decoding="async">
                        <bdi>{{ $label === 'Google Maps' ? 'گوگل مپ' : $label }}</bdi><span class="sr-only"> (در زبانهٔ جدید باز می‌شود)</span>
                    </a>
                @endforeach
            </nav>
        </div>
        <div class="np-location__directions">
            <div class="np-map-preview np-location__map" data-map-viewport>
                <div class="np-map-overlay" data-map-placeholder aria-hidden="true"></div>
                <div class="np-map-preview__action"><button type="button" class="np-button np-button--primary" data-map-activate hidden>فعال‌سازی نقشه</button></div>
                <p class="np-map-status" data-map-status role="status" aria-live="polite"></p>
            </div>
        </div>
    </div>
</section>
