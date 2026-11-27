@extends('layouts.admin')
@section('title','ماندهٔ مشتری')
@section('content')
@include('admin.finance.heading',['heading'=>'ماندهٔ پروفایل تاریخی مشتری'])
<section class="admin-panel">
    <div class="admin-panel-heading">
        <h2>@if($financeAllows('customers.contact')){{ $customer->name }}@else پروفایل مشتری @endif</h2>
        <div class="admin-id-row mt-0">
            <span class="admin-id-chip" title="{{ $customer->public_id }}">#{{ strtoupper(substr((string) $customer->public_id, 0, 8)) }}</span>
            <button type="button" class="admin-copy-mini" data-copy-code="{{ $customer->public_id }}"><span data-copy-code-label>کپی شناسه</span></button>
        </div>
    </div>
    @if(!$customer->is_active || $customer->merged_into_id)
        <p class="finance-alert">پروفایل بایگانی یا تطبیق یافته است؛ سابقهٔ مالی همچنان مستقل باقی می‌ماند.</p>
    @endif
    <dl class="admin-finance-summary">
        @foreach(['charged'=>'مطالبه','paid'=>'خالص دریافت','outstanding'=>'مانده'] as $key=>$label)
            <div><dt>{{ $label }}</dt><dd><bdi dir="ltr">{{ \App\Support\FinanceMoney::persianToman((string)$totals->$key) }}</bdi> <small class="admin-muted">تومان</small></dd></div>
        @endforeach
    </dl>
</section>
<section class="admin-panel admin-collection mt-4">
    <div class="admin-collection-tools"><h2>اسناد همین هویت</h2></div>
    @include('admin.finance.documents')
</section>
<section class="admin-panel admin-collection mt-4">
    <div class="admin-collection-tools"><h2>گردش تاریخی مشتری</h2></div>
    @include('admin.finance.transactions')
</section>
@endsection
