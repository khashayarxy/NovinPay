@extends('layouts.admin')
@section('title', ['intake'=>'رسید پذیرش', 'summary'=>'صورتحساب تعمیر', 'delivery'=>'رسید تحویل'][$document])
@section('content')
@php
    $isThermalReceipt = in_array($document, ['intake', 'delivery'], true);
    $deviceTitle = trim(($case->device_brand?->value ? $case->device_brand->value.' ' : '').$case->device_model);
    $storePhone = config('business.phone') ?: (config('business.phones.0') ?? '');
@endphp
@if($isThermalReceipt)
    <style>@media print { @page { size: 80mm auto; margin: 2mm; } }</style>
@else
    <style>@media print { @page { size: A5 portrait; margin: 10mm; } }</style>
@endif

<div class="repair-print-controls admin-actions">
    <a class="admin-back-link mb-0" href="{{ route('admin.repair-cases.show', $case) }}">← بازگشت به پرونده</a>
    <button class="btn btn-primary" type="button" data-print-document>چاپ {{ $isThermalReceipt ? 'رسید حرارتی (۸۰mm)' : 'صورتحساب' }}</button>
</div>

@if($isThermalReceipt)
    <article class="repair-receipt-80mm">
        <header class="repair-receipt-80mm__head">
            <strong class="repair-receipt-80mm__store">{{ config('business.name') }}</strong>
            <span class="repair-receipt-80mm__sub">فروش و خدمات کارتخوان</span>
            @if($storePhone)<span class="repair-receipt-80mm__phone" dir="ltr">{{ \App\Support\FaNumber::digits($storePhone) }}</span>@endif
            <h1 class="repair-receipt-80mm__title">{{ $document === 'intake' ? 'رسید دریافت دستگاه' : 'رسید تحویل دستگاه' }}</h1>
        </header>
        <div class="repair-receipt-80mm__tracking">
            <span>کد پیگیری</span>
            <strong dir="ltr"><bdi>{{ $case->tracking_reference }}</bdi></strong>
        </div>
        <dl class="repair-receipt-80mm__kv">
            <div><dt>مشتری</dt><dd>{{ $case->customer_name }}</dd></div>
            <div><dt>موبایل</dt><dd dir="ltr"><bdi>{{ $case->contact_phone }}</bdi></dd></div>
            <div><dt>دستگاه</dt><dd>{{ $deviceTitle }}</dd></div>
            <div><dt>سریال</dt><dd dir="ltr">{{ $case->device_serial ?? 'ثبت نشده' }}</dd></div>
            <div><dt>دریافت</dt><dd><x-finance-time :value="$case->received_at" /> · {{ $case->receiver->name }}</dd></div>
            @if($case->accessories)<div><dt>لوازم همراه</dt><dd>{{ $case->accessories }}</dd></div>@endif
        </dl>
        @if($document === 'intake')
            <div class="repair-receipt-80mm__section">
                <p><strong>مشکل گزارش‌شده:</strong> {{ $case->issue }}</p>
                <p><strong>وضعیت ظاهری:</strong> {{ $case->physical_condition }}</p>
            </div>
        @else
            @php($handover = $case->delivery)
            <dl class="repair-receipt-80mm__kv">
                <div><dt>تحویل‌گیرنده</dt><dd>{{ $handover->recipient_name }}</dd></div>
                <div><dt>زمان تحویل</dt><dd><x-finance-time :value="$handover->delivered_at" /></dd></div>
                <div><dt>مسئول ثبت</dt><dd>{{ $handover->actor->name }}</dd></div>
                <div><dt>مبلغ نهایی</dt><dd>{{ $handover->service_cost !== null ? \App\Support\FaNumber::digits(number_format($handover->service_cost + $handover->parts_cost)).' تومان' : 'تعیین نشده' }}</dd></div>
                <div><dt>تأیید تحویل</dt><dd>{{ ['staff_observation'=>'مشاهدات کارمند','message'=>'پیام تحویل‌گیرنده','document'=>'سند قابل ارجاع'][$handover->confirmation_method] ?? 'مدرکی ثبت نشده' }}</dd></div>
            </dl>
            <div class="repair-receipt-80mm__section">
                <p><strong>نتیجه:</strong> {{ $handover->outcome_notes }}</p>
            </div>
        @endif
        <footer class="repair-receipt-80mm__foot">
            <p class="repair-receipt-80mm__note">این سند بر پایهٔ ثبت کارکنان صادر شده است. امضا یا تأیید دیجیتال مشتری در این سامانه دریافت نشده است. هیچ پرداختی با این سند تأیید نمی‌شود.</p>
        </footer>
    </article>
