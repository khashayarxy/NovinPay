@extends('layouts.admin')
@section('title', 'لیست سفارش‌ها')
@section('content')
<header class="admin-page-heading">
    <div>
        <h1>لیست سفارش‌ها</h1>
        <p>پیگیری سفارش‌های فروش، رزرو موجودی و مدارک فعال‌سازی.</p>
    </div>
    <div class="admin-actions">
        @can('orders.fulfill')<a class="btn btn-secondary" href="{{ route('admin.deliveries.index') }}">مدیریت ارسال</a>@endcan
        @can('orders.create')<a class="btn btn-primary" href="{{ route('admin.orders.create') }}">+ سفارش جدید</a>@endcan
    </div>
</header>
<x-admin.errors/>
@php
    $activeFilters = (int) (! empty($filters['q'])) + (int) (! empty($filters['status']));
@endphp
<section class="admin-panel admin-collection">
    <div class="admin-collection-tools">
        <x-admin.filter-drawer id="order-filters" :active-count="$activeFilters">
            <form class="commerce-filters" method="get">
                <x-operation-field name="q" :label="$contact ? 'جست‌وجو (کد سفارش، نام مشتری یا موبایل)' : 'کد سفارش'" :required="false" :maxlength="100" :value="$filters['q'] ?? ''"/>
                <div>
                    <label class="label" for="order-status">مرحلهٔ سفارش</label>
                    <select class="input" name="status" id="order-status">
                        <option value="">همه مراحل</option>
                        @foreach(\App\Models\SalesOrder::STATUSES as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-secondary" type="submit">اعمال فیلتر</button>
                @if($activeFilters > 0)
                    <a class="btn btn-secondary btn-sm" href="{{ route('admin.orders.index') }}">پاک‌کردن</a>
                @endif
            </form>
        </x-admin.filter-drawer>
    </div>
@if($orders->isEmpty())
    <x-admin.empty-state title="سفارشی پیدا نشد" icon="shopping-bag">فیلترها را تغییر دهید تا دوباره جست‌وجو کنید.</x-admin.empty-state>
@else
<div tabindex="0" role="region" aria-label="فهرست سفارش‌ها" class="admin-table-wrap">
    <table class="admin-table admin-table--cards commerce-table">
        <caption class="sr-only">فهرست سفارش‌ها</caption>
        <thead>
            <tr>
                <th scope="col">سفارش و اقلام</th>
                @if($contact)<th scope="col">مشتری</th>@endif
                <th scope="col">مرحله</th>
                <th scope="col">مبلغ سفارش (تومان)</th>
                <th scope="col">تاریخ ثبت</th>
                <th scope="col">جزئیات</th>
            </tr>
        </thead>
        <tbody>
        @foreach($orders as $order)
            @php
                $shortCode = strtoupper(substr((string) $order->public_id, 0, 8));
                $firstLine = $order->lines->first();
                $extraLines = max(0, $order->lines->count() - 1);
                $statusTone = match($order->status) {
                    'fulfilled', 'confirmed' => 'success',
                    'reserved' => 'info',
                    'cancelled' => 'danger',
                    default => 'warning',
                };
            @endphp
            <tr>
                <td data-label="سفارش">
                    <div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <strong>سفارش #{{ \App\Support\FaNumber::digits($order->id) }}</strong>
                            <span class="admin-id-chip np-latin" dir="ltr">{{ $shortCode }}</span>
                        </div>
                        @if($firstLine)
                            <small class="admin-table-meta">
                                {{ \App\Support\LatinText::html($firstLine->description) }} × {{ \App\Support\FaNumber::digits($firstLine->quantity) }}@if($extraLines > 0) و {{ \App\Support\FaNumber::digits($extraLines) }} قلم دیگر@endif
                            </small>
                        @endif
                    </div>
                </td>
                @if($contact)
                    <td data-label="مشتری">
                        <div>
                            <strong>{{ $order->customer?->name ?? '—' }}</strong>
                            @if($order->customer?->contact_phone)
                                <small class="admin-table-meta"><bdi dir="ltr">{{ \App\Support\FaNumber::digits($order->customer->contact_phone) }}</bdi></small>
                            @endif
                        </div>
                    </td>
                @endif
                <td data-label="مرحله"><x-admin.status :tone="$statusTone">{{ \App\Models\SalesOrder::STATUSES[$order->status] }}</x-admin.status></td>
                <td data-label="مبلغ سفارش (تومان)"><strong>{{ \App\Support\FinanceMoney::persianOrderToman($order->total) }}</strong></td>
                <td data-label="تاریخ ثبت"><bdi dir="ltr"><x-finance-time :value="$order->created_at" :with-time="false" /></bdi></td>
                <td data-label="جزئیات"><a class="btn btn-secondary btn-sm" href="{{ route('admin.orders.show', $order) }}">مشاهدهٔ سفارش</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
@if($orders->hasPages())<div class="admin-collection-footer">{{ $orders->links() }}</div>@endif
</section>
@endsection
