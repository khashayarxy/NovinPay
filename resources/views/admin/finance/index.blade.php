@extends('layouts.admin')
@section('title','تراکنش‌ها و گزارش‌ها')
@section('content')
@include('admin.finance.heading',['heading'=>'تراکنش‌ها و گزارش‌ها'])
<section class="admin-panel">
    <div class="admin-panel-heading">
        <h2>فیلتر دوره و عملیات مالی</h2>
        <div class="admin-actions">
            @if($financeAllows('finance.charge'))
                <a class="btn btn-primary btn-sm" href="{{ route('admin.finance.issue.create') }}">+ صدور فاکتور</a>
            @endif
            @if(($financeAllows('finance.receive') || $financeAllows('finance.expenses')) && $financeAllows('finance.details'))
                <details class="admin-more-menu">
                    <summary>
                        <span>ثبت دریافت / هزینه</span>
                        <svg class="admin-more-menu__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                    </summary>
                    <div class="admin-more-menu__panel">
                        @if($financeAllows('finance.receive'))
                            <a href="{{ route('admin.finance.receipt.create') }}">ثبت دریافت دستی</a>
                        @endif
                        @if($financeAllows('finance.expenses'))
                            <a href="{{ route('admin.finance.expense.create') }}">ثبت هزینه</a>
                        @endif
                    </div>
                </details>
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
