@extends('layouts.admin')
@section('title', ['intake'=>'رسید پذیرش', 'summary'=>'خلاصهٔ تعمیر', 'delivery'=>'سند تحویل'][$document])
@section('content')
<div class="repair-print-controls admin-actions">
    <button class="btn btn-primary" type="button" data-print-document>چاپ سند</button>
    <a class="btn btn-secondary" href="{{ route('admin.repair-cases.show', $case) }}">بازگشت به پرونده</a>
</div>
<article class="admin-panel repair-document">
    <header class="repair-document-header">
        <div class="flex items-center gap-3">
            <x-brand-logo/>
            <div>
                <h1>{{ ['intake'=>'رسید دریافت دستگاه', 'summary'=>'خلاصهٔ خدمات تعمیر', 'delivery'=>'سند تحویل دستگاه'][$document] }}</h1>
                <p class="admin-muted">{{ config('business.name') }} · فروش و خدمات کارتخوان</p>
            </div>
        </div>
        <span class="admin-id-chip" title="{{ $case->public_id }}">#{{ strtoupper(substr((string) $case->public_id, 0, 8)) }}</span>
    </header>
    <dl class="admin-definition">
        <div><dt>کد پیگیری مشتری</dt><dd class="admin-reference" dir="ltr">{{ $case->tracking_reference }}</dd></div>
        <div><dt>مشتری و تماس</dt><dd>{{ $case->customer_name }} · <bdi>{{ $case->contact_phone }}</bdi></dd></div>
        <div><dt>دستگاه</dt><dd>{{ ($case->device_brand?->value ?? '—') }} · {{ $case->device_model }}</dd></div>
        <div><dt>سریال اعلام‌شده</dt><dd>{{ $case->device_serial ?? 'ثبت نشده' }}</dd></div>
        <div><dt>دریافت دستگاه</dt><dd><x-finance-time :value="$case->received_at" /> · {{ $case->receiver->name }}</dd></div>
        <div><dt>وضعیت ظاهری هنگام دریافت</dt><dd class="whitespace-pre-line">{{ $case->physical_condition }}</dd></div>
        <div><dt>لوازم همراه ثبت‌شده</dt><dd class="whitespace-pre-line">{{ $case->accessories }}</dd></div>
    </dl>
    @if($document === 'intake')
        <div class="mt-4">
            <h2>مشکل گزارش‌شده</h2>
            <p class="whitespace-pre-line mt-1">{{ $case->issue }}</p>
            <p class="admin-hint">این رسید صرفاً دریافت فیزیکی دستگاه را گواهی می‌کند و به معنی تأیید برآورد یا دریافت وجه نیست.</p>
        </div>
    @endif
    @if($document === 'summary')
        <div class="mt-4">
            <h2>خدمات واقعاً ثبت‌شده</h2>
            @forelse($case->workEntries as $entry)
                <section class="repair-history-card">
                    <p class="whitespace-pre-line">{{ $entry->work_performed }}</p>
                    <p class="whitespace-pre-line">قطعات توصیفی: {{ $entry->parts_replaced }}</p>
                    @if($entry->is_completion)<p class="whitespace-pre-line">نتیجه: {{ $entry->final_result }}</p>@endif
                    <p class="admin-muted mt-1"><x-finance-time :value="$entry->created_at" /></p>
                </section>
            @empty
                <p class="admin-muted mt-1">هیچ کار انجام‌شده‌ای ثبت نشده است.</p>
            @endforelse
            @if($case->currentEstimate)
                <div class="repair-history-card mt-3">
                    <h3>آخرین برآورد، نسخهٔ {{ \App\Support\FaNumber::digits($case->currentEstimate->revision) }}</h3>
                    <p class="whitespace-pre-line mt-1">{{ $case->currentEstimate->customer_summary }}</p>
                    <p class="mt-1">خدمات: {{ \App\Support\FaNumber::digits(number_format($case->currentEstimate->service_cost)) }} · قطعات: {{ \App\Support\FaNumber::digits(number_format($case->currentEstimate->parts_cost)) }} · جمع: <strong>{{ \App\Support\FaNumber::digits(number_format($case->currentEstimate->total())) }} تومان</strong></p>
                    <p class="admin-hint">وضعیت تصمیم: {{ ['pending'=>'هنوز تصمیمی ثبت نشده', 'approved'=>'تأیید مشتری توسط کارمند ثبت شده', 'rejected'=>'رد مشتری توسط کارمند ثبت شده'][$case->currentEstimate->decisionStatus()] }}</p>
                </div>
            @endif
            <p class="admin-hint">مرحلهٔ فعلی: {{ $case->status->label() }}.</p>
        </div>
    @endif
    @if($document === 'delivery')
        @php($handover = $case->delivery)
        <div class="mt-4">
            <h2>{{ $handover->outcome === 'repaired' ? 'تحویل پس از تکمیل تعمیر' : 'بازگرداندن بدون تعمیر موفق' }}</h2>
            <dl class="admin-definition">
                <div><dt>تحویل‌گیرنده</dt><dd>{{ $handover->recipient_name }}</dd></div>
                <div><dt>زمان واقعی تحویل ثبت‌شده</dt><dd><x-finance-time :value="$handover->delivered_at" /></dd></div>
                <div><dt>کارمند مسئول ثبت</dt><dd>{{ $handover->actor->name }}</dd></div>
                <div><dt>نتیجه و توضیحات</dt><dd class="whitespace-pre-line">{{ $handover->outcome_notes }}</dd></div>
                <div><dt>مبالغ نهایی ثبت‌شده</dt><dd>{{ $handover->service_cost !== null ? 'خدمات: '.\App\Support\FaNumber::digits(number_format($handover->service_cost)).' · قطعات: '.\App\Support\FaNumber::digits(number_format($handover->parts_cost)).' تومان' : 'مبلغ نهایی تعیین نشده است' }}</dd></div>
                <div><dt>روش تأیید ثبت‌شده</dt><dd>{{ ['staff_observation'=>'مشاهدات کارمند','message'=>'پیام تحویل‌گیرنده','document'=>'سند قابل ارجاع'][$handover->confirmation_method] ?? 'مدرکی ثبت نشده' }}</dd></div>
                @if($handover->confirmation_method)<div><dt>مرجع ثبت‌شده</dt><dd class="whitespace-pre-line">{{ $handover->confirmation_reference }}</dd></div>@endif
            </dl>
        </div>
    @endif
    <footer class="repair-document-footer">
        <p>این سند بر پایهٔ ثبت کارکنان صادر شده است. امضا یا تأیید دیجیتال مشتری در این سامانه دریافت نشده است. هیچ پرداختی با این سند تأیید نمی‌شود.</p>
    </footer>
</article>
@endsection
