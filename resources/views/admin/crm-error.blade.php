@extends('layouts.admin')
@section('title', 'عملیات ثبت نشد')
@section('content')
@php
    $returnRoute = match (true) {
        request()->is('admin/deliveries*') => [route('admin.deliveries.index'), 'بازگشت به ارسال و تحویل'],
        request()->is('admin/orders*') => [route('admin.orders.index'), 'بازگشت به فهرست سفارش‌ها'],
        request()->is('admin/inventory*') => [route('admin.inventory.index'), 'بازگشت به موجودی انبار'],
        request()->is('admin/payments*') => [route('admin.payments.index'), 'بازگشت به پرداختی‌ها'],
        request()->is('admin/finance*') => [route('admin.finance.index'), 'بازگشت به امور مالی'],
        request()->is('admin/consultations*') => [route('admin.consultations.index'), 'بازگشت به مشاوره‌ها'],
        request()->is('admin/customers*') => [route('admin.customers.index'), 'بازگشت به مشتریان'],
        request()->is('admin/products*') => [route('admin.products.index'), 'بازگشت به محصولات'],
        default => [route('admin.dashboard'), 'بازگشت به داشبورد'],
    };
@endphp
<div class="admin-form-surface">
    <header class="admin-page-heading">
        <div>
            <h1>عملیات انجام نشد</h1>
        </div>
    </header>
    <section class="admin-panel space-y-3" role="alert">
        <div class="admin-info-card admin-info-card--warning">
            <p class="font-semibold">{{ $message }}</p>
        </div>
        <p class="admin-hint">وضعیت پرونده را تازه کنید و پس از بررسی اطلاعات فعلی دوباره اقدام نمایید.</p>
        <div class="admin-actions pt-1">
            <a class="btn btn-primary" href="{{ $returnRoute[0] }}">{{ $returnRoute[1] }}</a>
        </div>
    </section>
</div>
@endsection
