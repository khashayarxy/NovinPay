@extends('layouts.admin')
@section('title', 'عملیات ثبت نشد')
@section('content')
<section class="admin-panel" role="alert"><p class="admin-eyebrow">عملیات ثبت نشد · {{ \App\Support\FaNumber::digits($status) }}</p><h1 class="text-2xl font-bold">اطلاعات را دوباره بررسی کنید</h1><p class="mt-4">{{ $message }}</p><p class="admin-hint">رکورد را دوباره از فهرست باز کنید. اطلاعات شخصی فرم بازپخش نمی‌شود؛ نسخهٔ جدید را پیش از ثبت بررسی کنید.</p><div class="admin-actions">@can('customers.view')<a class="btn btn-primary" href="{{ route('admin.customers.index') }}">فهرست مشتریان</a>@endcan @can('consultations.view')<a class="btn btn-secondary" href="{{ route('admin.consultations.index') }}">صندوق مشاوره</a>@endcan</div></section>
@endsection
