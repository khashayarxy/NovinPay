@extends('layouts.admin')
@section('title','نمای مالی')
@section('content')
@include('admin.finance.heading',['heading'=>'نمای مالی کسب‌وکار'])
<section class="admin-panel"><div class="admin-actions">@if($financeAllows('finance.charge'))<a class="btn btn-primary" href="{{ route('admin.finance.issue.create') }}">صدور سند مطالبه</a>@endif @if($financeAllows('finance.receive')) @if($financeAllows('finance.details'))<a class="btn btn-secondary" href="{{ route('admin.finance.receipt.create') }}">ثبت دریافت دستی</a>@endif @endif @if($financeAllows('finance.expenses')) @if($financeAllows('finance.details'))<a class="btn btn-secondary" href="{{ route('admin.finance.expense.create') }}">ثبت هزینه</a>@endif @endif</div><div class="mt-4">@include('admin.finance.filters')</div></section>
<div class="mt-4">@include('admin.finance.summary')</div>
<section class="admin-panel mt-4"><h2>اسناد و ماندهٔ فعلی</h2>@include('admin.finance.documents')</section>
<section class="admin-panel mt-4"><h2>فعالیت مالی دوره</h2>@include('admin.finance.transactions')</section>
@endsection
