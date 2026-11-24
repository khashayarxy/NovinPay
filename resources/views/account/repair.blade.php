@extends('layouts.app')
@section('title', 'پیگیری تعمیر')
@section('content')
<div class="np-account np-account-detail np-container">
    <a class="np-account-link" href="{{ route('account.index', ['section' => 'repairs']) }}">بازگشت به تعمیرات</a>
    <header class="np-account-banner">
        <div>
            @php($deviceLabel = \App\Support\RepairIdentity::deviceLabel($repair->device_brand, $repair->device_model))
            <p>پیگیری تعمیر <bdi dir="ltr">{{ \App\Support\FaNumber::digits($repair->tracking_reference) }}</bdi></p>
            <h1>@if(preg_match('/[A-Za-z]/', $deviceLabel))<bdi class="np-latin-code" dir="ltr">{{ $deviceLabel }}</bdi>@else{{ $deviceLabel }}@endif</h1>
            <p>{{ $case ? \App\Support\CustomerTimeline::repairStatus($repair, $plan) : 'درخواست ثبت شد؛ دستگاه هنوز دریافت نشده است.' }}</p>
        </div>
    </header>
    <div class="np-account-detail-grid">
        <div class="np-account-detail-main">
            <section class="np-account-card">
                <h2>مسیر تعمیر</h2>
                <x-account.timeline :steps="$timeline" />
            </section>
            <section class="np-account-card">
                <h2>مشخصات دستگاه</h2>
                <dl class="np-account-facts">
                    <div><dt>برند</dt><dd>{{ $repair->device_brand?->value ?? 'ثبت نشده' }}</dd></div>
                    <div><dt>{{ $case && !$repair->onlineRequest ? 'تاریخ پذیرش' : 'تاریخ درخواست' }}</dt><dd><x-finance-time :value="$case ? ($repair->onlineRequest?->created_at ?? $repair->received_at) : $repair->created_at" /></dd></div>
                    <div><dt>شرح مشکل</dt><dd class="whitespace-pre-line">{{ $repair->issue }}</dd></div>
                    @if($case && $repair->currentEstimate)
                        <div><dt>برآورد تعمیر</dt><dd>{{ \App\Support\FaNumber::digits(number_format($repair->currentEstimate->total())) }} تومان</dd></div>
                        @if($repair->currentEstimate->customer_summary)
                            <div><dt>نتیجه بررسی و برآورد</dt><dd class="whitespace-pre-line">{{ $repair->currentEstimate->customer_summary }}</dd></div>
                        @endif
                        <div><dt>وضعیت برآورد</dt><dd>{{ ['approved' => 'تأیید شده', 'rejected' => 'رد شده', 'pending' => 'در انتظار پاسخ'][$repair->currentEstimate->decisionStatus()] ?? 'نامشخص' }}</dd></div>
                    @endif
                    <div><dt>هزینه تعمیر</dt><dd>{{ \App\Support\AdminRepairMoney::display($case ? $repair->repair_price_toman : null) }}</dd></div>
                </dl>
            </section>
        </div>
        <div class="np-account-detail-side">
            <x-account.settlement :document="$document" :plan="$plan" :paid-at-display="$paidAtDisplay ?? null" />
        </div>
    </div>
</div>
@endsection
