@extends('layouts.app')
@section('title', 'جزئیات سفارش')
@section('content')
@php
    $orderCodeRaw = strtoupper(substr($order->public_id, 0, 8));
    $isSettled = $document && $document->charged_minor > 0 && $document->paid_minor >= $document->charged_minor;
@endphp
<div class="np-account np-account-detail np-container">
    <div class="np-detail-topbar">
        <a class="np-account-link np-account-back" href="{{ route('account.index', ['section' => 'orders']) }}">بازگشت به سفارش‌ها</a>
        @if($isSettled)
            <a class="np-button np-button--outline np-detail-receipt-btn" href="{{ route('account.orders.receipt', $orderCodeRaw) }}">مشاهده و چاپ رسید</a>
        @endif
    </div>
    <header class="np-account-banner np-account-banner--compact">
        <div>
            <p class="np-account-banner__eyebrow np-banner-code-row">
                <span>جزئیات سفارش · کد <bdi class="np-latin" dir="ltr">{{ $orderCodeRaw }}</bdi></span>
                <button type="button" class="np-banner-copy-btn" data-copy-code="{{ $orderCodeRaw }}" aria-label="کپی کد سفارش {{ $orderCodeRaw }}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                    <span data-copy-code-label>کپی</span>
                </button>
                <span>· <x-finance-time :value="$order->created_at" /></span>
            </p>
            <h1>{{ \App\Support\CustomerTimeline::orderStatus($order, $plan) }}</h1>
        </div>
        <div class="np-account-banner__total">
            <span>مبلغ کل</span>
            <strong>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman(\App\Support\FinanceMoney::minor($order->total))) }} تومان</strong>
        </div>
    </header>
    <div class="np-account-order-layout">
        <section class="np-account-card np-account-order-stepper">
            <h2>مسیر سفارش</h2>
            <x-account.timeline :steps="$timeline" orientation="horizontal" />
        </section>
        <div class="np-account-order-columns">
            <section class="np-account-card">
                <h2>کالاهای سفارش</h2>
                @forelse($order->lines as $line)
                    <div class="np-account-row">
                        <span class="np-account-row__main">
                            <span class="np-account-row__title">{{ \App\Support\LatinText::html($line->description) }}</span>
                            <span class="np-account-row__meta">{{ \App\Support\FaNumber::digits($line->quantity) }} عدد × {{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman(\App\Support\FinanceMoney::minor($line->unit_price))) }} تومان</span>
                        </span>
                        <span class="np-account-price">{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman(\App\Support\FinanceMoney::minor($line->line_total))) }} <small>تومان</small></span>
                    </div>
                @empty
                    <div class="np-account-empty"><p>کالایی برای این سفارش ثبت نشده است.</p></div>
                @endforelse
                <div class="np-account-total">
                    <span>جمع سفارش</span>
                    <strong>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman(\App\Support\FinanceMoney::minor($order->total))) }} تومان</strong>
                </div>
            </section>
            <x-account.settlement
                :document="$document"
                :plan="$plan"
                :paid-at-display="$paidAtDisplay ?? null"
                :receipt-url="$isSettled ? route('account.orders.receipt', $orderCodeRaw) : null"
            />
        </div>
        @if(! empty($docsEligible))
            <x-orders.activation-documents
                :order="$order"
                :slots="$docSlots ?? []"
                :submit-action="route('account.orders.documents.store', $orderCodeRaw)"
            />
        @endif
    </div>
</div>
@endsection
