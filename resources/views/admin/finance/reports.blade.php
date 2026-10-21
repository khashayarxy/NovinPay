@extends('layouts.admin')
@section('title','گزارش مالی')
@section('content')
@include('admin.finance.heading',['heading'=>'گزارش مالی و تطبیق'])
<section class="admin-panel">@include('admin.finance.filters')</section>
<div class="mt-4">@include('admin.finance.summary')</div>
<section class="admin-panel mt-4"><h2>دفتر دورهٔ انتخاب‌شده</h2><p class="admin-hint">هر برگشت با علامت مخالف ثبت اولیه نمایش داده می‌شود؛ سابقه حذف یا بازنویسی نمی‌شود.</p>@include('admin.finance.transactions')</section>
@endsection
