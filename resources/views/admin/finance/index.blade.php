@extends('layouts.admin')
@section('title','تراکنش‌ها و گزارش‌ها')
@section('content')
@include('admin.finance.heading',['heading'=>'تراکنش‌ها و گزارش‌ها'])
<section class="admin-panel">
    <div class="admin-panel-heading">
        <h2>فیلتر دوره و عملیات مالی</h2>
        <div class="admin-actions">
            @if($financeAllows('finance.charge'))
                <a class="btn btn-primary btn-sm" href="{{ route('admin.finance.issue.create') }}">+ صدور سند مطالبه</a>
            @endif
            @if($financeAllows('finance.receive') && $financeAllows('finance.details'))
                <a class="btn btn-secondary btn-sm" href="{{ route('admin.finance.receipt.create') }}">ثبت دریافت دستی</a>
            @endif
            @if($financeAllows('finance.expenses') && $financeAllows('finance.details'))
                <a class="btn btn-secondary btn-sm" href="{{ route('admin.finance.expense.create') }}">ثبت هزینه</a>
            @endif
        </div>
    </div>
    @include('admin.finance.filters')
</section>
<div class="mt-4">@include('admin.finance.summary')</div>
<section class="admin-panel admin-collection mt-4">
    <div class="admin-collection-tools"><h2>اسناد و ماندهٔ فعلی</h2></div>
    @include('admin.finance.documents')
</section>
<section class="admin-panel admin-collection mt-4">
    <div class="admin-collection-tools"><h2>فعالیت مالی دوره</h2></div>
    @include('admin.finance.transactions')
</section>
@endsection
