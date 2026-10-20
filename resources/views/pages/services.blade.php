@extends('layouts.app')
@section('title', 'خدمات')
@section('meta_description', 'خدمات فروش، بررسی و تعمیر کارتخوان PAX، NewPOS و Newland در نوین‌پی شهریار')
@section('content')
<div class="np-container public-section">
    <x-page-heading title="خدمات کارتخوان، در کنار شما" eyebrow="خدمات نوین‌پی" icon="tool" lead="از انتخاب دستگاه تا بررسی مشکل کارتخوان فعلی؛ خدمت مورد نیازتان را انتخاب کنید و برای هماهنگی با ما در تماس باشید." />
    <div class="public-split">
        <section class="public-panel public-feature public-feature--mint"><span class="public-icon"><x-home.icon name="shopping-bag" /></span><h2>فروش دستگاه کارتخوان</h2><p>دستگاه‌های نو و کارکردهٔ <bdi lang="en">PAX</bdi>، <bdi lang="en">NewPOS</bdi> و <bdi lang="en">Newland</bdi> را در کاتالوگ ببینید. وضعیت، مشخصات و قیمت فقط بر اساس اطلاعات ثبت‌شدهٔ هر کالا نمایش داده می‌شود.</p><a class="np-button np-button--primary" href="{{ route('products.index') }}">مشاهدهٔ محصولات <x-home.icon name="arrow-left" /></a></section>
        <section class="public-panel public-feature public-feature--peach"><span class="public-icon"><x-home.icon name="tool" /></span><h2>بررسی و تعمیر کارتخوان</h2><p>برای خاموشی، مشکل شارژ، آنتن‌دهی یا چاپ دستگاه درخواست آنلاین ثبت کنید. ثبت درخواست، تأیید دریافت فیزیکی دستگاه یا شروع تعمیر نیست.</p><a class="np-button np-button--primary" href="{{ route('repair') }}">ثبت درخواست تعمیر <x-home.icon name="arrow-left" /></a></section>
    </div>
    <section class="public-section-block" aria-labelledby="service-list-title"><div class="public-section-title"><div><span>خدمات پرکاربرد</span><h2 id="service-list-title">چه کمکی نیاز دارید؟</h2></div><a href="{{ config('business.phone_uri') }}" class="public-inline-link">مشاورهٔ تلفنی <x-home.icon name="phone" /></a></div>
        <div class="public-service-grid">
            @foreach ([['user', 'انتقال مالکیت', 'راهنمایی دربارهٔ انتقال مالکیت دستگاه'], ['building-bank', 'تغییر حساب', 'بررسی و راهنمایی تنظیم حساب دستگاه'], ['printer', 'مشکل پرینتر', 'بررسی چاپ و قطعات مرتبط'], ['power', 'مشکل خاموشی', 'بررسی روشن‌نشدن دستگاه'], ['antenna-bars-5', 'مشکل آنتن‌دهی', 'بررسی ارتباط و آنتن دستگاه'], ['battery-charging', 'مشکل شارژ', 'بررسی باتری و مسیر شارژ']] as [$icon, $title, $copy])
                <a href="{{ route(in_array($icon, ['user', 'building-bank'], true) ? 'contact' : 'repair') }}" class="public-service"><x-home.icon :name="$icon" /><h3>{{ $title }}</h3><p>{{ $copy }}</p><span>{{ in_array($icon, ['user', 'building-bank'], true) ? 'درخواست راهنمایی' : 'درخواست بررسی' }} ←</span></a>
            @endforeach
        </div>
    </section>
    <div class="public-callout"><div><h2>پیش از مراجعه، هماهنگ کنید</h2><p>هزینه، امکان تعمیر و زمان انجام کار با ثبت فرم تعیین نمی‌شود. برای پرسش دربارهٔ خدمت مورد نیاز با ما تماس بگیرید.</p></div><a href="{{ config('business.phone_uri') }}" class="np-button np-button--outline" dir="ltr">{{ config('business.phone') }} <x-home.icon name="phone" /></a></div>
</div>
@endsection
