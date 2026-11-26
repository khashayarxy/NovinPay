@extends('payments.layout')
@section('title', 'پرداخت آنلاین هزینه تعمیر')
@section('payment')
    <div class="payment-context">
        <x-storefront.icon :name="$isPaid ? 'check' : 'repair'" />
        <span>پرداخت آنلاین خدمات تعمیر نوین‌پی</span>
    </div>

    <h1 id="payment-title">
        @if ($isPaid)
            هزینهٔ تعمیر تسویه شده است
        @else
            خلاصهٔ صورت‌حساب تعمیر دستگاه
        @endif
    </h1>

    <dl class="payment-summary-list">
        <div>
            <dt>کد پیگیری تعمیر</dt>
            <dd><bdi dir="ltr">{{ \App\Support\FaNumber::digits((string) $case->tracking_reference) }}</bdi></dd>
        </div>
        @if ($showDevice)
        <div>
            <dt>مدل دستگاه</dt>
            <dd>
                @if ($case->device_brand)<bdi dir="ltr">{{ $case->device_brand->value }}</bdi> · @endif{{ $case->device_model }}
            </dd>
        </div>
        @endif
        <div>
            <dt>مبلغ کل صورت‌حساب</dt>
            <dd>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman($document->charged_minor)) }} تومان</dd>
        </div>
        <div>
            <dt style="color: #122940; font-weight: 700;">{{ $isPaid ? 'مبلغ پرداخت‌شده' : 'مبلغ قابل پرداخت' }}</dt>
            <dd style="color: #0674cf; font-size: 1.05rem;">
                {{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman($isPaid ? $document->paid_minor : $balance)) }} تومان
            </dd>
        </div>
    </dl>

    @if ($isPaid)
        <p>این پرونده قبلاً به‌صورت کامل تسویه شده است. نیازی به پرداخت مجدد نیست.</p>
        @if ($transactions->isNotEmpty())
            <p style="font-size: .9rem; color: #536d88; font-variant-numeric: tabular-nums;">
                مرجع پرداخت: <bdi dir="ltr">{{ $transactions->first()->reference }}</bdi>
            </p>
        @endif
        <nav class="payment-actions" aria-label="پیگیری پس از پرداخت">
            <a class="payment-button payment-button--primary" href="{{ \App\Support\RepairTracking::url($case->tracking_reference) }}">پیگیری تعمیر</a>
        </nav>
    @elseif ($balance > 0)
        @if ($gatewayAvailable)
            <form method="post" action="{{ $startUrl }}" class="payment-actions">
                @csrf
                <input type="hidden" name="operation_key" value="{{ $operationKey }}">
                <button type="submit" class="payment-button payment-button--primary">پرداخت آنلاین با زیبال</button>
            </form>
            <p style="margin-top: .85rem; font-size: .85rem; color: #536d88;">پرداخت از طریق درگاه امن شاپرک و زیبال انجام می‌شود و پس از تأیید، رسید برای شما نمایش داده خواهد شد.</p>
        @else
            <p>درگاه پرداخت آنلاین در حال حاضر غیرفعال است. لطفاً با پشتیبانی نوین‌پی تماس بگیرید.</p>
            <x-payment-issue-actions :retry-url="request()->fullUrl()" />
        @endif
    @else
        <p>ماندهٔ قابل پرداختی برای این پرونده ثبت نشده است.</p>
    @endif
@endsection
