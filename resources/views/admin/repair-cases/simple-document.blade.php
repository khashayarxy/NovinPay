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
    $issuedOrSettledAt = $latestPayment?->created_at ?? $invoice?->created_at ?? now();
    $priceCellText = $case->repair_price_toman !== null
        ? \App\Support\FaNumber::digits(str_replace(',', '٬', number_format($case->repair_price_toman)))
        : 'هزینه هنوز ثبت نشده است';
@endphp

@if($isThermalReceipt)
    <style>@media print { @page { size: 80mm auto; margin: 2mm; } }</style>
@else
    <style>@media print { @page { size: A5 portrait; margin: 10mm; } }</style>
@endif

<div @class(['repair-print-controls', 'admin-actions', 'repair-print-controls--thermal' => $isThermalReceipt, 'repair-print-controls--invoice' => !$isThermalReceipt])>
    <a class="admin-back-link mb-0" href="{{ route('admin.repair-cases.show', $case) }}">← بازگشت به پرونده تعمیر</a>
    <button class="btn btn-primary" type="button" data-print-document>چاپ {{ $isThermalReceipt ? 'رسید حرارتی (۸۰mm)' : 'صورتحساب' }}</button>
</div>

@if($isThermalReceipt)
    <article class="repair-receipt-80mm" aria-label="{{ $docTitle }}">
        <header class="repair-receipt-80mm__head">
            <img src="{{ asset('brand/lockups/logo-wordmark-black-on-white.svg') }}" alt="{{ config('business.name') }}" class="repair-print-logo-mono repair-print-logo-mono--thermal">
            <span class="repair-receipt-80mm__sub">مرکز تخصصی فروش و تعمیرات کارتخوان</span>
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

        @if($document === 'intake')
            <footer class="repair-receipt-80mm__foot">
                @if(!empty($qrSvg))
                    <div class="repair-receipt-80mm__qr" data-repair-qr>
                        {!! $qrSvg !!}
                        <span class="repair-receipt-80mm__qr-caption">اسکن برای پیگیری آنلاین تعمیر</span>
                    </div>
                @endif
                <p class="repair-receipt-80mm__note">برای تحویل دستگاه، ارائهٔ این رسید یا کد پیگیری کافی است.</p>
            </footer>
        @else
            <footer class="repair-receipt-80mm__foot">
                <div class="repair-receipt-80mm__signature">
                    <span>امضای تحویل‌گیرنده</span>
                </div>
            </footer>
        @endif
    </article>
@else
    <article class="repair-formal-invoice repair-formal-invoice--mono" aria-label="صورتحساب تعمیر">
        <header class="repair-formal-invoice__head">
            <div class="repair-formal-invoice__brand">
                <img src="{{ asset('brand/lockups/logo-wordmark-black-on-white.svg') }}" alt="{{ config('business.name') }}" class="repair-print-logo-mono repair-print-logo-mono--invoice">
                <div>
                    <h1>صورتحساب خدمات تعمیر</h1>
                    <p>{{ config('business.name') }} · فروش و تعمیرات تخصصی کارتخوان</p>
                </div>
            </div>
            <div class="repair-formal-invoice__meta">
                <div><span>کد پیگیری:</span> <strong dir="ltr"><bdi>{{ $trackingFa }}</bdi></strong></div>
                <div><span>تاریخ پذیرش:</span> <strong><x-finance-time :value="$case->received_at" :with-time="false" /></strong></div>
                <div><span>تاریخ صدور / تسویه:</span> <strong><x-finance-time :value="$issuedOrSettledAt" :with-time="false" /></strong></div>
                <div>
                    <span>وضعیت تسویه:</span>
                    <span class="repair-formal-invoice__badge">{{ $isSettled ? 'تسویه‌شده' : ($invoice ? 'در انتظار تسویه' : $case->status->label()) }}</span>
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
                    <td class="repair-formal-invoice__amount">{{ $priceCellText }}</td>
                </tr>
            </tbody>
            <tfoot>
                @if($invoice)
                    <tr>
                        <th>مبلغ کل فاکتور</th>
                        <td class="repair-formal-invoice__amount">{{ \App\Support\FinanceMoney::persianToman($invoice->charged_minor) }}</td>
                    </tr>
                    <tr class="repair-formal-invoice__total-row">
                        <th>مبلغ پرداخت‌شده</th>
                        <td class="repair-formal-invoice__amount">{{ \App\Support\FinanceMoney::persianToman($invoice->paid_minor) }}</td>
                    </tr>
                @else
                    <tr class="repair-formal-invoice__total-row">
                        <th>جمع کل</th>
                        <td class="repair-formal-invoice__amount">{{ $priceCellText }}</td>
                    </tr>
                @endif
            </tfoot>
        </table>

        @if($case->settlement_method)
            <p class="repair-formal-invoice__settlement-note">روش پرداخت: <strong>{{ $case->settlement_method === 'online' ? 'اینترنتی' : 'حضوری' }}</strong></p>
        @endif

        <footer class="repair-formal-invoice__signatures">
            <div><span>مهر و امضای فروشگاه ({{ config('business.name') }})</span></div>
            <div><span>امضای مشتری / تحویل‌گیرنده</span></div>
        </footer>
    </article>
@endif
@endsection
