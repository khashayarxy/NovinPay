@props([
    'order',
    'slots' => [],
    'submitAction',
])
@php
    $docStatus = (string) ($order->documents_status ?: 'pending_submission');
    $statusLabel = \App\Models\SalesOrder::DOCUMENT_STATUSES[$docStatus] ?? 'در انتظار ارسال';
    $statusBadgeClass = match ($docStatus) {
        'approved' => 'badge badge-success',
        'needs_revision' => 'badge badge-warning',
        'pending_review' => 'badge badge-sample',
        default => 'badge badge-neutral',
    };
    $canUpload = in_array($docStatus, ['pending_submission', 'needs_revision'], true);
    $maxMb = \App\Support\FaNumber::digits((string) round(max(512, (int) config('novinpay.docs_max_file_kb', 8192)) / 1024, 1));
    $maxExtra = max(0, (int) config('novinpay.docs_max_extra_files', 4));
    $retentionDaysFa = \App\Support\FaNumber::digits((string) max(1, (int) config('novinpay.docs_retention_days', 7)));
@endphp

<section class="np-account-card mt-5" id="activation-documents" aria-labelledby="activation-documents-heading">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3">
        <div>
            <h2 id="activation-documents-heading" class="text-base font-bold text-slate-900">ارسال مدارک فعال‌سازی و ثبت در سامانه مالیاتی</h2>
            <p class="mt-1 text-xs text-slate-500">جهت فعال‌سازی دستگاه کارتخوان و ثبت پرونده شاپرکی و مالیاتی</p>
        </div>
        <span class="{{ $statusBadgeClass }}" data-documents-status="{{ $docStatus }}">{{ $statusLabel }}</span>
    </div>

    <div class="mt-4 flex items-start gap-2.5 rounded-xl border border-sky-200 bg-sky-50/70 px-3.5 py-3 text-xs leading-6 text-slate-700">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-brand-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        <p>مدارک شما به‌صورت رمزنگاری‌شده پردازش شده و {{ $retentionDaysFa }} روز پس از تحویل دستگاه به‌صورت خودکار حذف می‌شوند.</p>
    </div>

    @if (session('success'))
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="status">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">
            <ul class="list-disc space-y-1 pr-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($docStatus === 'needs_revision')
        <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm leading-7 text-amber-900" role="alert">
            <strong class="block font-bold">نیاز به اصلاح و ارسال مجدد مدارک:</strong>
            <p class="mt-1">{{ $order->documents_revision_reason ?: 'لطفاً تصاویر مدارک را واضح و کامل مجدداً بارگذاری فرمایید.' }}</p>
        </div>
    @elseif ($docStatus === 'pending_review')
        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm leading-7 text-slate-700">
            <strong class="font-bold text-slate-900">مدارک شما دریافت شد و در انتظار بررسی است.</strong>
            <p class="mt-1 text-xs text-slate-600">پس از بررسی توسط کارشناسان نوین‌پی، نتیجه از طریق پیامک به شماره همراه شما اطلاع‌رسانی خواهد شد.</p>
        </div>
    @elseif ($docStatus === 'approved')
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50/80 px-4 py-3.5 text-sm leading-7 text-emerald-900">
            <strong class="font-bold">مدارک فعال‌سازی شما تأیید شده است.</strong>
            <p class="mt-1 text-xs text-emerald-800">فرایند ثبت شاپرکی و فعال‌سازی دستگاه شما در حال انجام است.</p>
        </div>
    @endif

    @if ($canUpload && $slots !== [])
        <form class="mt-5 space-y-4" method="post" action="{{ $submitAction }}" enctype="multipart/form-data" data-public-submit-loading>
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($slots as $slotKey => $slot)
                    <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3.5">
                        <label class="label flex items-center justify-between gap-2" for="doc-slot-{{ $slotKey }}">
                            <span>{{ $slot['label'] }}</span>
                            @if ($slot['required'])
                                <span class="text-xs font-medium text-rose-600">الزامی</span>
                            @else
                                <span class="text-xs text-slate-400">اختیاری</span>
                            @endif
                        </label>
                        @if ($slot['hint'] !== '')
                            <p class="mb-2 text-xs leading-5 text-slate-500">{{ $slot['hint'] }}</p>
                        @endif
                        <input
                            class="input bg-white text-xs file:ml-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-brand-700 hover:file:bg-brand-100"
                            type="file"
                            id="doc-slot-{{ $slotKey }}"
                            name="slots[{{ $slotKey }}]"
                            accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                            @if ($slot['required']) required @endif
                        >
                    </div>
                @endforeach
            </div>

            @if ($maxExtra > 0)
                <div class="rounded-xl border border-dashed border-slate-300 bg-white p-3.5">
                    <label class="label" for="doc-extra-files">فایل‌های تکمیلی (اختیاری — حداکثر {{ \App\Support\FaNumber::digits((string) $maxExtra) }} فایل)</label>
                    <p class="mb-2 text-xs text-slate-500">جواز کسب، اجاره‌نامه تکمیلی، صفحه توضیحات شناسنامه یا سایر مستندات (JPG، PNG، WebP یا PDF — حداکثر {{ $maxMb }} مگابایت برای هر فایل)</p>
                    <input
                        class="input text-xs file:ml-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700"
                        type="file"
                        id="doc-extra-files"
                        name="extra_files[]"
                        multiple
                        accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                    >
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                <span class="text-xs text-slate-500">فرمت‌های مجاز: JPG، PNG، WebP، PDF (حداکثر {{ $maxMb }} مگابایت برای هر فایل)</span>
                <button type="submit" class="btn btn-primary" data-loading-label="در حال رمزنگاری و ارسال...">
                    {{ $docStatus === 'needs_revision' ? 'ارسال مجدد مدارک اصلاح‌شده' : 'رمزنگاری و ارسال مدارک' }}
                </button>
            </div>
        </form>
    @endif
</section>
