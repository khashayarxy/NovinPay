@extends('layouts.app')
@section('title', 'تماس با ما')
@section('meta_description', 'شماره تماس، نشانی و ساعات کاری نوین‌پی در شهریار')
@section('content')
<div class="np-container public-section design-contact-page"><header class="design-contact-heading"><p class="design-eyebrow">تماس با ما</p><h1>ما اینجا هستیم تا پاسخگوی شما باشیم</h1><p>برای مشاورهٔ خرید، پرسش دربارهٔ تعمیرات یا هماهنگی مراجعهٔ حضوری، از راه‌های ارتباطی زیر استفاده کنید.</p></header><x-site.contact-cards/><div class="public-callout"><div><h2>پرسش خود را برای ما ارسال کنید</h2><p>فرم مشاورهٔ اختصاصی، راهی برای ثبت پرسش و اطلاعات تماس شماست.</p></div><a class="np-button np-button--primary" href="{{ route('consultation') }}">ثبت درخواست مشاوره <x-home.icon name="arrow-left"/></a></div><div class="design-track-link"><p>قبلاً درخواست تعمیر ثبت کرده‌اید؟</p><a class="public-inline-link" href="{{ route('repair.track') }}">پیگیری با کد درخواست <x-home.icon name="arrow-left"/></a></div></div><x-site.location/>
@endsection
