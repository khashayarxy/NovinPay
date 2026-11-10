@extends('layouts.admin')
@section('title', 'تعمیرات')
@section('content')
<header class="admin-page-heading"><h1>تعمیرات</h1>@can('repairs.intake')<a class="btn btn-primary" href="{{ route('admin.repair-cases.create') }}">پذیرش جدید</a>@endcan</header>
<x-admin.errors />
<section class="admin-panel admin-collection">
<div class="admin-collection-tools">
<form method="post" action="{{ route('admin.repair-cases.search') }}" class="admin-toolbar">@csrf
<div class="admin-form-grid"><x-operation-field name="q" type="search" label="جست‌وجوی پرونده" :required="false" :maxlength="120"/><div><label class="label" for="repair-filter">وضعیت</label><select class="input" id="repair-filter" name="status"><option value="">همه</option>@foreach(['received'=>'پذیرش‌شده','repairing'=>'در حال تعمیر','ready'=>'آماده تحویل','delivered'=>'تحویل‌شده','return_pending'=>'تعمیرنشده / لغوشده'] as $value=>$label)<option value="{{ $value }}" @selected(($criteria['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div></div>
<button class="btn btn-secondary" type="submit">جست‌وجو</button></form>
@if($criteria)<form method="post" action="{{ route('admin.repair-cases.reset') }}">@csrf<button class="admin-text-link">نمایش همه</button></form>@endif
</div>
@if($cases->isEmpty())
    <x-admin.empty-state title="پرونده‌ای پیدا نشد" icon="tool">
        {{ $criteria ? 'جست‌وجو یا وضعیت انتخاب‌شده را تغییر دهید.' : 'پرونده‌ها پس از پذیرش دستگاه در این فهرست نمایش داده می‌شوند.' }}
    </x-admin.empty-state>
@else
    <div class="admin-table-wrap" role="region" aria-label="پرونده‌های تعمیر" tabindex="0">
        <table class="admin-table admin-compact-table" role="table">
            <thead role="rowgroup"><tr role="row">
                <th scope="col" role="columnheader">کد پیگیری</th>
                <th scope="col" role="columnheader">مشتری</th>
                <th scope="col" role="columnheader">دستگاه</th>
                <th scope="col" role="columnheader">وضعیت</th>
            </tr></thead>
            <tbody role="rowgroup">
                @foreach($cases as $case)
                    <tr role="row">
                        <td role="cell"><a class="admin-text-link" href="{{ route('admin.repair-cases.show', $case) }}"><span class="sr-only">پروندهٔ </span><bdi>{{ \App\Support\FaNumber::digits($case->tracking_reference) }}</bdi><x-home.icon name="arrow-left" /></a></td>
                        <td role="cell"><span class="admin-mobile-cell-label" aria-hidden="true">مشتری</span>{{ $canReadCustomer ? $case->customer_name : '—' }}</td>
                        <td role="cell"><span class="admin-mobile-cell-label" aria-hidden="true">دستگاه</span>{{ $canReadCustomer ? $case->device_model : '—' }}</td>
                        <td role="cell"><x-admin.status :tone="match($case->status->value) { 'delivered', 'ready' => 'success', 'repairing' => 'info', 'return_pending', 'returned' => 'warning', default => 'neutral' }">{{ $case->status->label() }}</x-admin.status></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@if($cases->hasPages())<div class="admin-collection-footer">{{ $cases->links() }}</div>@endif
</section>
@endsection
