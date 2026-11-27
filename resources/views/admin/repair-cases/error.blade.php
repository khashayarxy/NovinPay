@extends('layouts.admin')
@section('title', 'عملیات ثبت نشد')
@section('content')
<div class="admin-form-surface">
    <header class="admin-page-heading">
        <div>
            <span class="admin-eyebrow">عدم امکان ثبت عملیات</span>
            <h1>عملیات انجام نشد</h1>
        </div>
    </header>
    <section class="admin-panel space-y-3" role="alert">
        <div class="admin-info-card admin-info-card--warning">
            <p class="font-semibold">{{ $message }}</p>
        </div>
        <p class="admin-hint">پرونده را دوباره باز کنید تا آخرین وضعیت آن بارگذاری شود.</p>
        <div class="admin-actions pt-1">
            <a class="btn btn-primary" href="{{ route('admin.repair-cases.index') }}">بازگشت به پرونده‌های تعمیر</a>
        </div>
    </section>
</div>
@endsection
