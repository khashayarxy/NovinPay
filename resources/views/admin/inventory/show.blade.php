@extends('layouts.admin')
@section('title', 'جزئیات موجودی')
@section('content')
<header class="admin-page-heading">
    <div>
        <span class="admin-eyebrow">{{ 'کالای '.\App\Enums\ProductCondition::from($item->condition)->label() }} · {{ $item->serialized ? 'کنترل سریالی' : 'کنترل تعدادی' }}</span>
        <h1>{{ $item->name }}</h1>
        <div class="admin-id-row">
            <span class="repair-code-chip" dir="ltr">{{ $item->sku }}</span>
            <span class="admin-id-chip" title="{{ $item->public_id }}">#{{ strtoupper(substr((string) $item->public_id, 0, 8)) }}</span>
            <button type="button" class="admin-copy-mini" data-copy-code="{{ $item->public_id }}"><span data-copy-code-label>کپی شناسه انبار</span></button>
        </div>
    </div>
    <div class="admin-actions">
        @can('orders.create')<a class="btn btn-secondary btn-sm" href="{{ route('admin.orders.create') }}">+ ساخت سفارش</a>@endcan
        <a class="btn btn-secondary btn-sm" href="{{ route('admin.inventory.index') }}">فهرست انبار</a>
    </div>
</header>
<x-admin.errors/>

<div class="admin-stat-grid">
    @foreach([['موجود فیزیکی', $item->on_hand], ['رزروشده', $item->reserved], ['آزاد برای فروش', $item->available], ['تحویل‌شده', $item->dispatched]] as [$label, $count])
        <div class="admin-stat-card">
            <span class="admin-stat-card__label">{{ $label }}</span>
            <strong class="admin-stat-card__value">{{ \App\Support\FaNumber::digits($count) }}</strong>
        </div>
    @endforeach
</div>

<div class="repair-layout mt-4">
    <div class="repair-main-stack">
        @if($item->serialized)
            <section class="admin-panel admin-collection">
                <div class="admin-collection-tools"><h2>دستگاه‌های سریالی</h2></div>
                <div tabindex="0" role="region" aria-label="دستگاه‌های سریالی" class="admin-table-wrap">
                    <table class="admin-table commerce-table">
                        <caption class="sr-only">سریال و وضعیت دستگاه</caption>
                        <thead><tr><th scope="col">سریال</th><th scope="col">وضعیت</th></tr></thead>
                        <tbody>
                            @forelse($units as $unit)
                                <tr>
                                    <td dir="ltr">{{ $unit->serial }}</td>
                                    <td><x-admin.status :tone="$unit->state === 'on_hand' ? 'success' : ($unit->state === 'reserved' ? 'info' : 'neutral')">{{ ['on_hand'=>'آزاد در انبار','reserved'=>'رزروشده','dispatched'=>'تحویل‌شده','removed'=>'خارج‌شده با اصلاح'][$unit->state] }}</x-admin.status></td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="admin-muted">هنوز دستگاهی دریافت نشده است.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($units->hasPages())<div class="admin-collection-footer">{{ $units->links() }}</div>@endif
            </section>
        @endif
        <section class="admin-panel">
            <div class="admin-panel-heading"><h2>تاریخچهٔ موجودی</h2></div>
            <x-stock-movements :movements="$movements"/>
            {{ $movements->links() }}
        </section>
    </div>

    <aside class="repair-action-stack" aria-label="عملیات موجودی">
        @can('inventory.receive')
            <section class="admin-panel">
                <div class="admin-panel-heading"><h2>دریافت موجودی</h2></div>
                <x-commerce-operation :action="route('admin.inventory.receive',$item)" :version="$item->version" title="ثبت دریافت واقعی">
                    <x-operation-field name="quantity" label="تعداد دریافت" type="number" value="1"/>
                    @if($item->serialized)
                        <x-operation-field name="serials" label="سریال‌ها؛ هر خط یک دستگاه، حداکثر ۱۰۰" kind="textarea" :maxlength="11000"/>
                    @endif
                </x-commerce-operation>
            </section>
        @endcan

        @can('inventory.adjust')
            <section class="admin-panel">
                <div class="admin-panel-heading"><h2>اصلاح موجودی</h2></div>
                <p class="admin-hint mb-2">تعداد منفی فقط از موجودی آزاد کسر می‌شود.</p>
                <x-commerce-operation :action="route('admin.inventory.adjust',$item)" :version="$item->version" title="ثبت اصلاح موجودی">
                    <div>
                        <label class="label" for="adjust-quantity">تغییر تعداد (مثبت یا منفی)</label>
                        <input class="input" id="adjust-quantity" name="quantity" type="number" min="-10000" max="10000" required dir="ltr">
                    </div>
                    @if($item->serialized)
                        <x-operation-field name="serials" label="سریال‌های دریافت یا خروج؛ هر خط یک مورد" kind="textarea" :maxlength="11000"/>
                    @endif
                </x-commerce-operation>
            </section>
        @endcan

        @can('inventory.configure')
            <section class="admin-panel">
                <div class="admin-panel-heading"><h2>آستانهٔ کمبود</h2></div>
                <x-commerce-operation :action="route('admin.inventory.configure',$item)" :version="$item->version" title="ثبت آستانه">
                    <x-operation-field name="low_stock_threshold" label="حد هشدار موجودی آزاد" type="number" :value="$item->low_stock_threshold"/>
                </x-commerce-operation>
            </section>
        @endcan
    </aside>
</div>
@endsection
