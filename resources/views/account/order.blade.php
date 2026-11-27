@extends('layouts.app')
@section('title', 'جزئیات سفارش')
@section('content')
<div class="np-account np-account-detail np-container">
    <a class="np-account-link np-account-back" href="{{ route('account.index', ['section' => 'orders']) }}">→ بازگشت به سفارش‌ها</a>
    <header class="np-account-banner np-account-banner--compact">
        <div>
            <p class="np-account-banner__eyebrow">جزئیات سفارش · <span class="np-order-code">کد <bdi class="np-latin" dir="ltr">{{ strtoupper(substr($order->public_id, 0, 8)) }}</bdi></span> · <x-finance-time :value="$order->created_at" /></p>
            <h1>{{ \App\Support\CustomerTimeline::orderStatus($order, $plan) }}</h1>
        </div>
        <div class="np-account-banner__total">
            <span>مبلغ کل</span>
            <strong>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman(\App\Support\FinanceMoney::minor($order->total))) }} تومان</strong>
        </div>
    </header>
    <div class="np-account-order-layout">
        <section class="np-account-card">
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
            <x-account.settlement :document="$document" :plan="$plan" :paid-at-display="$paidAtDisplay ?? null" />
        </div>
        @if(! empty($docsEligible))
            <x-orders.activation-documents
                :order="$order"
                :slots="$docSlots ?? []"
                :submit-action="route('account.orders.documents.store', $order->public_id)"
            />
        @endif
    </div>
</div>
@endsection
