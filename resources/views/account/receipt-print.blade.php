@extends('layouts.app')
@section('title', $kind === 'order' ? 'رسید و صورت‌حساب سفارش' : 'رسید پرداخت و خدمات تعمیر')
@section('quiet-shell', 'true')
@section('content')
@php
    $isOrder = $kind === 'order';
    $customerDisplayName = $isOrder
        ? ($order->customer?->name ?: $account->name ?: 'مشتری گرامی')
        : ($repair->customer_name ?: $account->name ?: 'مشتری گرامی');
    $customerDisplayPhone = $isOrder
        ? ($order->customer?->contact_phone ?: $account->phone)
        : ($repair->contact_phone ?: $account->phone);
    $primaryReference = $transactions->last()?->reference;
@endphp
<div class="np-receipt-page np-container" data-merchant-receipt>
    <div class="np-receipt-toolbar no-print">
        <a class="np-button np-button--outline" href="{{ $backUrl }}">بازگشت به جزئیات {{ $isOrder ? 'سفارش' : 'تعمیر' }}</a>
        <button type="button" class="np-button np-button--primary" data-print-receipt onclick="window.print()">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
            <span>چاپ رسید / ذخیره PDF</span>
        </button>
    </div>

    <article class="np-receipt-sheet" aria-labelledby="receipt-heading">
        <header class="np-receipt-head">
            <div class="np-receipt-brand">
                <img src="{{ versioned_asset('brand/lockups/logo-wordmark-black-on-white.svg') }}" alt="{{ config('business.name') }}" width="132" height="36" class="np-receipt-logo">
                <p class="np-receipt-subtitle">فروش و تعمیرات تخصصی پایانه‌های فروشگاهی (کارتخوان)</p>
            </div>
            <div class="np-receipt-meta">
                <span class="np-receipt-badge">پرداخت تسویه‌شده</span>
                <h1 id="receipt-heading">{{ $isOrder ? 'رسید رسمی خرید کالا' : 'رسید خدمات و تعمیرات تخصصی' }}</h1>
                <p>شماره رسید: <strong dir="ltr" class="np-latin-code">{{ $receiptNumber }}</strong></p>
                @if(! empty($paidAtDisplay))
                    <p>تاریخ تسویه: <strong dir="ltr">{{ $paidAtDisplay }}</strong></p>
                @else
                    <p>تاریخ صدور: <x-finance-time :value="$document->updated_at ?? $document->created_at" /></p>
                @endif
            </div>
        </header>

        <section class="np-receipt-parties" aria-label="مشخصات طرفین">
            <div class="np-receipt-party">
                <h2>فروشنده / ارائه‌دهنده خدمات</h2>
                <p><strong>{{ config('business.name') }}</strong></p>
                <p>{{ config('business.address') }}</p>
                <p>تلفن پشتیبانی: <bdi dir="ltr">{{ \App\Support\FaNumber::digits((string) config('business.phone')) }}</bdi></p>
            </div>
            <div class="np-receipt-party">
                <h2>مشخصات پذیرنده / مشتری</h2>
                <p><strong>{{ $customerDisplayName }}</strong></p>
                @if($customerDisplayPhone)
                    <p>شماره تماس: <bdi dir="ltr">{{ \App\Support\FaNumber::digits((string) $customerDisplayPhone) }}</bdi></p>
                @endif
                @if($plan?->address_snapshot)
                    <p>نشانی تحویل: {{ \App\Support\DeliveryAddress::format($plan->address_snapshot) }}</p>
                @endif
            </div>
        </section>

        <section class="np-receipt-items" aria-label="شرح اقلام و خدمات">
            <table class="np-receipt-table">
                <thead>
                    <tr>
                        <th scope="col">ردیف</th>
                        <th scope="col">شرح کالا / خدمات</th>
                        <th scope="col">تعداد</th>
                        <th scope="col">مبلغ واحد (تومان)</th>
                        <th scope="col">مبلغ کل (تومان)</th>
                    </tr>
                </thead>
                <tbody>
                    @if($isOrder)
                        @foreach($order->lines as $idx => $line)
                            <tr>
                                <td>{{ \App\Support\FaNumber::digits($idx + 1) }}</td>
                                <td>
                                    <strong>{{ \App\Support\LatinText::html($line->description) }}</strong>
                                    @if($line->sku)
                                        <small class="block text-slate-500">کد کالا: <bdi dir="ltr" class="np-latin-code">{{ $line->sku }}</bdi></small>
                                    @endif
                                </td>
                                <td>{{ \App\Support\FaNumber::digits($line->quantity) }}</td>
                                <td>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman(\App\Support\FinanceMoney::minor($line->unit_price))) }}</td>
                                <td><strong>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman(\App\Support\FinanceMoney::minor($line->line_total))) }}</strong></td>
                            </tr>
                        @endforeach
                    @else
                        @php
                            $repairDevice = \App\Support\RepairIdentity::deviceLabel($repair->device_brand, $repair->device_model);
                            $paidToman = \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman($document->paid_minor));
                        @endphp
                        <tr>
                            <td>۱</td>
                            <td>
                                <strong>خدمات عیب‌یابی و تعمیر تخصصی دستگاه <bdi dir="ltr" class="np-latin-code">{{ $repairDevice }}</bdi></strong>
                                <small class="block text-slate-500">
                                    کد پیگیری پرونده: <bdi dir="ltr">{{ \App\Support\FaNumber::digits($repair->tracking_reference) }}</bdi>
                                    @if(! empty($repair->device_serial))
                                        · سریال (S/N): <bdi dir="ltr" class="np-latin-code">{{ $repair->device_serial }}</bdi>
                                    @endif
                                </small>
                                @if($repair->currentEstimate?->customer_summary)
                                    <small class="block text-slate-600">{{ $repair->currentEstimate->customer_summary }}</small>
                                @endif
                            </td>
                            <td>۱</td>
                            <td>{{ $paidToman }}</td>
                            <td><strong>{{ $paidToman }}</strong></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </section>

        <section class="np-receipt-summary" aria-label="جمع‌بندی مالی و تراکنش">
            <div class="np-receipt-tx">
                <h2>اطلاعات پرداخت</h2>
                @if($transactions->isNotEmpty())
                    <ul>
                        @foreach($transactions as $tx)
                            <li>
                                <span>{{ \App\Support\FinanceInput::methodLabel($tx->method) }}: <strong>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman($tx->amount_minor)) }} تومان</strong></span>
                                @if($tx->reference)
                                    <span>شماره مرجع / رهگیری: <bdi dir="ltr" class="np-latin-code">{{ $tx->reference }}</bdi></span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @elseif($primaryReference)
                    <p>شماره مرجع پرداخت: <bdi dir="ltr" class="np-latin-code">{{ $primaryReference }}</bdi></p>
                @else
                    <p>وضعیت پرداخت: تسویه‌شده در سامانه نوین‌پی</p>
                @endif
            </div>
            <dl class="np-receipt-totals">
                @if(($plan?->shipping_minor ?? 0) > 0)
                    <div>
                        <dt>هزینه ارسال</dt>
                        <dd>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman((int) $plan->shipping_minor)) }} تومان</dd>
                    </div>
                @endif
                <div>
                    <dt>مبلغ قابل پرداخت</dt>
                    <dd>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman($document->charged_minor)) }} تومان</dd>
                </div>
                <div class="np-receipt-totals__final">
                    <dt>مبلغ پرداخت‌شده نهایی</dt>
                    <dd>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman($document->paid_minor)) }} تومان</dd>
                </div>
            </dl>
        </section>

        <footer class="np-receipt-foot">
            <p>این رسید به‌صورت سیستمی از درگاه رسمی {{ config('business.name') }} (`novinpaypos.com`) صادر شده و جهت ارائه در سوابق مالی پذیرندگان معتبر است.</p>
        </footer>
    </article>
</div>
@endsection
