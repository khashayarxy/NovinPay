@extends('layouts.admin')
@section('title', $document === 'intake' ? 'رسید پذیرش' : 'صورتحساب تعمیر')
@section('content')
<div class="repair-print-controls admin-actions"><button class="btn btn-primary" type="button" data-print-document>چاپ</button><a class="admin-text-link" href="{{ route('admin.repair-cases.show', $case) }}">پرونده تعمیر</a></div>
<article class="repair-document admin-panel"><header class="repair-document-header"><x-brand-logo/><h1>{{ $document === 'intake' ? 'رسید پذیرش' : 'صورتحساب تعمیر' }}</h1><p>کد پیگیری: <bdi>{{ \App\Support\FaNumber::digits($case->tracking_reference) }}</bdi></p></header>
<dl class="simple-repair-details"><div><dt>مشتری</dt><dd>{{ $case->customer_name }}</dd></div><div><dt>موبایل</dt><dd><bdi>{{ \App\Support\FaNumber::digits($case->contact_phone) }}</bdi></dd></div><div><dt>دستگاه</dt><dd>{{ $case->device_model }}</dd></div><div><dt>سریال</dt><dd><bdi>{{ $case->device_serial ?? '—' }}</bdi></dd></div><div><dt>زمان پذیرش</dt><dd>{{ \App\Support\FinanceTime::display($case->received_at) }}</dd></div><div><dt>وضعیت</dt><dd>{{ $case->status->label() }}</dd></div></dl>
@if($document === 'intake')<h2>مشکل اعلام‌شده</h2><p>{{ $case->issue }}</p><h2>وضعیت ظاهری</h2><p>{{ $case->physical_condition }}</p>
@else
<dl class="simple-repair-details"><div><dt>هزینه تعمیر</dt><dd>{{ $case->repair_price_toman !== null ? \App\Support\FaNumber::digits(number_format($case->repair_price_toman, 0, '.', '٬')).' تومان' : 'تعیین نشده' }}</dd></div>
@if($invoice)<div><dt>مبلغ سند مالی</dt><dd>{{ \App\Support\FaNumber::digits($invoice->charged_minor % 100 === 0 ? number_format(intdiv($invoice->charged_minor, 100), 0, '.', '٬') : str_replace([',', '.'], ['٬', '٫'], \App\Support\FinanceMoney::display($invoice->charged_minor))) }} تومان</dd></div><div><dt>تسویه</dt><dd>{{ $invoice->charged_minor === $invoice->paid_minor && $invoice->charged_minor > 0 ? 'تسویه‌شده' : 'تسویه نشده' }}</dd></div>@endif
@if($case->settlement_method)<div><dt>روش تسویه</dt><dd>{{ $case->settlement_method === 'online' ? 'اینترنتی' : 'حضوری' }}</dd></div>@endif
<div><dt>روش تحویل</dt><dd>{{ $case->delivery_method === 'shipping' ? 'ارسال' : 'حضوری' }} @if($case->delivery_carrier)· {{ ['post'=>'پست','tipax'=>'تیپاکس','courier'=>'پیک'][$case->delivery_carrier] }}@endif</dd></div></dl>
@if($case->repair_notes)<h2>یادداشت تعمیر</h2><p class="whitespace-pre-line">{{ $case->repair_notes }}</p>@endif
@endif
</article>
@endsection
