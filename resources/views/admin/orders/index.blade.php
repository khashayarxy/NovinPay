@extends('layouts.admin')
@section('title', 'سفارش‌های داخلی')
@section('content')
<header class="admin-page-heading"><div><h1>سفارش‌های داخلی</h1><p>ثبت توافق فروش و تحویل؛ پرداخت و حسابداری در این بخش انجام نمی‌شود.</p></div>@can('orders.create')<a class="btn btn-primary" href="{{ route('admin.orders.create') }}">سفارش جدید</a>@endcan</header><x-admin.errors/>
<section class="admin-panel admin-collection"><div class="admin-collection-tools"><form class="commerce-filters" method="get"><x-operation-field name="reference" label="شناسهٔ کامل سفارش" :required="false" :maxlength="36" :value="$filters['reference'] ?? ''"/><div><label class="label" for="order-status">مرحلهٔ سفارش</label><select class="input" name="status" id="order-status"><option value="">همه</option>@foreach(\App\Models\SalesOrder::STATUSES as $value=>$label)<option value="{{ $value }}" @selected(($filters['status'] ?? '')===$value)>{{ $label }}</option>@endforeach</select></div><button class="btn btn-primary">اعمال فیلتر</button><a class="admin-text-link" href="{{ route('admin.orders.index') }}">پاک‌کردن</a></form></div>
@if($orders->isEmpty())
    <x-admin.empty-state title="سفارشی پیدا نشد" icon="shopping-bag">فیلترها را تغییر دهید تا دوباره جست‌وجو کنید.</x-admin.empty-state>
@else
<div tabindex="0" role="region" aria-label="فهرست سفارش‌ها" class="admin-table-wrap"><table class="admin-table commerce-table"><caption class="sr-only">فهرست سفارش‌ها</caption><thead><tr><th scope="col">سفارش</th><th scope="col">مرحله</th><th scope="col">توافق فروش (تومان)</th><th scope="col">تاریخ ثبت</th><th scope="col">جزئیات</th></tr></thead><tbody>@foreach($orders as $order)<tr><td><strong>سفارش {{ \App\Support\FaNumber::digits($order->id) }}</strong><small class="crm-code">{{ $order->public_id }}</small></td><td><span class="repair-stage">{{ \App\Models\SalesOrder::STATUSES[$order->status] }}</span></td><td>{{ \App\Support\FaNumber::digits($order->total) }}</td><td dir="ltr">{{ \App\Support\FinanceTime::display($order->created_at, withTime: false, withZone: false) }}</td><td><a class="admin-text-link" href="{{ route('admin.orders.show',$order) }}">مشاهدهٔ سفارش ←</a></td></tr>@endforeach</tbody></table></div>
@endif
@if($orders->hasPages())<div class="admin-collection-footer">{{ $orders->links() }}</div>@endif
</section>
@endsection
