@extends('layouts.app')
@section('title', 'درخواست مشاوره')
@section('meta_description', 'ثبت پرسش دربارهٔ خرید و خدمات کارتخوان در نوین‌پی')
@section('content')
<div class="np-container public-section"><x-page-heading title="پیش از تصمیم، با ما مشورت کنید" eyebrow="مشاورهٔ نوین‌پی" icon="headphones" lead="پرسش خود را دربارهٔ خرید یا خدمات کارتخوان بنویسید. درخواست بررسی می‌شود؛ زمان مشخصی برای پاسخ تضمین نمی‌شود."/>
<div class="public-split public-split--form"><section class="public-panel"><div class="public-panel__heading"><span class="public-icon"><x-home.icon name="headphones"/></span><div><h2>ثبت درخواست مشاوره</h2><p>اطلاعات تماس فقط برای پیگیری همین درخواست استفاده می‌شود.</p></div></div><x-form-errors/>
<form class="public-form" method="post" action="{{ route('consultation.store') }}">@csrf<input type="hidden" name="submission_token" value="{{ $token }}"/>
<div class="public-form__grid"><x-operation-field name="customer_name" label="نام و نام خانوادگی" :maxlength="100"/><x-operation-field name="contact_phone" label="شمارهٔ همراه ایرانی" type="tel" :maxlength="40"/></div>
<div><label class="label" for="consultation-topic">موضوع مشاوره</label><select class="input" id="consultation-topic" name="topic" required><option value="">موضوع را انتخاب کنید</option>@foreach(\App\Models\Consultation::TOPICS as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
<x-operation-field name="device_info" label="مدل یا اطلاعات مرتبط دستگاه (اختیاری)" :required="false" :maxlength="200"/>
<x-operation-field name="question" label="شرح پرسش (حداقل ۱۰ نویسه)" kind="textarea"/><p class="public-hint">گذرواژه، اطلاعات کارت بانکی یا مدارک هویتی ارسال نکنید. ثبت این فرم پذیرش تعمیر یا سفارش خرید نیست.</p><button class="np-button np-button--primary" type="submit">ثبت درخواست مشاوره <x-home.icon name="arrow-left"/></button></form></section>
<aside class="public-panel"><span class="public-icon"><x-home.icon name="shield-check"/></span><h2 class="mt-4">پرسش شما، با پیگیری انسانی</h2><p class="public-hint">ثبت فرم به معنی تأیید مالکیت شماره یا ایجاد حساب کاربری نیست. همکاران مجاز نوین‌پی درخواست را بررسی می‌کنند.</p><p class="public-hint">برای اعلام مشکل دستگاه و دریافت کد پیگیری تعمیر، از فرم مستقل تعمیرات استفاده کنید.</p><a class="public-inline-link" href="{{ route('repair') }}">درخواست آنلاین تعمیر ←</a><a class="public-inline-link" href="{{ route('privacy') }}">حریم خصوصی ←</a><a class="public-inline-link" href="{{ route('contact') }}">راه‌های تماس ←</a></aside></div></div>
@endsection
