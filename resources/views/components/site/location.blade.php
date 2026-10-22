<section class="np-location" aria-labelledby="location-title">
<div class="np-container np-location__grid">
<div class="np-location__identity"><img src="{{ asset('images/mascots/blue-header.webp') }}" width="1536" height="1024" alt="" loading="lazy" decoding="async"><div><span class="design-eyebrow">همراه کارتخوان شما</span><h2>نوین‌پی در شهریار</h2><p>برای هماهنگی مراجعه، پیش از حرکت با ما تماس بگیرید.</p><a href="{{ config('business.phone_uri') }}" dir="ltr">{{ config('business.phone') }}</a></div></div>
<div class="np-location__copy"><h2 id="location-title"><x-home.icon name="map-pin" />مراجعهٔ حضوری</h2><address>{{ config('business.address') }}</address><p class="np-location__hours">{{ config('business.hours.open') }} · {{ config('business.hours.closed') }}</p><x-map-links class="np-location__maps" /></div>
</div></section>
