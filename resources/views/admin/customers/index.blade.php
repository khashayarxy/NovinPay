@extends('layouts.admin')
@section('title', 'لیست مشتریان')
@section('content')
<header class="admin-page-heading">
    <div>
        <h1>لیست مشتریان</h1>
        <p>پروفایل‌های ثبت‌شده و خلاصهٔ سفارش‌ها و تعمیرات متصل.</p>
    </div>
    @can('customers.manage')
        @can('customers.contact')
            <a class="btn btn-primary" href="{{ route('admin.customers.create') }}"><x-home.icon name="user"/> ثبت مشتری جدید</a>
        @endcan
    @endcan
</header>

<div class="admin-stat-grid">
    <div class="admin-stat-card">
        <div class="admin-stat-card__top">
            <span class="admin-stat-card__label">همهٔ پروفایل‌ها</span>
            <span class="admin-stat-card__icon"><x-home.icon name="user" /></span>
        </div>
        <strong class="admin-stat-card__value">{{ \App\Support\FaNumber::digits($total) }}</strong>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__top">
            <span class="admin-stat-card__label">پروفایل‌های فعال</span>
            <span class="admin-stat-card__icon"><x-home.icon name="shield-check" /></span>
        </div>
        <strong class="admin-stat-card__value">{{ \App\Support\FaNumber::digits($active) }}</strong>
    </div>
</div>

<x-admin.errors/>
@php
    $activeCustomerFilters = (int) (isset($criteria['digest']) || isset($criteria['query_cipher'])) + (int) (($criteria['active'] ?? '') !== '');
@endphp
<section class="admin-panel admin-collection">
    <div class="admin-collection-tools">
        <x-admin.filter-drawer id="customer-filters" :active-count="$activeCustomerFilters">
            <form class="crm-filters" method="post" action="{{ route('admin.customers.search') }}">
                @csrf
                @if($contact)
                    <x-operation-field name="q" label="جست‌وجوی نام یا شماره موبایل" :required="false" :maxlength="120"/>
                @endif
                <div>
                    <label class="label" for="customer-active">وضعیت پروفایل</label>
                    <select class="input" name="active" id="customer-active">
                        <option value="">همه</option>
                        <option value="1" @selected(($criteria['active'] ?? '') === '1')>فعال</option>
                        <option value="0" @selected(($criteria['active'] ?? '') === '0')>بایگانی‌شده</option>
                    </select>
                </div>
                <button class="btn btn-primary" type="submit">اعمال جستجو و فیلتر</button>
            </form>
        </x-admin.filter-drawer>
        @if(isset($criteria['digest']) || isset($criteria['query_cipher']))
            <p class="admin-hint">جستجوی خصوصی فعال است؛ برای نمایش همه، فرم را خالی ارسال کنید.</p>
        @endif
        @if(!$contact)
            <p class="admin-hint">مشاهدهٔ نام و شمارهٔ مشتری به مجوز جداگانه نیاز دارد.</p>
        @endif
    </div>

    <div class="admin-table-wrap" tabindex="0" role="region" aria-label="پروفایل‌های مشتری">
        <table @class(['admin-table', 'admin-table--cards', 'crm-table-empty' => $customers->isEmpty()])>
            <caption class="sr-only">پروفایل‌های مشتری</caption>
            <thead>
                <tr>
                    <th scope="col">مشتری</th>
                    @if($contact)<th scope="col">شماره موبایل</th>@endif
                    <th scope="col">سوابق</th>
                    <th scope="col">وضعیت</th>
                    <th scope="col">پروفایل</th>
                </tr>
            </thead>
            <tbody>
            @forelse($customers as $customer)
                @php
                    $repairTotal = (int) ($customer->repair_cases_count ?? 0) + (int) ($customer->repair_requests_count ?? 0);
                    $orderTotal = (int) ($customer->sales_orders_count ?? 0);
                @endphp
                <tr>
                    <td data-label="مشتری">
                        <div>
                            @if($contact)
                                <strong>{{ $customer->name }}</strong>
                            @else
                                <strong>مشتری #{{ \App\Support\FaNumber::digits($customer->id) }}</strong>
                            @endif
                        </div>
                    </td>
                    @if($contact)
                        <td data-label="شماره موبایل"><bdi dir="ltr">{{ \App\Support\FaNumber::digits($customer->contact_phone) }}</bdi></td>
                    @endif
                    <td data-label="سوابق">
                        <span class="admin-muted">
                            {{ \App\Support\FaNumber::digits($orderTotal) }} سفارش · {{ \App\Support\FaNumber::digits($repairTotal) }} تعمیر
                        </span>
                    </td>
                    <td data-label="وضعیت">
                        <x-admin.status :tone="$customer->merged_into_id ? 'info' : ($customer->is_active ? 'success' : 'warning')">
                            {{ $customer->merged_into_id ? 'تطبیق‌یافته' : ($customer->is_active ? 'فعال' : 'بایگانی‌شده') }}
                        </x-admin.status>
                    </td>
                    <td data-label="پروفایل"><a class="btn btn-secondary btn-sm" href="{{ route('admin.customers.show', $customer) }}">مشاهدهٔ پروفایل</a></td>
                </tr>
            @empty
                <tr><td colspan="{{ $contact ? 5 : 4 }}"><div class="admin-empty"><x-home.icon name="user"/><h2>مشتری پیدا نشد</h2><p>جست‌وجو را پاک کنید یا پس از بررسی هویت، مشتری جدید ثبت کنید.</p></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($customers->hasPages())<div class="admin-collection-footer">{{ $customers->links() }}</div>@endif
</section>
@endsection
