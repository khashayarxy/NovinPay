@extends('layouts.admin')
@section('title', match($document) { 'intake' => 'رسید پذیرش', 'delivery' => 'رسید تحویل', default => 'صورتحساب تعمیر' })
@section('content')
@php
    $isThermalReceipt = in_array($document, ['intake', 'delivery'], true);
    $docTitle = match($document) {
        'intake' => 'رسید پذیرش دستگاه',
        'delivery' => 'رسید تحویل دستگاه',
        default => 'صورتحساب تعمیر',
    };
    $trackingFa = \App\Support\FaNumber::digits($case->tracking_reference);
    $deviceTitle = trim(($case->device_brand?->value ? $case->device_brand->value.' ' : '').$case->device_model);
    $deliveryMethodLabel = match($case->delivery_method) {
        'shipping' => 'ارسال'.($case->delivery_carrier ? ' ('.(['post'=>'پست','tipax'=>'تیپاکس','courier'=>'پیک'][$case->delivery_carrier] ?? 'ارسال').')' : ''),
        'in_person' => 'حضوری',
        default => 'مشخص نشده',
    };
    $isSettled = $invoice && $invoice->charged_minor > 0 && $invoice->charged_minor === $invoice->paid_minor;
    $remainingMinor = $invoice ? max(0, $invoice->charged_minor - $invoice->paid_minor) : null;
    $storePhone = config('business.phone') ?: (config('business.phones.0') ?? '');
@endphp

@if($isThermalReceipt)
    <style>@media print { @page { size: 80mm auto; margin: 2mm; } }</style>
@else
    <style>@media print { @page { size: A5 portrait; margin: 10mm; } }</style>
@endif

<div class="repair-print-controls admin-actions">
    <a class="admin-back-link mb-0" href="{{ route('admin.repair-cases.show', $case) }}">← بازگشت به پرونده تعمیر</a>
    <button class="btn btn-primary" type="button" data-print-document>چاپ {{ $isThermalReceipt ? 'رسید حرارتی (۸۰mm)' : 'صورتحساب' }}</button>
</div>

@if($isThermalReceipt)
    <article class="repair-receipt-80mm" aria-label="{{ $docTitle }}">
        <header class="repair-receipt-80mm__head">
            <strong class="repair-receipt-80mm__store">{{ config('business.name') }}</strong>
            <span class="repair-receipt-80mm__sub">مرکز تخصصی فروش و تعمیرات کارتخوان</span>
            @if($storePhone)<span class="repair-receipt-80mm__phone" dir="ltr">{{ \App\Support\FaNumber::digits($storePhone) }}</span>@endif
            <h1 class="repair-receipt-80mm__title">{{ $docTitle }}</h1>
        </header>

        <div class="repair-receipt-80mm__tracking">
            <span>کد پیگیری</span>
            <strong dir="ltr"><bdi>{{ $trackingFa }}</bdi></strong>
        </div>

        <dl class="repair-receipt-80mm__kv">
            <div><dt>تاریخ پذیرش</dt><dd><x-finance-time :value="$case->received_at" /></dd></div>
            <div><dt>مشتری</dt><dd>{{ $case->customer_name }}</dd></div>
            <div><dt>موبایل</dt><dd dir="ltr"><bdi>{{ \App\Support\FaNumber::digits($case->contact_phone) }}</bdi></dd></div>
            <div><dt>دستگاه</dt><dd>{{ $deviceTitle }}</dd></div>
            @if($case->device_serial)
                <div><dt>سریال</dt><dd dir="ltr"><bdi>{{ $case->device_serial }}</bdi></dd></div>
            @endif
            @if($case->accessories)
                <div><dt>لوازم همراه</dt><dd>{{ $case->accessories }}</dd></div>
            @endif
        </dl>

        @if($document === 'intake')
            <div class="repair-receipt-80mm__section">
                <p><strong>شرح مشکل:</strong> {{ $case->issue }}</p>
                <p><strong>وضعیت ظاهری:</strong> {{ $case->physical_condition }}</p>
            </div>
        @else
            <dl class="repair-receipt-80mm__kv">
                <div><dt>هزینه تعمیر</dt><dd><strong>{{ \App\Support\AdminRepairMoney::display($case->repair_price_toman) }}</strong></dd></div>
                @if($invoice)
                    <div><dt>وضعیت تسویه</dt><dd><strong>{{ $isSettled ? 'تسویه‌شده' : 'در انتظار تسویه' }}</strong></dd></div>
                @endif
                @if($case->settlement_method)
                    <div><dt>روش پرداخت</dt><dd>{{ $case->settlement_method === 'online' ? 'اینترنتی' : 'حضوری' }}</dd></div>
                @endif
                <div><dt>روش تحویل</dt><dd>{{ $deliveryMethodLabel }}</dd></div>
                @if($case->delivery)
                    <div><dt>زمان تحویل</dt><dd><x-finance-time :value="$case->delivery->delivered_at" /></dd></div>
                    @if($case->delivery->confirmation_reference)
                        <div><dt>مرجع تحویل</dt><dd dir="ltr"><bdi>{{ $case->delivery->confirmation_reference }}</bdi></dd></div>
                    @endif
                @endif
            </dl>
            @if($case->repair_notes)
                <div class="repair-receipt-80mm__section">
                    <p><strong>توضیحات تعمیر:</strong> {{ $case->repair_notes }}</p>
                </div>
            @endif
        @endif

        <footer class="repair-receipt-80mm__foot">
            <p>پیگیری آنلاین وضعیت تعمیر:</p>
            <p dir="ltr" class="repair-receipt-80mm__url">novinpaypos.com/repair/track</p>
            <p class="repair-receipt-80mm__note">ارائهٔ این رسید یا کد پیگیری هنگام تحویل الزامی است.</p>
        </footer>
    </article>
