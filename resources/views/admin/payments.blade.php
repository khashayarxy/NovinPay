@extends('layouts.admin')
@section('title', 'پرداختی‌ها')
@section('content')
<header class="admin-page-heading"><h1>پرداختی‌ها</h1></header><x-admin.errors />

@forelse($receipts as $receipt)
    @php
        $ctx = $docContext[$receipt->financial_document_id] ?? null;
    @endphp
    <section class="admin-panel mb-4">
        <div class="admin-panel-heading">
            <div>
                <h2>رسید در انتظار بررسی @if($ctx)· {{ $ctx['title'] }}@endif</h2>
                @if($ctx && $ctx['customer'])<p class="admin-table-meta">مشتری: {{ $ctx['customer'] }}</p>@endif
            </div>
            <strong>{{ \App\Support\FinanceMoney::persianToman($receipt->amount_minor) }} تومان</strong>
        </div>
        @can('finance.receive') @can('finance.details')
            <div class="admin-actions mt-3">
                <a class="btn btn-secondary btn-sm" href="{{ route('admin.payments.image', $receipt->public_id) }}">مشاهدهٔ تصویر خصوصی</a>
                @if($ctx)<a class="admin-text-link" href="{{ $ctx['document_url'] }}">مشاهدهٔ سند مالی ←</a>@endif
            </div>
            <form class="mt-4 space-y-4" method="post" action="{{ route('admin.payments.review', $receipt->public_id) }}">
                @csrf
                <label class="label" for="review-{{ $receipt->id }}">نتیجهٔ بررسی</label>
                <select class="input" id="review-{{ $receipt->id }}" name="decision">
                    <option value="reject">رد رسید</option>
                    <option value="approve">تأیید وجه واقعاً دریافت‌شده</option>
                </select>
                <x-operation-field name="amount" inputmode="decimal" label="مبلغ واقعی دریافت‌شده (تومان؛ برای تأیید)" :required="false"/>
                <x-operation-field name="reference" label="مرجع واقعی واریز (برای تأیید)" :required="false"/>
                <x-operation-field name="note" label="توضیح نتیجهٔ بررسی" kind="textarea" :maxlength="1000"/>
                <x-operation-field name="password" label="رمز عبور فعلی شما" type="password"/>
                <label class="admin-checkbox"><input type="checkbox" name="funds_confirmed" value="1"> دریافت واقعی وجه را مستقل از تصویر رسید بررسی و تأیید کردم.</label>
                <button class="btn btn-primary">ثبت نتیجه</button>
            </form>
        @endcan @endcan
    </section>
@empty
    <x-admin.empty-state title="رسیدی در انتظار بررسی نیست" icon="building-bank" class="admin-panel mb-4">رسیدهای بانکی ارسال‌شده توسط مشتریان در این بخش نمایش داده می‌شوند.</x-admin.empty-state>
@endforelse
{{ $receipts->links() }}

<section class="admin-panel mt-3">
    <div class="admin-panel-heading"><h2>مطالبات مشتریان برای بازپرداخت</h2></div>
    @if($credits->isEmpty())
        <p class="admin-hint mt-2">طلب بازی برای بازپرداخت ثبت نشده است.</p>
    @else
        <div class="admin-payment-grid">
            @foreach($credits as $credit)
                @php
                    $ctx = $docContext[$credit->document_id] ?? null;
                @endphp
                <article class="admin-payment-card">
                    <div class="admin-payment-card__head">
                        <span class="admin-payment-card__title">{{ $ctx['title'] ?? 'سند مالی' }}</span>
                        <x-admin.status tone="warning">بستانکاری باز</x-admin.status>
                    </div>
                    @if($ctx && $ctx['customer'])<p class="admin-payment-card__meta">مشتری: {{ $ctx['customer'] }}</p>@endif
                    <div class="admin-payment-card__amount">{{ \App\Support\FinanceMoney::persianToman($credit->balance) }} تومان</div>
                    @if($ctx)<a class="admin-text-link" href="{{ $ctx['document_url'] }}">مشاهدهٔ سند مالی ←</a>@endif
                </article>
            @endforeach
        </div>
    @endif
    {{ $credits->links() }}
</section>