@else
    <article class="repair-formal-invoice">
        <header class="repair-formal-invoice__head">
            <div class="repair-formal-invoice__brand">
                <x-brand-logo />
                <div>
                    <h1>صورتحساب و خلاصهٔ تعمیر</h1>
                    <p>{{ config('business.name') }} · فروش و خدمات کارتخوان</p>
                </div>
            </div>
            <div class="repair-formal-invoice__meta">
                <div><span>کد پیگیری:</span> <strong dir="ltr">{{ $case->tracking_reference }}</strong></div>
                <div><span>تاریخ پذیرش:</span> <strong><x-finance-time :value="$case->received_at" :with-time="false" /></strong></div>
                <div><span>وضعیت:</span> <span class="repair-formal-invoice__badge">{{ $case->status->label() }}</span></div>
            </div>
        </header>
        <div class="repair-formal-invoice__parties">
            <section>
                <h2>مشخصات مشتری</h2>
                <dl>
                    <div><dt>مشتری</dt><dd>{{ $case->customer_name }}</dd></div>
                    <div><dt>موبایل</dt><dd dir="ltr"><bdi>{{ $case->contact_phone }}</bdi></dd></div>
                </dl>
            </section>
            <section>
                <h2>مشخصات دستگاه</h2>
                <dl>
                    <div><dt>دستگاه</dt><dd>{{ $deviceTitle }}</dd></div>
                    <div><dt>سریال</dt><dd dir="ltr">{{ $case->device_serial ?? 'ثبت نشده' }}</dd></div>
                    <div><dt>لوازم همراه</dt><dd>{{ $case->accessories ?: '—' }}</dd></div>
                </dl>
            </section>
        </div>
        <table class="repair-formal-invoice__table">
            <thead><tr><th>شرح خدمات</th><th>مبلغ (تومان)</th></tr></thead>
            <tbody>
                @forelse($case->workEntries as $entry)
                    <tr>
                        <td>
                            <strong>{{ $entry->work_performed }}</strong>
                            <span class="repair-formal-invoice__desc">قطعات: {{ $entry->parts_replaced }}@if($entry->is_completion) · نتیجه: {{ $entry->final_result }}@endif</span>
                        </td>
                        <td class="repair-formal-invoice__amount">—</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="admin-muted">هیچ کار انجام‌شده‌ای ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
            @if($case->currentEstimate)
                <tfoot>
                    <tr><th>خدمات</th><td class="repair-formal-invoice__amount">{{ \App\Support\FaNumber::digits(number_format($case->currentEstimate->service_cost)) }} تومان</td></tr>
                    <tr><th>قطعات</th><td class="repair-formal-invoice__amount">{{ \App\Support\FaNumber::digits(number_format($case->currentEstimate->parts_cost)) }} تومان</td></tr>
                    <tr class="repair-formal-invoice__total-row"><th>جمع کل برآورد</th><td class="repair-formal-invoice__amount">{{ \App\Support\FaNumber::digits(number_format($case->currentEstimate->total())) }} تومان</td></tr>
                </tfoot>
            @endif
        </table>
        <footer class="repair-formal-invoice__signatures">
            <div><span>مهر و امضای فروشگاه ({{ config('business.name') }})</span></div>
            <div><span>امضای مشتری / تحویل‌گیرنده</span></div>
        </footer>
    </article>
@endif
@endsection
