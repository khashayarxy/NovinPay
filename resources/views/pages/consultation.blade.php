@extends('layouts.app')
@section('title', 'درخواست مشاوره')
@section('meta_description', 'ثبت پرسش دربارهٔ خرید و خدمات کارتخوان در نوین‌پی')
@section('content')
<div class="np-container public-section"><x-page-heading title="پیش از تصمیم، با ما مشورت کنید" eyebrow="مشاورهٔ نوین‌پی" icon="headphones" lead="پرسش خود را دربارهٔ خرید یا خدمات کارتخوان بنویسید تا همکاران ما راهنمایی‌تان کنند."/>
<div class="public-split public-split--form"><section class="public-panel"><div class="public-panel__heading"><span class="public-icon"><x-home.icon name="headphones"/></span><div><h2>ثبت درخواست مشاوره</h2><p>اطلاعات تماس فقط برای پیگیری همین درخواست استفاده می‌شود.</p></div></div><x-form-errors/>
<form class="public-form" method="post" action="{{ route('consultation.store') }}">@csrf<input type="hidden" name="submission_token" value="{{ $token }}"/>
<div class="public-form__grid"><x-operation-field name="customer_name" label="نام و نام خانوادگی" :maxlength="100"/><x-operation-field name="contact_phone" label="شماره موبایل" type="tel" inputmode="numeric" autocomplete="tel" :maxlength="11"/></div>
<div><label class="label" for="consultation-topic">موضوع مشاوره</label><select class="input" id="consultation-topic" name="topic" required><option value="">موضوع را انتخاب کنید</option>@foreach(\App\Models\Consultation::TOPICS as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
<x-operation-field name="device_info" label="مدل یا اطلاعات مرتبط دستگاه (اختیاری)" :required="false" :maxlength="200"/>
<x-operation-field name="question" label="پرسش شما (حداقل ۱۰ کاراکتر)" kind="textarea"/><button class="np-button np-button--primary" type="submit">ثبت درخواست مشاوره <x-home.icon name="arrow-left"/></button></form></section>
<aside class="public-panel"><span class="public-icon"><x-home.icon name="shield-check"/></span><h2 class="mt-4">دسترسی سریع به خدمات</h2><p class="public-hint">برای ثبت خرابی، درخواست تعمیر را باز کنید. برای مقایسه و خرید دستگاه، به فروشگاه بروید.</p><a class="public-inline-link" href="{{ route('products.index') }}">فروشگاه ←</a><a class="public-inline-link" href="{{ route('repair') }}">درخواست آنلاین تعمیر ←</a><a class="public-inline-link" href="{{ route('privacy') }}">حریم خصوصی ←</a><a class="public-inline-link" href="{{ route('contact') }}">راه‌های تماس ←</a></aside></div></div>
@endsection
