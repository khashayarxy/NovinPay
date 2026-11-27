@extends('layouts.admin')
@section('title', 'جزئیات سفارش')
@section('content')
@can('finance.view')<div class="admin-notice"><a class="admin-text-link" href="{{ route('admin.finance.sources.show',['type'=>'order','reference'=>$order->public_id]) }}">سند مالی و تسویهٔ مستقل این سفارش ←</a><p>مرحلهٔ سفارش به معنی دریافت وجه نیست.</p></div>@endcan
<header class="admin-page-heading"><div><h1>سفارش #{{ \App\Support\FaNumber::digits($order->id) }}</h1><small class="admin-table-meta np-latin" dir="ltr">{{ strtoupper(substr((string) $order->public_id, 0, 8)) }}</small></div><a class="admin-text-link" href="{{ route('admin.orders.index') }}">فهرست سفارش‌ها ←</a></header><x-admin.errors/>
<section class="repair-status-banner"><div><p>مرحلهٔ فعلی</p><h2>{{ \App\Models\SalesOrder::STATUSES[$order->status] }}</h2><p>پیش‌نویس ← رزرو ← تأیید ← تحویل کامل</p></div><div><strong>{{ \App\Support\FinanceMoney::persianOrderToman($order->total) }} تومان</strong><p>مبلغ سفارش؛ تأیید پرداخت نیست</p></div></section>
<div class="repair-layout mt-4"><div class="repair-main-stack"><section class="admin-panel"><h2>اقلام و مبلغ سفارش</h2><div tabindex="0" role="region" aria-label="جدول قابل پیمایش" class="admin-table-wrap"><table class="admin-table commerce-table"><caption class="sr-only">اقلام سفارش به تومان</caption><thead><tr><th scope="col">کالا</th><th scope="col">تعداد</th><th scope="col">قیمت واحد</th><th scope="col">مبلغ ردیف</th></tr></thead><tbody>@foreach($lines as $line)<tr><td>{{ \App\Support\LatinText::html($line->description) }} · {{ \App\Enums\ProductCondition::from($line->condition)->label() }}<small class="np-latin" dir="ltr">{{ $line->sku }}</small></td><td>{{ \App\Support\FaNumber::digits($line->quantity) }}</td><td>{{ \App\Support\FinanceMoney::persianOrderToman($line->unit_price) }}</td><td>{{ \App\Support\FinanceMoney::persianOrderToman($line->line_total) }}</td></tr>@endforeach</tbody></table></div></section>
<section class="admin-panel"><h2>مشتری و تحویل</h2>@if($contact)<p class="mt-2 font-bold">{{ $customer->name }}</p><p><bdi dir="ltr">{{ \App\Support\FaNumber::digits($customer->contact_phone) }}</bdi></p>@if($order->fulfilled_at)<p class="admin-hint">تحویل در <x-finance-time :value="$order->fulfilled_at" /></p><p class="commerce-private">{{ $order->fulfillment_info }}</p>@endif<a class="admin-text-link" href="{{ route('admin.customers.show',$customer) }}">پروفایل مشتری ←</a>@else<p class="admin-hint">اطلاعات تماس و تحویل به مجوز جداگانه نیاز دارد.</p>@endif</section>
@if($contact)
@php
    $docStatus = (string) ($order->documents_status ?: 'pending_submission');
    $docStatusLabel = \App\Models\SalesOrder::DOCUMENT_STATUSES[$docStatus] ?? 'در انتظار ارسال';
    $hasUnpurgedDocs = $order->documents_purged_at === null && ($order->telegram_message_id !== null || $order->documents_staging_dir !== null);
@endphp
<section class="admin-panel" id="admin-order-documents">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2>مدارک فعال‌سازی و مالیاتی</h2>
        <span class="badge {{ $docStatus === 'approved' ? 'badge-success' : ($docStatus === 'needs_revision' ? 'badge-warning' : 'badge-neutral') }}">{{ $docStatusLabel }}</span>
    </div>
    @if($order->documents_submitted_at)
        <p class="admin-hint mt-2">آخرین ارسال مشتری: <x-finance-time :value="$order->documents_submitted_at" /></p>
    @endif
    @if($order->documents_reviewed_at)
        <p class="admin-hint">آخرین بررسی همکار: <x-finance-time :value="$order->documents_reviewed_at" /></p>
    @endif
    @if($order->documents_purged_at)
        <p class="admin-hint">پاکسازی مدارک: <x-finance-time :value="$order->documents_purged_at" /></p>
    @endif
    @if($order->documents_revision_reason)
        <p class="mt-2 text-sm text-amber-800">علت اعلام‌شده برای اصلاح: {{ $order->documents_revision_reason }}</p>
    @endif
    @if($order->documents_staging_dir)
        <div class="admin-notice mt-3">
            <p>فایل ZIP رمزنگاری‌شده به‌دلیل اختلال تلگرام در فضای خصوصی سرور نگهداری شده است.</p>
            @can('orders.confirm')
                <form class="mt-2" method="post" action="{{ route('admin.orders.documents.retry', $order) }}">@csrf<button class="btn btn-secondary" type="submit">ارسال مجدد به تلگرام و حذف از سرور</button></form>
            @endcan
        </div>
    @endif
    @can('orders.confirm')
        <div class="mt-4 flex flex-wrap items-center gap-3">
            @if($docStatus !== 'approved')
                <form method="post" action="{{ route('admin.orders.documents.approve', $order) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">تأیید مدارک (تأیید شده)</button>
                </form>
            @endif
            @if($hasUnpurgedDocs)
                <form method="post" action="{{ route('admin.orders.documents.purge', $order) }}">
                    @csrf
                    <button class="btn btn-secondary" type="submit">پاکسازی فوری مدارک</button>
                </form>
            @endif
        </div>
        <form class="mt-4 space-y-2 border-t border-slate-200 pt-3" method="post" action="{{ route('admin.orders.documents.revision', $order) }}">
            @csrf
            <label class="label" for="docs-revision-reason">ثبت نیاز به اصلاح مدارک (علت کوتاه برای مشتری)</label>
            <input class="input" type="text" id="docs-revision-reason" name="reason" maxlength="250" value="{{ old('reason', $order->documents_revision_reason) }}" placeholder="مثلاً تصویر کارت ملی ناخوانا است" required>
            <button class="btn btn-secondary" type="submit">اعلام نیاز به اصلاح</button>
        </form>
    @endcan
