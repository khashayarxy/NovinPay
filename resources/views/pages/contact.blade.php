@extends('layouts.app')
@section('title', 'تماس با ما')
@section('meta_description', 'شماره تماس، نشانی و ساعات کاری نوین‌پی در شهریار')
@section('content')
<div class="np-container public-section">
    <x-page-heading title="با نوین‌پی در تماس باشید" eyebrow="تماس با ما" icon="headphones" lead="برای مشاورهٔ خرید، پیگیری سفارش یا هماهنگی مراجعهٔ حضوری، از راه‌های ارتباطی زیر استفاده کنید." />
    <div class="public-split">
        <section class="public-panel public-contact-primary" aria-labelledby="phone-title">
            <span class="public-icon"><x-home.icon name="phone" /></span>
            <h2 id="phone-title">گفت‌وگو با نوین‌پی</h2>
            <x-business-phones link-class="public-phone" />
            <p><x-business-hours /></p>
        </section>
        <section class="public-panel" aria-labelledby="consultation-title">
            <span class="public-icon"><x-home.icon name="headphones" /></span>
            <h2 id="consultation-title">مشاورهٔ خرید و خدمات</h2>
            <p class="public-hint">برای انتخاب دستگاه مناسب یا راهنمایی خدمات، درخواست مشاوره ثبت کنید تا همکاران ما با شما تماس بگیرند.</p>
            <a class="np-button np-button--primary" href="{{ route('consultation') }}">ثبت درخواست مشاوره <x-home.icon name="arrow-left" /></a>
        </section>
    </div>
</div>
@endsection
