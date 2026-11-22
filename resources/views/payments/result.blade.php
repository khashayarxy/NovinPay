@extends('payments.layout')
@section('title', 'نتیجهٔ پرداخت')
@section('payment')
    @php
        $isOrder = $isOrder ?? (bool) $document->sales_order_id;
        $sourceCode = $sourceCode ?? strtoupper(substr($document->public_id, 0, 8));
        $retryUrl = $retryUrl ?? $backUrl;
        $continueUrl = $continueUrl ?? ($canContinue ? route('payments.connect', $attempt->public_id) : null);
    @endphp
    <div class="payment-context">
        <x-storefront.icon :name="$attempt->state === 'settled' ? 'check' : 'shield'" />
        <span>{{ $isOrder ? 'پرداخت سفارش' : 'پرداخت تعمیر' }}</span>
    </div>
    <h1 id="payment-title">@switch($attempt->state)
        @case('settled') پرداخت تأیید شد @break
        @case('failed') پرداخت انجام نشد @break
        @case('review') پرداخت نیاز به بررسی دارد @break
        @default نتیجهٔ پرداخت در انتظار تأیید است
    @endswitch</h1>
    <p>{{ $attempt->statusMessage() }}</p>

    <dl class="payment-summary-list" style="margin: 1.15rem 0; display: grid; gap: .65rem; padding: .95rem 1.1rem; border-radius: .75rem; background: #f8fbff; border: 1px solid #dce7f2;">
        <div style="display: flex; justify-content: space-between; gap: 1rem;">
            <dt style="color: #536d88;">{{ $isOrder ? 'کد سفارش' : 'کد پیگیری تعمیر' }}</dt>
            <dd style="margin: 0; font-weight: 700; font-variant-numeric: tabular-nums;"><bdi dir="ltr">{{ \App\Support\FaNumber::digits((string) $sourceCode) }}</bdi></dd>
        </div>
        @if ($attempt->track_id)
            <div style="display: flex; justify-content: space-between; gap: 1rem;">
                <dt style="color: #536d88;">کد رهگیری زیبال</dt>
                <dd style="margin: 0; font-weight: 700; font-variant-numeric: tabular-nums;"><bdi dir="ltr">{{ \App\Support\FaNumber::digits((string) $attempt->track_id) }}</bdi></dd>
            </div>
        @endif
        <div style="display: flex; justify-content: space-between; gap: 1rem;">
            <dt style="color: #536d88;">مبلغ تراکنش</dt>
            <dd style="margin: 0; font-weight: 700; color: {{ $attempt->state === 'settled' ? '#15803d' : '#122940' }}; font-variant-numeric: tabular-nums;">
                {{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display($attempt->amount_minor)) }} تومان
            </dd>
        </div>
    </dl>

    <nav class="payment-actions" aria-label="گزینه‌های پیگیری پرداخت">
        @if ($attempt->state === 'failed')
            <a class="payment-button payment-button--primary" href="{{ $retryUrl }}">تلاش مجدد برای پرداخت</a>
            @if (! empty($accountUrl))
                <a class="payment-button" href="{{ $accountUrl }}">حساب کاربری</a>
            @endif
        @else
            <a class="payment-button payment-button--primary" href="{{ $backUrl }}">مشاهدهٔ سند و رسیدها</a>
            @if (! empty($accountUrl))
                <a class="payment-button" href="{{ $accountUrl }}">حساب کاربری</a>
            @endif
            @if ($canContinue && $continueUrl)
                <a class="payment-button" href="{{ $continueUrl }}">ادامه به درگاه</a>
            @endif
            @if (! in_array($attempt->state, ['settled', 'failed'], true))
                <a class="payment-button" href="{{ request()->fullUrl() }}">به‌روزرسانی وضعیت</a>
            @endif
        @endif
    </nav>
@endsection
