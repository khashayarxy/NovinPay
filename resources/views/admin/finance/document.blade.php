@extends('layouts.admin')
@section('title','فاکتور و تسویه')
@section('content')
@php
    $remainingMinor = $document->charged_minor - $document->paid_minor;
    $isSettled = $remainingMinor <= 0 && $document->charged_minor > 0;
    $settlementKey = $document->settlement();
    $settlementTone = $isSettled ? 'success' : ($remainingMinor > 0 ? 'warning' : 'neutral');
@endphp
@include('admin.finance.heading',['heading'=>($document->sales_order_id?'تسویهٔ سفارش':'مالی تعمیر').' · فاکتور #'.\App\Support\FaNumber::digits($document->id)])

@if(!$valid)
    <div class="admin-notice admin-notice--error" role="alert">مغایرت مالی تشخیص داده شد؛ ثبت عملیات جدید متوقف است.</div>
@endif
@if($sourceCustomerChanged)
    <div class="admin-notice admin-notice--warning" role="status">اتصال مشتری در منبع عملیاتی تغییر کرده است؛ این فاکتور همچنان متعلق به پروفایل زمان صدور است.</div>
@endif

<section class="finance-doc-metrics" aria-label="خلاصه مالی فاکتور">
    <div class="finance-doc-metric">
        <span class="finance-doc-metric__label">مبلغ کل فاکتور</span>
        <strong class="finance-doc-metric__value"><bdi dir="ltr">{{ \App\Support\FinanceMoney::persianToman($document->charged_minor) }}</bdi> <small>تومان</small></strong>
    </div>
    <div class="finance-doc-metric finance-doc-metric--paid">
        <span class="finance-doc-metric__label">پرداخت‌شده</span>
        <strong class="finance-doc-metric__value"><bdi dir="ltr">{{ \App\Support\FinanceMoney::persianToman($document->paid_minor) }}</bdi> <small>تومان</small></strong>
    </div>
    <div @class(['finance-doc-metric', 'finance-doc-metric--settled' => $remainingMinor <= 0, 'finance-doc-metric--due' => $remainingMinor > 0])>
        <div class="finance-doc-metric__head">
            <span class="finance-doc-metric__label">مانده (بدهی)</span>
            <x-admin.status :tone="$settlementTone">{{ \App\Support\FinanceInput::STATES[$settlementKey] }}</x-admin.status>
        </div>
        <strong class="finance-doc-metric__value"><bdi dir="ltr">{{ \App\Support\FinanceMoney::persianToman($remainingMinor) }}</bdi> <small>تومان</small></strong>
    </div>
</section>