<section class="admin-panel mt-3">
    <div class="admin-panel-heading"><h2>پرداخت‌های نیازمند بررسی</h2></div>
    @if($attempts->isEmpty())
        <p class="admin-hint mt-2">موردی برای بررسی ثبت نشده است.</p>
    @else
        <div class="admin-payment-grid">
            @foreach($attempts as $attempt)
                @php
                    $ctx = $docContext[$attempt->financial_document_id] ?? null;
                    $stateLabels = [
                        'review' => ['نیازمند بررسی', 'warning'],
                        'uncertain' => ['نتیجه نامشخص', 'warning'],
                        'requesting' => ['در حال ثبت', 'info'],
                        'pending' => ['در انتظار درگاه', 'info'],
                        'processing' => ['در حال پردازش', 'info'],
                        'verified' => ['تأییدشده', 'success'],
                    ];
                    [$stateLabel, $stateTone] = $stateLabels[$attempt->state] ?? [$attempt->state, 'neutral'];
                @endphp
                <article class="admin-payment-card">
                    <div class="admin-payment-card__head">
                        <span class="admin-payment-card__title">
                            {{ $ctx['title'] ?? ('پرداخت #'.\App\Support\FaNumber::digits($attempt->id)) }}
                            @if($ctx && $ctx['customer']) · {{ $ctx['customer'] }}@endif
                        </span>
                        <x-admin.status :tone="$stateTone">{{ $stateLabel }}</x-admin.status>
                    </div>
                    <div class="admin-payment-card__amount">{{ \App\Support\FinanceMoney::persianToman($attempt->amount_minor) }} تومان</div>
                    <div class="admin-payment-card__meta">
                        <span>تاریخ: <x-finance-time :value="$attempt->created_at" /></span>
                        @if($attempt->track_id)<span>کد پیگیری: <bdi class="np-latin" dir="ltr">{{ $attempt->track_id }}</bdi></span>@endif
                    </div>
                    <div class="admin-payment-card__actions">
                        @if($ctx)<a class="admin-text-link" href="{{ $ctx['document_url'] }}">مشاهدهٔ سند ←</a>@endif
                        @can('finance.correct') @can('finance.details')
                            @if(in_array($attempt->state, ['review', 'uncertain']) && !$attempt->resolved_at)
                                <button type="button" class="btn btn-secondary btn-sm" data-toggle-panel="attempt-resolve-{{ $attempt->id }}" aria-expanded="false" aria-controls="attempt-resolve-{{ $attempt->id }}">ثبت نتیجهٔ بررسی</button>
                            @endif
                        @endcan @endcan
                    </div>
                    @can('finance.correct') @can('finance.details')
                        @if(in_array($attempt->state, ['review', 'uncertain']) && !$attempt->resolved_at)
                            <div id="attempt-resolve-{{ $attempt->id }}" hidden>
                                <form class="mt-2 space-y-3" method="post" action="{{ route('admin.payments.attempt.resolve', $attempt->public_id) }}">
                                    @csrf
                                    <x-operation-field name="note" label="مرجع و نتیجهٔ بررسی درگاه یا بانک" kind="textarea" :maxlength="1000"/>
                                    <x-operation-field name="password" label="رمز عبور فعلی شما" type="password"/>
                                    <label class="admin-checkbox"><input type="checkbox" name="unpaid_confirmed" value="1" required> با بررسی مستقل درگاه یا بانک، پرداخت‌نشدن این تلاش را تأیید می‌کنم.</label>
                                    <p class="admin-hint">این عملیات دریافت وجه ثبت نمی‌کند. تأیید دیرهنگام درگاه همچنان بررسی می‌شود.</p>
                                    <button class="btn btn-secondary" type="submit">ثبت پرداخت‌نشدن</button>
                                </form>
                            </div>
                        @endif
                    @endcan @endcan
                </article>
            @endforeach
        </div>
    @endif
    {{ $attempts->links() }}
</section>

@can('finance.receive')
<section class="admin-panel admin-form-surface mt-3">
    <h2>ثبت دریافت در فروشگاه</h2>
    <form class="admin-form mt-3" method="post" action="{{ route('admin.payments.store') }}">
        @csrf
        <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
        <x-operation-field name="document" label="شناسهٔ سند مالی" :maxlength="36"/>
        <x-operation-field name="amount" inputmode="decimal" label="مبلغ دریافت‌شده (تومان)"/>
        <x-operation-field name="password" label="رمز عبور فعلی شما" type="password"/>
        <label class="admin-checkbox"><input type="checkbox" name="funds_confirmed" value="1" required> وجه واقعاً دریافت شده است.</label>
        <button class="btn btn-primary">ثبت دریافت وجه</button>
    </form>
</section>
@endcan

@can('finance.correct')
<section class="admin-panel admin-form-surface mt-3">
    <div class="admin-panel-heading">
        <h2>بازپرداخت طلب مشتری</h2>
        <button type="button" class="btn btn-secondary btn-sm" data-toggle-panel="admin-refund-panel" aria-expanded="false" aria-controls="admin-refund-panel">ثبت بازپرداخت واقعی طلب مشتری</button>
    </div>
    <div id="admin-refund-panel" class="mt-3" hidden>
        <form class="admin-form" method="post" action="{{ route('admin.payments.refund') }}">
            @csrf
            <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            <x-operation-field name="document" label="شناسهٔ سند مالی" :maxlength="36"/>
            <x-operation-field name="amount" inputmode="decimal" label="مبلغ بازپرداخت (تومان)"/>
            <x-operation-field name="reference" label="مرجع واقعی بازپرداخت" :maxlength="80"/>
            <x-operation-field name="password" label="رمز عبور فعلی شما" type="password"/>
            <label class="admin-checkbox"><input type="checkbox" name="refund_confirmed" value="1" required> وجه قبلاً به مشتری بازپرداخت شده است؛ این فرم انتقال وجه انجام نمی‌دهد.</label>
            <button class="btn btn-secondary">ثبت بازپرداخت انجام‌شده</button>
        </form>
    </div>
</section>
@endcan
@endsection
