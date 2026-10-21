@extends('layouts.admin')
@section('title','ماندهٔ مشتری')
@section('content')
@include('admin.finance.heading',['heading'=>'ماندهٔ پروفایل تاریخی مشتری'])
<section class="admin-panel"><h2>@if($financeAllows('customers.contact')){{ $customer->name }}@else پروفایل مشتری @endif</h2><p class="crm-code">{{ $customer->public_id }}</p><p class="admin-hint">تطبیق پروفایل، مانده یا سابقهٔ مالی را منتقل نمی‌کند. این فهرست فقط اسناد صادرشده برای همین هویت تاریخی را نشان می‌دهد.</p>@if(!$customer->is_active || $customer->merged_into_id)<p class="finance-alert">پروفایل بایگانی یا تطبیق یافته است؛ سابقهٔ مالی همچنان مستقل باقی می‌ماند.</p>@endif<dl class="finance-definition">@foreach(['charged'=>'مطالبه','paid'=>'خالص دریافت','outstanding'=>'مانده'] as $key=>$label)<dt>{{ $label }}</dt><dd>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display((string)$totals->$key)) }} تومان</dd>@endforeach</dl></section>
<section class="admin-panel mt-4"><h2>اسناد همین هویت</h2>@include('admin.finance.documents')</section><section class="admin-panel mt-4"><h2>گردش تاریخی مشتری</h2>@include('admin.finance.transactions')</section>
@endsection
