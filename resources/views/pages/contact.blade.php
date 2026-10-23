@extends('layouts.app')
@section('title', 'تماس با ما')
@section('meta_description', 'شماره تماس، نشانی و ساعات کاری نوین‌پی در شهریار')
@section('content')
<div class="np-container public-section">
    <x-page-heading title="با نوین‌پی در تماس باشید" eyebrow="تماس با ما" icon="headphones" lead="برای مشاورهٔ خرید، پرسش دربارهٔ تعمیرات یا هماهنگی مراجعهٔ حضوری، از راه‌های ارتباطی زیر استفاده کنید." />
    <div class="public-split">
        <section class="public-panel public-contact-primary" aria-labelledby="phone-title"><span class="public-icon"><x-home.icon name="phone" /></span><h2 id="phone-title">گفت‌وگو با نوین‌پی</h2><x-business-phone class="public-phone" /><p>{{ config('business.hours.open') }}<br>{{ config('business.hours.closed') }}</p><a href="{{ route('repair') }}" class="np-button np-button--primary">ثبت درخواست تعمیر <x-home.icon name="arrow-left" /></a></section>
        <section class="public-panel" aria-labelledby="address-title"><span class="public-icon"><x-home.icon name="map-pin" /></span><h2 id="address-title">مراجعهٔ حضوری</h2><address>{{ config('business.address') }}</address><p class="public-hint">برای انتخاب مسیر، یکی از نقشه‌ها را باز کنید.</p><x-map-links class="public-map-links" /></section>
    </div>
    <div class="public-callout"><div><h2>برای انتخاب دستگاه یا خدمات راهنمایی می‌خواهید؟</h2><p>پرسش خود را ثبت کنید تا همکاران بررسی کنند؛ زمان مشخصی برای پاسخ تضمین نمی‌شود.</p></div><a class="np-button np-button--primary" href="{{ route('consultation') }}">ثبت درخواست مشاوره <x-home.icon name="headphones"/></a></div>
    <div class="public-callout"><div><h2>قبلاً درخواست تعمیر ثبت کرده‌اید؟</h2><p>با کد پیگیری و شمارهٔ همراه ثبت‌شده، وضعیت اولیهٔ درخواست آنلاین را بررسی کنید.</p></div><a class="np-button np-button--outline" href="{{ route('repair.track') }}">پیگیری درخواست <x-home.icon name="arrow-left" /></a></div>
</div>
@endsection
