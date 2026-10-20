@extends('layouts.app')
@section('title', 'درباره ما')
@section('meta_description', 'آشنایی با نوین‌پی؛ فروش و تعمیر کارتخوان در شهریار')
@section('content')
<div class="np-container public-section">
    <x-page-heading title="نوین‌پی؛ همراه کارتخوان شما" eyebrow="درباره ما" icon="diamond" lead="نوین‌پی در شهریار، در زمینهٔ فروش و تعمیر دستگاه‌های کارتخوان فعالیت می‌کند. اینجا می‌توانید محصولات ثبت‌شده را ببینید و درخواست بررسی دستگاه خود را ارسال کنید." />
    <div class="public-split">
        <section class="public-panel public-about-brand"><x-brand-logo /><h2>از انتخاب تا بررسی دستگاه</h2><p>کارتخوان بخشی از کار روزانهٔ کسب‌وکار شماست. برای انتخاب دستگاه نو یا کارکرده و پرسش دربارهٔ تعمیر، با نوین‌پی در ارتباط باشید.</p><div class="public-brand-list" aria-label="برندهای تحت پوشش"><span lang="en">PAX</span><span lang="en">NewPOS</span><span lang="en">Newland</span></div><a href="{{ route('products.index') }}" class="public-inline-link">آشنایی با محصولات <x-home.icon name="arrow-left" /></a></section>
        <section class="public-panel"><span class="public-icon"><x-home.icon name="map-pin" /></span><h2>نوین‌پی در شهریار</h2><address>{{ config('business.address') }}</address><p>{{ config('business.hours.open') }}<br>{{ config('business.hours.closed') }}</p><a class="public-inline-link" href="{{ config('business.phone_uri') }}" dir="ltr">{{ config('business.phone') }} <x-home.icon name="phone" /></a><x-map-links class="public-map-links" /></section>
    </div>
    <div class="public-callout"><div><h2>برای دستگاهتان راهنمایی می‌خواهید؟</h2><p>خدمات را ببینید یا مشکل دستگاه را در فرم درخواست آنلاین بنویسید.</p></div><div class="public-actions"><a class="np-button np-button--primary" href="{{ route('repair') }}">درخواست تعمیر</a><a class="np-button np-button--outline" href="{{ route('services') }}">آشنایی با خدمات</a></div></div>
</div>
@endsection
