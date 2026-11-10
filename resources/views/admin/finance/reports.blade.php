@extends('layouts.admin')
@section('title','گزارش مالی')
@section('content')
@include('admin.finance.heading',['heading'=>'گزارش مالی و تطبیق'])
<section class="admin-panel">@include('admin.finance.filters')</section>
<div class="mt-4">@include('admin.finance.summary')</div>
<section class="admin-panel mt-4"><h2>دفتر دورهٔ انتخاب‌شده</h2>@include('admin.finance.transactions')</section>
@endsection
