@extends('layouts.app')
@section('title', 'جزئیات سفارش')
@section('content')
<div class="np-account np-account-detail np-container">
    <a class="np-account-link" href="{{ route('account.index', ['section' => 'orders']) }}">بازگشت به سفارش‌ها</a>
    <header class="np-account-banner">
        <div>
            <p>جزئیات سفارش</p>
            <h1>{{ \App\Support\CustomerTimeline::orderStatus($order, $plan) }}</h1>
            <p><x-finance-time :value="$order->created_at" /></p>
            <bdi class="np-account-reference" dir="ltr">{{ $order->public_id }}</bdi>
        </div>
    </header>
    <section class="np-account-card">
        <h2>مسیر سفارش</h2>
        <x-account.timeline :steps="$timeline" />
    </section>
    <section class="np-account-card">
        <h2>کالاهای سفارش</h2>
        @forelse($order->lines as $line)
            <div class="np-account-row">
                <span><strong>{{ $line->description }}</strong><small>{{ \App\Support\FaNumber::digits($line->quantity) }} عدد · هر عدد {{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display(\App\Support\FinanceMoney::minor($line->unit_price))) }} تومان</small></span>
                <span class="np-account-tag">{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display(\App\Support\FinanceMoney::minor($line->line_total))) }} تومان</span>
            </div>
        @empty
            <p>کالایی برای این سفارش ثبت نشده است.</p>
        @endforelse
        <p class="np-account-total">جمع سفارش <strong>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display(\App\Support\FinanceMoney::minor($order->total))) }} تومان</strong></p>
    </section>
    <x-account.settlement :document="$document" :plan="$plan" />
</div>
@endsection
