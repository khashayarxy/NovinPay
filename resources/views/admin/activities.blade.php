@extends('layouts.admin')
@section('title', 'تاریخچهٔ فعالیت')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">نظارت و امنیت</p><h1>تاریخچهٔ فعالیت مدیریت</h1><p>رویدادهای ثبت‌شده؛ زمان‌ها به UTC نمایش داده می‌شوند. گذرواژه و محتوای اطلاعات مشتری در این فهرست ثبت نمی‌شوند.</p></div></header>
<section class="admin-panel"><ul class="admin-activities"><x-admin.activity-list :activities="$activities" /></ul></section><div class="mt-6">{{ $activities->links() }}</div>
@endsection