@else
    <article class="repair-formal-invoice" aria-label="صورتحساب تعمیر">
        <header class="repair-formal-invoice__head">
            <div class="repair-formal-invoice__brand">
                <x-brand-logo />
                <div>
                    <h1>صورتحساب خدمات تعمیر</h1>
                    <p>{{ config('business.name') }} · فروش و تعمیرات تخصصی کارتخوان</p>
                    @if($storePhone)<p class="repair-formal-invoice__phone" dir="ltr">{{ \App\Support\FaNumber::digits($storePhone) }}</p>@endif
                </div>
            </div>
            <div class="repair-formal-invoice__meta">
                <div><span>کد پیگیری:</span> <strong dir="ltr"><bdi>{{ $trackingFa }}</bdi></strong></div>
                <div><span>تاریخ پذیرش:</span> <strong><x-finance-time :value="$case->received_at" :with-time="false" /></strong></div>
                <div>
                    <span>وضعیت تسویه:</span>
                    @if($isSettled)
                        <span class="repair-formal-invoice__badge repair-formal-invoice__badge--paid">تسویه‌شده</span>
                    @elseif($invoice)
                        <span class="repair-formal-invoice__badge repair-formal-invoice__badge--unpaid">در انتظار تسویه</span>
                    @else
                        <span class="repair-formal-invoice__badge">{{ $case->status->label() }}</span>
                    @endif
                </div>
            </div>
        </header>

        <div class="repair-formal-invoice__parties">
            <section>
                <h2>مشخصات مشتری</h2>
                <dl>
                    <div><dt>نام و نام خانوادگی</dt><dd>{{ $case->customer_name }}</dd></div>
                    <div><dt>شماره موبایل</dt><dd dir="ltr"><bdi>{{ \App\Support\FaNumber::digits($case->contact_phone) }}</bdi></dd></div>
                    <div><dt>روش تحویل</dt><dd>{{ $deliveryMethodLabel }}</dd></div>
                </dl>
            </section>
            <section>
                <h2>مشخصات دستگاه</h2>
                <dl>
                    <div><dt>مدل دستگاه</dt><dd>{{ $deviceTitle }}</dd></div>
                    <div><dt>شماره سریال</dt><dd dir="ltr"><bdi>{{ $case->device_serial ?? '—' }}</bdi></dd></div>
                    <div><dt>لوازم همراه</dt><dd>{{ $case->accessories ?: '—' }}</dd></div>
                </dl>
            </section>
        </div>

        <table class="repair-formal-invoice__table">
            <thead>
                <tr>
                    <th>شرح خدمات</th>
                    <th>مبلغ (تومان)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>خدمات تعمیر و عیب‌یابی دستگاه {{ $deviceTitle }}</strong>
                        @if($case->repair_notes)
                            <span class="repair-formal-invoice__desc">{{ $case->repair_notes }}</span>
                        @endif
                    </td>
                    <td class="repair-formal-invoice__amount">{{ \App\Support\AdminRepairMoney::display($case->repair_price_toman) }}</td>
                </tr>
            </tbody>
            <tfoot>
                @if($invoice)
                    <tr>
                        <th>مبلغ کل فاکتور</th>
                        <td class="repair-formal-invoice__amount">{{ \App\Support\FinanceMoney::persianToman($invoice->charged_minor) }} تومان</td>
                    </tr>
                    <tr>
                        <th>مبلغ پرداخت‌شده</th>
                        <td class="repair-formal-invoice__amount">{{ \App\Support\FinanceMoney::persianToman($invoice->paid_minor) }} تومان</td>
                    </tr>
                    <tr class="repair-formal-invoice__total-row">
                        <th>مانده قابل پرداخت</th>
                        <td class="repair-formal-invoice__amount">{{ \App\Support\FinanceMoney::persianToman($remainingMinor) }} تومان</td>
                    </tr>
                @else
                    <tr class="repair-formal-invoice__total-row">
                        <th>جمع کل قابل پرداخت</th>
                        <td class="repair-formal-invoice__amount">{{ \App\Support\AdminRepairMoney::display($case->repair_price_toman) }}</td>
                    </tr>
                @endif
            </tfoot>
        </table>

        @if($case->settlement_method)
            <p class="repair-formal-invoice__settlement-note">روش پرداخت ثبت‌شده: <strong>{{ $case->settlement_method === 'online' ? 'پرداخت آنلاین' : 'پرداخت حضوری' }}</strong></p>
        @endif

        <footer class="repair-formal-invoice__signatures">
            <div>
                <span>مهر و امضای فروشگاه ({{ config('business.name') }})</span>
            </div>
            <div>
                <span>امضای مشتری / تحویل‌گیرنده</span>
            </div>
        </footer>
    </article>
@endif
@endsection
