@extends('layouts.app')

@section('meta_description', 'فروش دستگاه‌های کارتخوان نو و کارکرده و خدمات تعمیر PAX، NewPOS و Newland در شهریار. مشاوره و استعلام قیمت از نوین‌پی.')

@section('content')
    <section class="np-hero" aria-labelledby="hero-title">
        <div class="np-container np-hero__grid">
            <div class="np-hero__copy">
                <p class="np-eyebrow">به {{ config('business.name') }} خوش آمدید</p>
                <h1 id="hero-title">فروش و تعمیر تخصصی<br><span>کارتخوان</span></h1>
                <p class="np-hero__description">دستگاه‌های کارتخوان نو و کارکرده از برندهای <bdi lang="en">PAX</bdi>، <bdi lang="en">NewPOS</bdi> و <bdi lang="en">Newland</bdi>؛ همراه شما در انتخاب، راه‌اندازی و تعمیر دستگاه.</p>
                <div class="np-hero__actions">
                    <a href="{{ route('products.index') }}" class="np-button np-button--primary"><x-home.icon name="shopping-bag" />مشاهده محصولات<x-home.icon name="arrow-left" /></a>
                    <a href="{{ route('repair') }}" class="np-button np-button--outline"><x-home.icon name="tool" />خدمات تعمیرات<x-home.icon name="arrow-left" /></a>
                </div>
            </div>
            <x-home.artwork name="hero" label="از انتخاب و راه‌اندازی تا تعمیر کارتخوان" class="np-hero__art" />
        </div>
    </section>

    <section class="np-trust np-container" aria-label="همراه شما در خرید و تعمیر">
        @foreach ([['tag', 'مشاورهٔ خرید', 'متناسب با نیاز شما'], ['shield-check', 'شفافیت وضعیت', 'نو یا کارکرده'], ['truck', 'هماهنگی تحویل', 'جزئیات هنگام استعلام'], ['headphones', 'راهنمایی تخصصی', 'برای خرید و تعمیر']] as [$icon, $title, $text])
            <div class="np-trust__item"><x-home.icon :name="$icon" /><h2>{{ $title }}</h2><p>{{ $text }}</p></div>
        @endforeach
    </section>

    <section class="np-container np-services" aria-labelledby="popular-services-title">
        <div class="np-section-heading">
            <div><h2 id="popular-services-title">خدمات پرکاربرد ما</h2><p>برای هر مرحله از کار با کارتخوان، راهی برای راهنمایی شما داریم.</p></div>
            <a href="{{ route('contact') }}" class="np-small-link">راهنمایی خدمات<x-home.icon name="arrow-left" /></a>
        </div>
        <div class="np-services__grid">
            @foreach ([['user', 'انتقال مالکیت', 'راهنمایی و انجام مراحل', 'contact'], ['building-bank', 'تغییر حساب', 'بررسی و راه‌اندازی مجدد', 'contact'], ['printer', 'رفع مشکل پرینتر', 'بررسی چاپ و قطعات', 'repair'], ['power', 'رفع مشکل خاموشی', 'عیب‌یابی دستگاه', 'repair'], ['antenna-bars-5', 'رفع مشکل آنتن‌دهی', 'بررسی ارتباط و آنتن', 'repair'], ['battery-charging', 'رفع مشکل شارژ', 'بررسی باتری و تغذیه', 'repair'], ['settings', 'سایر خدمات', 'راه‌اندازی و پشتیبانی', 'contact']] as [$icon, $title, $text, $destination])
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
                <h2 id="location-title"><x-home.icon name="map-pin" />مراجعهٔ حضوری</h2>
                <address>{{ config('business.address') }}</address>
                <p class="np-location__hours">{{ config('business.hours.open') }}<span> · {{ config('business.hours.closed') }}</span></p>
            </div>
            <div class="np-location__directions">
                <iframe class="np-location__map" src="{{ config('business.map_embed') }}" title="موقعیت نوین‌پی در نقشهٔ نشان" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
                <div class="np-location__maps">
                    @foreach (config('business.maps') as $label => $url)
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"><x-home.icon name="map-pin" />{{ $label }}<span class="sr-only"> (در زبانهٔ جدید باز می‌شود)</span></a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endsection