</section>
@endif
<section class="admin-panel"><h2>تخصیص موجودی</h2><div tabindex="0" role="region" aria-label="جدول قابل پیمایش" class="admin-table-wrap"><table class="admin-table commerce-table"><caption class="sr-only">رزرو یا تحویل دستگاه‌ها</caption><thead><tr><th scope="col">سریال / نوع</th><th scope="col">تعداد</th><th scope="col">وضعیت</th></tr></thead><tbody>@forelse($reservations as $reservation)<tr><td dir="ltr">{{ $reservation->serial ?? '—' }}</td><td>{{ \App\Support\FaNumber::digits($reservation->quantity) }}</td><td>{{ $reservation->status==='active'?'رزرو فعال':'تحویل‌شده' }}</td></tr>@empty<tr><td colspan="3">رزرو فعالی وجود ندارد.</td></tr>@endforelse</tbody></table></div>{{ $reservations->links() }}</section>
<section class="admin-panel" id="order-history"><h2>تاریخچهٔ سفارش</h2>@foreach($events as $event)<article class="repair-history-card"><h3>{{ ['save'=>'ثبت / ویرایش','reserve'=>'رزرو','release'=>'آزادسازی','confirm'=>'تأیید','cancel'=>'لغو','fulfill'=>'تحویل کامل'][$event->action] }} · {{ \App\Models\SalesOrder::STATUSES[$event->to_status] }}</h3><p>{{ $event->actor_id ? 'همکار '.\App\Support\FaNumber::digits($event->actor_id) : 'مشتری' }} · <x-finance-time :value="$event->created_at" /></p>@if($contact)<p>{{ $event->reason }}</p>@endif<ul class="mt-2 space-y-1 text-slate-600">@foreach($event->line_snapshot as $snapshot)<li><span class="np-latin">{{ $snapshot['sku'] }}</span> · {{ \App\Support\FaNumber::digits($snapshot['quantity']) }} × {{ \App\Support\FinanceMoney::persianOrderToman($snapshot['unit_price']) }} تومان</li>@endforeach</ul></article>@endforeach{{ $events->links() }}</section>
</div><aside class="repair-action-stack" aria-label="عملیات سفارش"><section class="admin-panel"><h2>عملیات مجاز</h2><p class="admin-hint">تحویل جزئی پشتیبانی نمی‌شود. پیش از تحویل، همهٔ اقلام و هویت گیرنده را بررسی کنید. رزرو کالا به معنی پرداخت نیست.</p>@if($order->status==='draft') @can('orders.create')<a class="btn btn-secondary mt-4" href="{{ route('admin.orders.edit',$order) }}">ویرایش پیش‌نویس</a>@endcan @endif</section>
@foreach(['reserve'=>['رزرو همهٔ اقلام','orders.reserve',['draft']], 'confirm'=>['تأیید توافق فروش','orders.confirm',['reserved']], 'fulfill'=>['ثبت تحویل کامل','orders.fulfill',['confirmed']], 'release'=>['آزادسازی برای بازبینی','orders.reserve',['reserved','confirmed']], 'cancel'=>['لغو سفارش','orders.reserve',['draft','reserved','confirmed']]] as $action=>[$label,$permission,$statuses])
@if(in_array($order->status,$statuses,true)) @can($permission) @if($action!=='fulfill'||$contact)<section class="repair-action-detail"><h2>{{ $label }}</h2><section class="admin-panel"><x-commerce-operation :action="route('admin.orders.'.$action,$order)" :version="$order->version" :title="$label">@if($action==='fulfill')<x-operation-field name="fulfillment_info" label="اطلاعات گیرنده، شیوه و مدرک تحویل؛ خصوصی" kind="textarea" :maxlength="2000"/>@endif</x-commerce-operation></section></section>@endif @endcan @endif
@endforeach
@if(in_array($order->status,['fulfilled','cancelled'],true))<section class="admin-panel"><span class="admin-icon"><x-home.icon name="shield-check"/></span><h2 class="mt-4">سفارش نهایی‌شده</h2><p class="admin-hint">این سفارش فقط خواندنی است. بازگشت فروش و عملیات مالی به مرحلهٔ بعد تعلق دارد.</p></section>@endif
</aside></div>
@endsection
