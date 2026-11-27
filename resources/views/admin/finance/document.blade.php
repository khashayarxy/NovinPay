@extends('layouts.admin')
@section('title','سند مالی و تسویه')
@section('content')
@include('admin.finance.heading',['heading'=>($document->sales_order_id?'تسویهٔ سفارش':'مالی تعمیر').' · سند '.\App\Support\FaNumber::digits($document->id)])
@if($sourceCustomerChanged)
    <div class="admin-notice admin-notice--warning" role="status">اتصال مشتری در منبع عملیاتی تغییر کرده است؛ این سند همچنان متعلق به هویت تاریخی زمان صدور است.</div>
@endif
<section class="repair-status-banner">
    <div>
        <span class="admin-eyebrow">وضعیت تسویه</span>
        <h2>{{ \App\Support\FinanceInput::STATES[$document->settlement()] }}</h2>
        <p>{{ $valid ? 'دفتر و ماندهٔ این سند تطبیق دارد.' : 'مغایرت تشخیص داده شد؛ عملیات متوقف است.' }}</p>
    </div>
    <div>
        <p>بدهی باقی‌مانده</p>
        <strong><bdi>{{ \App\Support\FinanceMoney::persianToman($document->charged_minor-$document->paid_minor) }}</bdi> تومان</strong>
    </div>
</section>
<div class="repair-layout mt-4">
    <div class="repair-main-stack">
        <section class="admin-panel">
            <div class="admin-panel-heading">
                <h2>جزئیات فاکتور</h2>
                <div class="admin-id-row mt-0">
                    <span class="admin-id-chip" title="{{ $document->public_id }}">#{{ strtoupper(substr((string) $document->public_id, 0, 8)) }}</span>
                    <button type="button" class="admin-copy-mini" data-copy-code="{{ $document->public_id }}"><span data-copy-code-label>کپی شناسه</span></button>
                </div>
            </div>
            <dl class="finance-definition">
                <dt>مبلغ فاکتور</dt><dd><bdi dir="ltr">{{ \App\Support\FinanceMoney::persianToman($document->charged_minor) }}</bdi> تومان</dd>
                <dt>دریافت‌شده</dt><dd><bdi dir="ltr">{{ \App\Support\FinanceMoney::persianToman($document->paid_minor) }}</bdi> تومان</dd>
                <dt>مبلغ اولیه</dt><dd><bdi dir="ltr">{{ \App\Support\FinanceMoney::persianToman($document->source_amount_minor) }}</bdi> تومان</dd>
                <dt>نسخهٔ منبع / دفتر</dt><dd>{{ \App\Support\FaNumber::digits($document->source_version) }} / {{ \App\Support\FaNumber::digits($document->version) }}</dd>
            </dl>
            @if(!$valid)<p class="finance-alert">مغایرت تشخیص داده شد؛ عملیات متوقف است.</p>@endif
            @if($customer)
                <div class="mt-3 pt-3 border-t border-slate-100">
                    <a class="admin-text-link" href="{{ route('admin.finance.customers.show',$customer) }}">مانده و گردش مالی مشتری ←</a>
                </div>
            @endif
        </section>
        <section class="admin-panel admin-collection">
            <div class="admin-collection-tools"><h2>تاریخچهٔ پرداخت و اصلاحات</h2></div>
            @include('admin.finance.transactions')
        </section>
    </div>
    <aside class="repair-action-stack" aria-label="عملیات مالی سند">
        <section class="admin-panel">
            <h2>عملیات فاکتور</h2>
            <p class="admin-hint">دریافت‌ها و اصلاحات مستقیماً روی همین فاکتور ثبت می‌شوند.</p>
            @if($valid && $financeAllows('finance.details'))
                @if($financeAllows('finance.receive'))
                    <div class="mt-3">
                        <a class="btn btn-primary w-full" href="{{ route('admin.finance.receipt.create',['document'=>$document->public_id]) }}">ثبت دریافت دستی</a>
                    </div>
                @endif
                @if($financeAllows('finance.expenses') || $financeAllows('finance.correct'))
                    <details class="admin-accordion mt-3">
                        <summary>
                            <span>اصلاح مبلغ یا ثبت هزینه</span>
                            <svg class="admin-accordion__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                        </summary>
                        <div class="admin-accordion__body grid gap-2">
                            @if($financeAllows('finance.expenses'))
                                <a class="btn btn-secondary btn-sm justify-start" href="{{ route('admin.finance.expense.create',['document'=>$document->public_id]) }}">ثبت هزینهٔ مرتبط</a>
                            @endif
                            @if($financeAllows('finance.correct'))
                                <a class="btn btn-secondary btn-sm justify-start" href="{{ route('admin.finance.credit.create',['document'=>$document->public_id]) }}">کاهش مبلغ فاکتور</a>
                                <a class="btn btn-secondary btn-sm justify-start" href="{{ route('admin.finance.debit.create',['document'=>$document->public_id]) }}">افزایش مبلغ فاکتور</a>
                            @endif
                        </div>
                    </details>
                @endif
            @endif
        </section>
    </aside>
</div>
@endsection
