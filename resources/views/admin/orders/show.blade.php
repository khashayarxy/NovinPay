@extends('layouts.admin')
@section('title', 'جزئیات سفارش')
@section('content')
@php
    $shortOrderCode = strtoupper(substr((string) $order->public_id, 0, 8));
    $orderTone = match($order->status) {
        'fulfilled', 'confirmed' => 'success',
        'reserved' => 'info',
        'cancelled' => 'danger',
        default => 'warning',
    };
@endphp
<a class="admin-back-link" href="{{ route('admin.orders.index') }}">← فهرست سفارش‌ها</a>
<header class="admin-page-heading">
    <div>
        <div class="repair-case-header__title-row">
            <h1>سفارش #{{ \App\Support\FaNumber::digits($order->id) }}</h1>
            <x-admin.status :tone="$orderTone">{{ \App\Models\SalesOrder::STATUSES[$order->status] }}</x-admin.status>
        </div>
        <p class="repair-case-header__sub">
            <span>مبلغ کل: <strong>{{ \App\Support\FinanceMoney::persianOrderToman($order->total) }} تومان</strong></span>
            <span>· کد پیگیری: <bdi class="np-latin" dir="ltr">{{ $shortOrderCode }}</bdi></span>
            <button type="button" class="admin-copy-mini" data-copy-code="{{ $shortOrderCode }}"><span data-copy-code-label>کپی کد</span></button>
        </p>
        <div class="admin-id-row sr-only">
            <span class="admin-id-chip np-latin" dir="ltr" title="{{ $order->public_id }}">{{ $shortOrderCode }}</span>
        </div>
    </div>
    <div class="admin-actions">
        @if($order->status==='draft')
            @can('orders.create')<a class="btn btn-primary btn-sm" href="{{ route('admin.orders.edit',$order) }}">ویرایش پیش‌نویس</a>@endcan
        @endif
        @can('finance.view')
            <a class="btn btn-secondary btn-sm" href="{{ route('admin.finance.sources.show',['type'=>'order','reference'=>$order->public_id]) }}">سند مالی و تسویه</a>
        @endcan
    </div>
</header>
<x-admin.errors/>

