@extends('layouts.admin')
@section('title', 'جزئیات درخواست آنلاین')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">نمای فقط‌خواندنی</p><h1>درخواست آنلاین شمارهٔ {{ \App\Support\FaNumber::digits($repair->id) }}</h1><p>ثبت اولیهٔ مشتری؛ دریافت فیزیکی دستگاه تأیید نشده است.</p></div><a href="{{ route('admin.repairs.index') }}" class="admin-text-link">بازگشت به فهرست ←</a></header>
<div class="admin-notice"><h2>{{ $repair->status->label() }}</h2><p>پذیرش، هزینه، تعمیر یا تحویل دستگاه با این درخواست تأیید نمی‌شود.</p></div>
<div class="admin-panel"><h2>اطلاعات درخواست</h2><dl class="admin-definition"><div><dt>برند</dt><dd><bdi>{{ $repair->device_brand->value }}</bdi></dd></div><div><dt>کد پیگیری عمومی</dt><dd class="admin-reference" dir="ltr">{{ $repair->tracking_reference }}</dd></div><div><dt>زمان ثبت (UTC)</dt><dd dir="ltr">{{ \App\Support\FaNumber::digits($repair->created_at->format('Y/m/d H:i')) }}</dd></div></dl></div>
@if($showCustomerData)
<section class="admin-panel mt-6" aria-labelledby="customer-data-title"><h2 id="customer-data-title">اطلاعات مشتری و دستگاه</h2><p class="admin-hint">مشاهدهٔ این اطلاعات در تاریخچهٔ فعالیت ثبت می‌شود.</p><dl class="admin-definition"><div><dt>نام مشتری</dt><dd>{{ $repair->customer_name }}</dd></div><div><dt>شمارهٔ همراه</dt><dd dir="ltr">{{ $repair->contact_phone }}</dd></div><div><dt>مدل اعلام‌شده</dt><dd>{{ $repair->device_model }}</dd></div><div><dt>شرح مشکل</dt><dd class="whitespace-pre-line">{{ $repair->issue }}</dd></div></dl></section>
@else<div class="admin-panel mt-6"><h2>اطلاعات شخصی محدود است</h2><p class="admin-hint">نام، شماره، مدل دستگاه و شرح مشکل به مجوز جداگانه نیاز دارند و برای این حساب نمایش داده نمی‌شوند.</p></div>@endif
@endsection
