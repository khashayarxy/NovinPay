@extends('layouts.admin')
@section('title', 'عملیات ثبت نشد')
@section('content')
<header class="admin-page-heading"><h1>عملیات ثبت نشد</h1></header><section class="admin-panel admin-form-surface" role="alert"><p>{{ $message }}</p><p class="admin-hint mt-4">برای جلوگیری از ثبت تکراری یا بازنویسی اطلاعات تازه، پرونده را از فهرست دوباره باز کنید. ورودی‌های شخصی در این صفحه بازپخش نمی‌شوند.</p><a class="btn btn-primary mt-6" href="{{ route('admin.repair-cases.index') }}">بازگشت به پرونده‌ها</a></section>
@endsection
