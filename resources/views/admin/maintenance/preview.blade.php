@extends('layouts.admin')
@section('title', 'پیش‌نمایش صفحهٔ نگهداری')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">پیش‌نمایش خصوصی</p><h1>پیش‌نمایش صفحهٔ نگهداری</h1><p>فقط پیش‌نمایش است؛ وضعیت سایت و داده‌های کسب‌وکار تغییر نمی‌کنند.</p></div><a class="btn btn-secondary" href="{{ route('admin.maintenance.index') }}">بازگشت به کنترل نگهداری</a></header>
<div class="admin-notice" role="status">پیش‌نمایش، حالت نگهداری را فعال نمی‌کند. محتوای داخل قاب همان طراحی تأییدشده است؛ تعامل‌ها در این قاب محدود شده‌اند.</div>
<iframe src="{{ route('admin.maintenance.preview.page') }}" title="پیش‌نمایش خصوصی صفحهٔ نگهداری نوین‌پی" sandbox="" referrerpolicy="no-referrer" style="display:block;width:100%;height:min(1000px,100svh);border:0;border-radius:16px"></iframe>
@endsection
