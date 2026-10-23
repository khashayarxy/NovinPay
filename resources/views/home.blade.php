@extends('layouts.app')

@section('meta_description', 'فروش دستگاه‌های کارتخوان آکبند و استوک و خدمات تعمیر PAX، NewPOS و Newland در شهریار. مشاوره و استعلام قیمت از نوین‌پی.')

@section('content')
    <section class="np-hero" aria-labelledby="hero-title">
        <div class="np-container np-hero__grid">
            <x-home.artwork name="hero" label="تصویرسازی نمایشی کارتخوان و کارت بانکی؛ نه عکس تأییدشدهٔ محصول" class="np-hero__art" />
            <div class="np-hero__copy">
                <p class="np-eyebrow">به {{ config('business.name') }} خوش آمدید</p>
                <h1 id="hero-title">فروش و تعمیر<br><span>کارتخوان</span></h1>
                <p class="np-hero__description">انواع دستگاه‌های کارتخوان از برندهای معتبر، همراه با راه‌اندازی، پشتیبانی و تعمیر تخصصی.</p>
                <div class="np-hero__actions">
                    <a href="{{ route('products.index') }}" class="np-button np-button--primary"><x-home.icon name="shopping-bag" />مشاهده محصولات<x-home.icon name="arrow-left" /></a>
                    <a href="{{ route('repair') }}" class="np-button np-button--outline"><x-home.icon name="tool" />ثبت درخواست تعمیر<x-home.icon name="arrow-left" /></a>
                </div>
            </div>
        </div>
    </section>

    <section class="np-trust np-container" aria-label="همراه شما در خرید و تعمیر">
        @foreach ([['tag', 'مشاوره تخصصی خرید', 'انتخاب کارتخوان متناسب با نیاز و بودجه شما'], ['shield-check', 'استعلام آنلاین وضعیت تعمیرات', 'با استفاده از کد پیگیری'], ['truck', 'ارسال و تحویل به تمام نقاط', 'در سریع‌ترین زمان ممکن'], ['headphones', 'تعمیرات تخصصی کارتخوان', 'رسیدگی به مشکلات فنی دستگاه']] as [$icon, $title, $text])
            <div class="np-trust__item"><span class="np-trust__icon"><x-home.icon :name="$icon" /></span><h2>{{ $title }}</h2><p>{{ $text }}</p></div>
        @endforeach
    </section>

    <section class="np-container np-services" aria-labelledby="popular-services-title">
        <div class="np-section-heading">
            <div><h2 id="popular-services-title">خدمات کارتخوان نوین‌پی</h2><p>برای هر مرحله از کار با کارتخوان، راهی برای راهنمایی شما داریم.</p></div>
            <a href="{{ route('contact') }}" class="np-small-link">راهنمایی خدمات<x-home.icon name="arrow-left" /></a>
        </div>
        <div class="np-services__grid">
            @foreach ([['user', 'انتقال مالکیت کارتخوان', 'راهنمایی و انجام مراحل', 'contact'], ['building-bank', 'تغییر حساب بانکی', 'بررسی و راه‌اندازی مجدد', 'contact'], ['printer', 'رفع مشکل چاپ رسید', 'بررسی چاپ و قطعات', 'repair'], ['power', 'رفع مشکل روشن‌نشدن', 'عیب‌یابی دستگاه', 'repair'], ['antenna-bars-5', 'رفع مشکل آنتن کارتخوان', 'بررسی ارتباط و آنتن', 'repair'], ['battery-charging', 'رفع مشکل شارژ دستگاه', 'بررسی باتری و تغذیه', 'repair'], ['settings', 'سایر خدمات کارتخوان', 'راه‌اندازی و پشتیبانی', 'contact']] as [$icon, $title, $text, $destination])
                <a href="{{ route($destination) }}" class="np-service"><x-home.icon :name="$icon" /><h3>{{ $title }}</h3><p>{{ $text }}</p></a>
            @endforeach
        </div>
    </section>

    <section class="np-container np-brands" aria-labelledby="brands-title">
        <h2 id="brands-title">برندهای تحت پوشش</h2>
        <p>فروش و تعمیر انواع دستگاه‌های کارتخوان از برندهای زیر</p>
        <div class="np-brands__grid">
            @foreach (['Newland', 'NewPOS', 'PAX'] as $brand)
                <a href="{{ route('products.index', ['q' => $brand]) }}" class="np-brand"><x-home.manufacturer :name="$brand" /><span>مشاهده دستگاه‌ها<x-home.icon name="arrow-left" /></span></a>
            @endforeach
        </div>
    </section>


    <section class="np-location" aria-labelledby="location-title">
        <div class="np-container np-location__grid">
            <div class="np-location__copy">
                <div class="np-location__heading">
                    <h2 id="location-title"><x-home.icon name="map-pin" />مراجعهٔ حضوری</h2>
                    <button type="button" class="np-copy-address" data-copy-address="visit-address" aria-describedby="address-copy-status" hidden>کپی نشانی</button>
                </div>
                <address id="visit-address">{{ config('business.address') }}</address>
                <p class="np-location__hours">{{ config('business.hours.open') }}<span> · {{ config('business.hours.closed') }}</span></p>
                <nav class="np-location__maps" aria-label="مسیریابی فروشگاه">
                    @foreach (['Google Maps', 'نشان', 'بلد'] as $label)
                        <a href="{{ config('business.maps')[$label] }}" target="_blank" rel="noopener noreferrer"><x-home.icon name="map-pin" /><bdi>{{ $label }}</bdi><span class="sr-only"> (در زبانهٔ جدید باز می‌شود)</span></a>
                    @endforeach
                </nav>
                <p id="address-copy-status" class="np-copy-status" role="status" aria-live="polite"></p>
            </div>
            <div class="np-location__directions">
                <iframe class="np-location__map" src="{{ config('business.map_embed') }}" title="موقعیت نوین‌پی در نقشهٔ نشان" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
            </div>
        </div>
    </section>
@endsection
