@extends('layouts.app')
@section('title', 'پیگیری سفارش')
@section('content')
<div class="np-container public-section repair-track-page">
    <div class="repair-track-shell">
        <header class="store-page-intro"><h1>پیگیری سفارش</h1></header>
        <section class="public-panel">
            @if ($timeline)
                @php($current = collect($timeline)->first(fn ($step) => in_array($step['state'], ['current', 'cancelled'], true)) ?? last($timeline))
                <div class="public-notice" role="status">
                    <h2>{{ $current['label'] }}</h2>
                    <p>{{ match ($current['label']) {
                        'آمادهٔ تحویل حضوری' => 'برای تحویل سفارش به فروشگاه مراجعه کنید.',
                        'در انتظار پرداخت', 'پرداخت ناموفق؛ در انتظار پرداخت' => 'برای تکمیل پرداخت، سفارش را در حساب کاربری باز کنید.',
                        'ارسال به شرکت حمل ثبت شد' => 'جزئیات ارسال را در حساب کاربری بررسی کنید.',
                        default => in_array($current['state'], ['cancelled', 'complete'], true) ? 'برای پیگیری بیشتر، جزئیات سفارش را در حساب کاربری ببینید.' : 'آخرین وضعیت سفارش در همین صفحه نمایش داده می‌شود.',
                    } }}</p>
                </div>
                <x-account.timeline :steps="$timeline" orientation="horizontal" />
                @if(! empty($docsEligible) && isset($trackedOrder))
                    <x-orders.activation-documents
                        :order="$trackedOrder"
                        :slots="$docSlots ?? []"
                        :submit-action="route('account.orders.short.documents.store', ['code' => $code])"
                    />
                @endif
                <a class="public-inline-link mt-4 inline-block" href="{{ route('account.index', ['section' => 'orders']) }}">سفارش‌های من</a>
            @else
                @if ($timeline === [])<p class="public-notice public-notice--error" role="alert">سفارشی با این کد و شماره پیدا نشد.</p>@endif
                <form class="public-form" method="post" action="{{ route('account.orders.track', ['code' => $code]) }}" data-public-submit-loading>
                    @csrf
                    <p>شماره موبایل ثبت‌شده برای سفارش را وارد کنید.</p>
                    <x-repair-field name="contact_phone" label="شماره موبایل">
                        <input class="input" type="tel" id="contact_phone" name="contact_phone" dir="ltr" inputmode="numeric" data-digits-only="11" maxlength="11" autocomplete="tel" placeholder="۰۹۱۲۳۴۵۶۷۸۹" required>
                    </x-repair-field>
                    <button type="submit" class="np-button np-button--primary" data-loading-label="در حال ارسال...">بررسی وضعیت</button>
                </form>
            @endif
        </section>
    </div>
</div>
@endsection
