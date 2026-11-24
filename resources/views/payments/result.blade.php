@php
    $settled = $attempt->state === 'settled';
    $heading = match ($attempt->state) {
        'settled' => 'پرداخت تأیید شد',
        'failed' => 'پرداخت انجام نشد',
        'review' => 'پرداخت نیاز به بررسی دارد',
        default => 'نتیجهٔ پرداخت در انتظار تأیید است',
    };
    $amount = \App\Support\FaNumber::digits(number_format(intdiv((int) $attempt->amount_minor, 100), 0, '.', '٬'));
@endphp
@extends('payments.layout')
@section('title', $heading)
@section('payment')
    <div class="payment-context"><x-storefront.icon :name="$settled ? 'check' : 'shield'" /><span>{{ $isOrder ? 'پرداخت سفارش' : 'پرداخت تعمیر' }}</span></div>
    <h1 id="payment-title">{{ $heading }}</h1>
    <p>{{ $attempt->statusMessage() }}</p>
    <dl class="payment-summary-list">
        <div><dt>{{ $isOrder ? 'کد سفارش' : 'کد پیگیری تعمیر' }}</dt><dd><bdi dir="ltr">{{ \App\Support\FaNumber::digits((string) $sourceCode) }}</bdi></dd></div>
        @if ($attempt->track_id)
            <div><dt>کد رهگیری زیبال</dt><dd><bdi dir="ltr">{{ \App\Support\FaNumber::digits((string) $attempt->track_id) }}</bdi></dd></div>
        @endif
        <div><dt>مبلغ تراکنش</dt><dd>{{ $amount }} تومان</dd></div>
        @if ($settled && ! empty($paidAtDisplay))
            <div><dt>تاریخ و ساعت پرداخت</dt><dd><bdi dir="ltr">{{ $paidAtDisplay }}</bdi></dd></div>
        @endif
    </dl>
    @if ($settled)
        <nav class="payment-actions" aria-label="پیگیری پس از پرداخت">
            <a class="payment-button payment-button--primary" href="{{ $trackingUrl }}">{{ $isOrder ? 'پیگیری سفارش' : 'پیگیری تعمیر' }}</a>
        </nav>
    @else
        @if ($attempt->state === 'failed')
            <p class="payment-note">اگر مبلغی از حساب شما کسر شده، پس از بررسی بانکی به حساب مبدأ بازمی‌گردد.</p>
        @elseif ($attempt->state !== 'pending' || ! $attempt->track_id)
            <p class="payment-note">تا مشخص‌شدن نتیجه، پرداخت تازه‌ای انجام ندهید. تلاش مجدد در این صفحه فقط وضعیت پرداخت را بررسی می‌کند.</p>
        @endif
        <x-payment-issue-actions :retry-url="$retryUrl" />
    @endif
@endsection
