@extends('layouts.admin')
@section('title', $customer ? 'ویرایش مشتری' : 'ثبت مشتری')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">پروفایل مشتری</p><h1>{{ $customer ? 'ویرایش اطلاعات جاری' : 'ثبت مشتری جدید' }}</h1><p>اطلاعات قبلی تعمیرات و مشاوره‌ها تغییر نمی‌کند.</p></div><a class="admin-text-link" href="{{ route('admin.customers.index') }}">بازگشت به مشتریان ←</a></header>
<x-admin.errors/>
<div class="crm-two-column"><section class="admin-panel"><form class="admin-form" method="post" action="{{ $customer ? route('admin.customers.update', $customer) : route('admin.customers.store') }}">@csrf
@if($customer) @method('PUT')<input type="hidden" name="version" value="{{ $customer->version }}">@endif
<x-operation-field name="name" label="نام و نام خانوادگی" :value="$customer?->name ?? ''" :maxlength="100"/>
<x-operation-field name="contact_phone" label="شمارهٔ همراه ایرانی" type="tel" :value="$customer?->contact_phone ?? ''" :maxlength="40"/>
<p class="admin-hint">شماره‌های ۰۹، ‎+۹۸ و ‎۰۰۹۸ با ارقام فارسی یا انگلیسی پذیرفته می‌شوند.</p>
<div><label for="profile-active" class="label">وضعیت پروفایل</label><select id="profile-active" name="is_active" class="input"><option value="1" @selected(!$customer || $customer->is_active)>فعال</option><option value="0" @selected($customer && !$customer->is_active)>بایگانی‌شده</option></select></div>
<x-operation-field name="reason" label="دلیل ثبت یا تغییر؛ یادداشت خصوصی" kind="textarea" :maxlength="1000"/>
<label class="admin-checkbox"><input type="checkbox" name="identity_reviewed" value="1" required> هویت را مستقل از شمارهٔ تأییدنشده بررسی کرده‌ام.</label>
<label class="admin-checkbox"><input type="checkbox" name="duplicate_reviewed" value="1"> در صورت وجود شمارهٔ مشترک، پروفایل‌های قبلی را بررسی کرده‌ام و این شخص هویت مستقلی دارد.</label>
<button class="btn btn-primary" type="submit">ذخیرهٔ پروفایل بررسی‌شده</button></form></section>
<aside class="admin-panel"><span class="admin-icon"><x-home.icon name="shield-check"/></span><h2 class="mt-4">پیش از ساخت پروفایل</h2><p class="admin-hint">با شماره و نام کامل در دفتر مشتریان جستجو کنید. شمارهٔ همراه به‌تنهایی اثبات هویت نیست. برای درخواست تازهٔ همان شخص از پروفایل موجود استفاده کنید.</p><p class="admin-hint">پروفایل بایگانی‌شده حذف نمی‌شود و سوابق آن محفوظ است. ثبت اطلاعات، مجوز تماس تبلیغاتی نیست.</p><a class="admin-text-link" href="{{ route('admin.customers.index') }}">بررسی مشتریان موجود ←</a></aside></div>
@endsection
