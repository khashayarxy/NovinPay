@extends('payments.layout')
@section('title', 'نتیجهٔ پرداخت')
@section('payment')
    <div class="payment-context">
        <x-storefront.icon :name="$attempt->state === 'settled' ? 'check' : 'shield'" />
        <span>{{ $document->sales_order_id ? 'پرداخت سفارش' : 'پرداخت تعمیر' }}</span>
    </div>
    <h1 id="payment-title">@switch($attempt->state)
        @case('settled') پرداخت تأیید شد @break
        @case('failed') پرداخت انجام نشد @break
        @case('review') پرداخت نیاز به بررسی دارد @break
        @default نتیجهٔ پرداخت در انتظار تأیید است
    @endswitch</h1>
    <p>{{ $attempt->statusMessage() }}</p>
    <nav class="payment-actions" aria-label="گزینه‌های پیگیری پرداخت">
        <a class="payment-button payment-button--primary" href="{{ $backUrl }}">مشاهدهٔ سند و رسیدها</a>
        @if ($canContinue)
            <a class="payment-button" href="{{ route('payments.connect', $attempt->public_id) }}">ادامه به درگاه</a>
        @endif
        @if (!in_array($attempt->state, ['settled', 'failed'], true))
            <a class="payment-button" href="{{ route('payments.result', $attempt->public_id) }}">به‌روزرسانی وضعیت</a>
        @endif
    </nav>
@endsection
