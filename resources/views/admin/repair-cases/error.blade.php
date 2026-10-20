@extends('layouts.admin')
@section('title', 'عملیات ثبت نشد')
@section('content')
<section class="admin-panel" role="alert"><p class="admin-eyebrow">عملیات ثبت نشد · {{ \App\Support\FaNumber::digits($status) }}</p><h1 class="text-2xl font-bold mt-3">اطلاعات پرونده را دوباره بررسی کنید</h1><p class="mt-4">{{ $message }}</p><p class="admin-hint mt-4">برای جلوگیری از ثبت تکراری یا بازنویسی اطلاعات تازه، پرونده را از فهرست دوباره باز کنید. ورودی‌های شخصی در این صفحه بازپخش نمی‌شوند.</p><a class="btn btn-primary mt-6" href="{{ route('admin.repair-cases.index') }}">بازگشت به پرونده‌ها</a></section>
@endsection