<div class="repair-layout mt-4">
    <div class="repair-main-stack">
        <section class="admin-panel admin-collection">
            <div class="admin-collection-tools">
                <h2>تاریخچهٔ پرداخت و اصلاحات</h2>
                @if($customer)
                    <a class="admin-text-link" href="{{ route('admin.finance.customers.show',$customer) }}">گردش مالی مشتری ←</a>
                @endif
            </div>
            <div tabindex="0" role="region" aria-label="تاریخچهٔ پرداخت و اصلاحات" class="admin-table-wrap">
                <table class="admin-table admin-compact-table">
                    <thead>
                        <tr>
                            <th scope="col">شرح عملیات</th>
                            <th scope="col">مبلغ (تومان)</th>
                            <th scope="col">ثبت‌کننده و زمان</th>
                            <th scope="col">جزئیات</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($entries as $entry)
                        @php
                            $displayAmount = 0;
                            $amountTone = 'neutral';
                            if ((int) $entry->receipt_delta !== 0) {
                                $displayAmount = $entry->receipt_delta;
                                $amountTone = $entry->receipt_delta > 0 ? 'paid' : 'due';
                            } elseif ((int) $entry->expense_delta !== 0) {
                                $displayAmount = -$entry->expense_delta;
                                $amountTone = 'due';
                            } else {
                                $displayAmount = $entry->charge_delta;
                                $amountTone = 'neutral';
                            }
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ \App\Support\FinanceInput::KINDS[$entry->kind] }}</strong>
                                <span class="admin-table-meta">{{ \App\Support\FinanceInput::CATEGORIES[$entry->category] }}</span>
                            </td>
                            <td>
                                <strong @class(['finance-amount-cell', 'finance-amount-cell--paid' => $amountTone === 'paid', 'finance-amount-cell--due' => $amountTone === 'due']) dir="ltr">
                                    <bdi>{{ \App\Support\FinanceMoney::persianToman($displayAmount) }}</bdi>
                                </strong>
                            </td>
                            <td>
                                {{ $entry->actor?->name ?? ('همکار '.\App\Support\FaNumber::digits($entry->actor_id)) }}
                                <span class="admin-table-meta"><x-finance-time :value="$entry->created_at"/></span>
                            </td>
                            <td>
                                @if($financeAllows('finance.details'))
                                    <a class="admin-text-link" href="{{ route('admin.finance.entries.show',$entry) }}">جزئیات</a>
                                @else
                                    <span class="admin-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="admin-muted">رویدادی برای این فاکتور ثبت نشده است.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($entries->hasPages())<div class="admin-collection-footer">{{ $entries->links() }}</div>@endif
        </section>
    </div>

    <aside class="repair-action-stack" aria-label="عملیات فاکتور">
        <section class="admin-panel">
            <div class="admin-panel-heading">
                <h2>عملیات فاکتور</h2>
            </div>
            @if($valid && $financeAllows('finance.details'))
                @if($remainingMinor > 0 && $financeAllows('finance.receive'))
                    <div class="mt-3">
                        <a class="btn btn-primary w-full" href="{{ route('admin.finance.receipt.create',['document'=>$document->public_id]) }}">ثبت دریافت وجه</a>
                    </div>
                @elseif($isSettled)
                    <div class="admin-info-card admin-info-card--success mt-3">
                        این فاکتور به‌طور کامل تسویه شده است.
                    </div>
                @endif

                @if($financeAllows('finance.expenses') || $financeAllows('finance.correct') || ($remainingMinor <= 0 && $financeAllows('finance.receive')))
                    <details class="admin-accordion mt-3">
                        <summary>
                            <span>اصلاح مبلغ یا ثبت هزینه</span>
                            <svg class="admin-accordion__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                        </summary>
                        <div class="admin-accordion__body grid gap-2">
                            @if($remainingMinor <= 0 && $financeAllows('finance.receive'))
                                <a class="btn btn-secondary btn-sm justify-start" href="{{ route('admin.finance.receipt.create',['document'=>$document->public_id]) }}">ثبت دریافت دستی</a>
                            @endif
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

        <details class="admin-accordion">
            <summary>
                <span>جزئیات فنی فاکتور</span>
                <svg class="admin-accordion__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
            </summary>
            <div class="admin-accordion__body">
                <dl class="admin-kv-list">
                    <div>
                        <dt>شناسه</dt>
                        <dd>
                            <span class="admin-id-chip" title="{{ $document->public_id }}">#{{ strtoupper(substr((string) $document->public_id, 0, 8)) }}</span>
                            <button type="button" class="admin-copy-mini" data-copy-code="{{ $document->public_id }}"><span data-copy-code-label>کپی</span></button>
                        </dd>
                    </div>
                    <div><dt>مبلغ اولیه</dt><dd><bdi dir="ltr">{{ \App\Support\FinanceMoney::persianToman($document->source_amount_minor) }}</bdi> تومان</dd></div>
                    <div><dt>نسخه منبع / دفتر</dt><dd>{{ \App\Support\FaNumber::digits($document->source_version) }} / {{ \App\Support\FaNumber::digits($document->version) }}</dd></div>
                    <div><dt>تطبیق دفتر</dt><dd>{{ $valid ? 'تأییدشده' : 'دارای مغایرت' }}</dd></div>
                </dl>
            </div>
        </details>
    </aside>
</div>
@endsection
