@extends('layouts.admin')
@section('title','مالی منبع عملیاتی')
@section('content')
@include('admin.finance.heading',['heading'=>$type==='order'?'تسویهٔ سفارش':'مالی تعمیر'])
<section class="admin-panel admin-form-surface">
    <div class="admin-panel-heading">
        <h2>هنوز فاکتوری صادر نشده است</h2>
        <span class="admin-id-chip" title="{{ $source->public_id }}">#{{ strtoupper(substr((string) $source->public_id, 0, 8)) }}</span>
    </div>
    @if($type === 'repair' && $source->simple_workflow)
        <p>هزینه تعمیر: <strong>{{ \App\Support\AdminRepairMoney::display($source->repair_price_toman) }}</strong></p>
    @endif
    @if($amount !== null)
        <p class="mt-1">مبلغ قابل صدور: <strong><bdi dir="ltr">{{ \App\Support\FinanceMoney::persianToman($amount) }}</bdi> تومان</strong></p>
    @else
        <p class="finance-alert">سفارش یا پرونده تعمیر هنوز شرایط لازم برای صدور فاکتور را ندارد.</p>
    @endif
    @if($financeAllows('finance.charge'))
        <form class="finance-form mt-4" method="post" action="{{ route('admin.finance.issue.store') }}">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="source_type" value="{{ $type }}">
            <input type="hidden" name="source" value="{{ $source->public_id }}">
            <input type="hidden" name="source_version" value="{{ $source->version }}">
            <x-finance-field name="reason" label="توضیح صدور فاکتور (بدون درج شماره کارت)" kind="textarea" :maxlength="1500"/>
            <x-finance-field name="password" label="رمز عبور فعلی" type="password"/>
            <div class="admin-form-footer">
                <button class="btn btn-primary" type="submit">صدور فاکتور</button>
            </div>
        </form>
    @endif
</section>
@endsection