<div class="repair-layout mt-4">
    <div class="repair-main-stack">
        <section class="admin-panel admin-collection">
            <div class="admin-collection-tools"><h2>اقلام و مبلغ سفارش</h2></div>
            <div tabindex="0" role="region" aria-label="اقلام سفارش" class="admin-table-wrap">
                <table class="admin-table commerce-table">
                    <caption class="sr-only">اقلام سفارش به تومان</caption>
                    <thead><tr><th scope="col">کالا</th><th scope="col">تعداد</th><th scope="col">قیمت واحد</th><th scope="col">مبلغ ردیف</th></tr></thead>
                    <tbody>
                        @foreach($lines as $line)
                            <tr>
                                <td>
                                    <strong>{{ \App\Support\LatinText::html($line->description) }}</strong>
                                    <span class="admin-table-meta">{{ \App\Enums\ProductCondition::from($line->condition)->label() }} · <span class="np-latin" dir="ltr">{{ $line->sku }}</span></span>
                                </td>
                                <td>{{ \App\Support\FaNumber::digits($line->quantity) }}</td>
                                <td>{{ \App\Support\FinanceMoney::persianOrderToman($line->unit_price) }}</td>
                                <td><strong>{{ \App\Support\FinanceMoney::persianOrderToman($line->line_total) }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-panel">
            <div class="admin-panel-heading">
                <h2>مشتری و تحویل</h2>
                @if($contact)
                    <a class="admin-text-link" href="{{ route('admin.customers.show',$customer) }}">پروفایل مشتری ←</a>
                @endif
            </div>
            @if($contact)
                <dl class="admin-record-facts">
                    <div><dt>نام مشتری</dt><dd><strong>{{ $customer->name }}</strong></dd></div>
                    <div><dt>شماره موبایل</dt><dd><a class="repair-phone-chip" href="tel:{{ $customer->contact_phone }}" dir="ltr"><bdi>{{ \App\Support\FaNumber::digits($customer->contact_phone) }}</bdi></a></dd></div>
                    @if($order->fulfilled_at)
                        <div><dt>زمان تحویل</dt><dd><x-finance-time :value="$order->fulfilled_at" /></dd></div>
                        <div class="repair-fact--full"><dt>جزئیات تحویل</dt><dd class="commerce-private">{{ $order->fulfillment_info }}</dd></div>
                    @endif
                </dl>
            @else
                <p class="admin-hint">اطلاعات تماس و تحویل به مجوز جداگانه نیاز دارد.</p>
            @endif
        </section>

        @if($contact)
            @php
                $docStatus = (string) ($order->documents_status ?: 'pending_submission');
                $docStatusLabel = \App\Models\SalesOrder::DOCUMENT_STATUSES[$docStatus] ?? 'در انتظار ارسال';
                $hasUnpurgedDocs = $order->documents_purged_at === null && ($order->telegram_message_id !== null || $order->documents_staging_dir !== null);
            @endphp
            <section class="admin-panel" id="admin-order-documents">
                <div class="admin-panel-heading">
                    <h2>مدارک فعال‌سازی و مالیاتی</h2>
                    <x-admin.status :tone="$docStatus === 'approved' ? 'success' : ($docStatus === 'needs_revision' ? 'warning' : 'neutral')">{{ $docStatusLabel }}</x-admin.status>
                </div>
                <div class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500">
                    @if($order->documents_submitted_at)
                        <span>آخرین ارسال مشتری: <x-finance-time :value="$order->documents_submitted_at" /></span>
                    @endif
                    @if($order->documents_reviewed_at)
                        <span>آخرین بررسی: <x-finance-time :value="$order->documents_reviewed_at" /></span>
                    @endif
                    @if($order->documents_purged_at)
                        <span>پاکسازی: <x-finance-time :value="$order->documents_purged_at" /></span>
                    @endif
                </div>
                @if($order->documents_revision_reason)
                    <div class="admin-info-card admin-info-card--warning mt-3">علت اصلاح: {{ $order->documents_revision_reason }}</div>
                @endif
                @if($order->documents_staging_dir)
                    <div class="admin-notice mt-3">
                        <span>فایل ZIP رمزنگاری‌شده در فضای خصوصی سرور نگهداری شده است.</span>
                        @can('orders.confirm')
                            <form method="post" action="{{ route('admin.orders.documents.retry', $order) }}">@csrf<button class="btn btn-secondary btn-sm" type="submit">ارسال مجدد به تلگرام و حذف از سرور</button></form>
                        @endcan
                    </div>
                @endif
                @can('orders.confirm')
                    <div class="admin-actions mt-3">
                        @if($docStatus !== 'approved')
                            <form method="post" action="{{ route('admin.orders.documents.approve', $order) }}">
                                @csrf
                                <button class="btn btn-primary btn-sm" type="submit">تأیید مدارک</button>
                            </form>
                        @endif
                        @if($hasUnpurgedDocs)
                            <form method="post" action="{{ route('admin.orders.documents.purge', $order) }}">
                                @csrf
                                <button class="btn btn-secondary btn-sm" type="submit">پاکسازی فوری مدارک</button>
                            </form>
                        @endif
                    </div>
                    <details class="admin-accordion mt-3" @if($docStatus === 'needs_revision' || $errors->has('reason')) open @endif>
                        <summary>
                            <span>ثبت نیاز به اصلاح مدارک</span>
                            <svg class="admin-accordion__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                        </summary>
                        <div class="admin-accordion__body">
                            <form class="flex flex-wrap items-end gap-2" method="post" action="{{ route('admin.orders.documents.revision', $order) }}">
                                @csrf
                                <div class="flex-1 min-w-[14rem]">
                                    <label class="label" for="docs-revision-reason">علت کوتاه برای مشتری</label>
                                    <input class="input" type="text" id="docs-revision-reason" name="reason" maxlength="250" value="{{ old('reason', $order->documents_revision_reason) }}" placeholder="مثلاً تصویر کارت ملی ناخوانا است" required>
                                </div>
                                <button class="btn btn-secondary" type="submit">اعلام نیاز به اصلاح</button>
                            </form>
                        </div>
                    </details>
                @endcan
            </section>
        @endif

        <section class="admin-panel admin-collection">
            <div class="admin-collection-tools"><h2>تخصیص موجودی</h2></div>
            <div tabindex="0" role="region" aria-label="تخصیص موجودی" class="admin-table-wrap">
                <table class="admin-table commerce-table">
                    <caption class="sr-only">رزرو یا تحویل دستگاه‌ها</caption>
                    <thead><tr><th scope="col">سریال / نوع</th><th scope="col">تعداد</th><th scope="col">وضعیت</th></tr></thead>
                    <tbody>
                        @forelse($reservations as $reservation)
                            <tr>
                                <td dir="ltr">{{ $reservation->serial ?? '—' }}</td>
                                <td>{{ \App\Support\FaNumber::digits($reservation->quantity) }}</td>
                                <td><x-admin.status :tone="$reservation->status==='active' ? 'info' : 'success'">{{ $reservation->status==='active'?'رزرو فعال':'تحویل‌شده' }}</x-admin.status></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="admin-muted">رزرو فعالی وجود ندارد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($reservations->hasPages())<div class="admin-collection-footer">{{ $reservations->links() }}</div>@endif
        </section>

        <section class="admin-panel" id="order-history">
            <h2>تاریخچهٔ سفارش</h2>
            @foreach($events as $event)
                <article class="repair-history-card">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3>{{ ['save'=>'ثبت / ویرایش','reserve'=>'رزرو','release'=>'آزادسازی','confirm'=>'تأیید','cancel'=>'لغو','fulfill'=>'تحویل کامل'][$event->action] }} · {{ \App\Models\SalesOrder::STATUSES[$event->to_status] }}</h3>
                        <span class="admin-muted">{{ $event->actor_id ? 'همکار '.\App\Support\FaNumber::digits($event->actor_id) : 'مشتری' }} · <x-finance-time :value="$event->created_at" /></span>
                    </div>
                    @if($contact && $event->reason)<p class="mt-1">{{ $event->reason }}</p>@endif
                    <ul class="mt-2 space-y-1 text-xs text-slate-600">
                        @foreach($event->line_snapshot as $snapshot)
                            <li><span class="np-latin">{{ $snapshot['sku'] }}</span> · {{ \App\Support\FaNumber::digits($snapshot['quantity']) }} × {{ \App\Support\FinanceMoney::persianOrderToman($snapshot['unit_price']) }} تومان</li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
            {{ $events->links() }}
        </section>
    </div>

    <aside class="repair-action-stack" aria-label="عملیات سفارش">
        @foreach(['reserve'=>['رزرو اقلام سفارش','orders.reserve',['draft']], 'confirm'=>['تأیید سفارش','orders.confirm',['reserved']], 'fulfill'=>['ثبت تحویل کامل سفارش','orders.fulfill',['confirmed']]] as $action=>[$label,$permission,$statuses])
            @if(in_array($order->status,$statuses,true))
                @can($permission)
                    @if($action!=='fulfill'||$contact)
                        <section class="admin-panel">
                            <div class="admin-panel-heading"><h2>{{ $label }}</h2></div>
                            <x-commerce-operation :action="route('admin.orders.'.$action,$order)" :version="$order->version" :title="$label">
                                @if($action==='fulfill')
                                    <x-operation-field name="fulfillment_info" label="مشخصات گیرنده، روش ارسال و کد پیگیری" kind="textarea" :maxlength="2000"/>
                                @endif
                            </x-commerce-operation>
                        </section>
                    @endif
                @endcan
            @endif
        @endforeach

        @if(in_array($order->status,['draft','reserved','confirmed'],true))
            @can('orders.reserve')
                <details class="admin-accordion">
                    <summary>
                        <span>لغو یا آزادسازی رزرو سفارش</span>
                        <svg class="admin-accordion__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                    </summary>
                    <div class="admin-accordion__body space-y-4">
                        @if(in_array($order->status,['reserved','confirmed'],true))
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-2">آزادسازی رزرو برای ویرایش</h3>
                                <x-commerce-operation :action="route('admin.orders.release',$order)" :version="$order->version" title="آزادسازی رزرو" />
                            </div>
                        @endif
                        <div @if(in_array($order->status,['reserved','confirmed'],true)) class="pt-3 border-t border-slate-200" @endif>
                            <h3 class="text-sm font-bold text-rose-700 mb-2">لغو کامل سفارش</h3>
                            <x-commerce-operation :action="route('admin.orders.cancel',$order)" :version="$order->version" title="لغو سفارش" />
                        </div>
                    </div>
                </details>
            @endcan
        @endif

        @if(in_array($order->status,['fulfilled','cancelled'],true))
            <section class="admin-panel">
                <div class="flex items-center gap-2.5">
                    <span class="admin-icon"><x-home.icon name="shield-check"/></span>
                    <div>
                        <h2>سفارش نهایی‌شده</h2>
                        <p class="admin-hint">این سفارش مختومه است؛ اصلاحات مالی از طریق سند مالی انجام می‌شود.</p>
                    </div>
                </div>
            </section>
        @endif
    </aside>
</div>
@endsection
