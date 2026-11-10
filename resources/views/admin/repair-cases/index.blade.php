@extends('layouts.admin')
@section('title', 'تعمیرات')
@section('content')
<header class="admin-page-heading"><h1>تعمیرات</h1>@can('repairs.intake')<a class="btn btn-primary" href="{{ route('admin.repair-cases.create') }}">پذیرش جدید</a>@endcan</header>
<x-admin.errors />
<section class="admin-panel"><form method="post" action="{{ route('admin.repair-cases.search') }}" class="admin-toolbar">@csrf
<div class="admin-form-grid"><x-operation-field name="q" type="search" label="جست‌وجوی پرونده" :required="false" :maxlength="120"/><div><label class="label" for="repair-filter">وضعیت</label><select class="input" id="repair-filter" name="status"><option value="">همه</option>@foreach(['received'=>'پذیرش‌شده','repairing'=>'در حال تعمیر','ready'=>'آماده تحویل','delivered'=>'تحویل‌شده','return_pending'=>'تعمیرنشده / لغوشده'] as $value=>$label)<option value="{{ $value }}" @selected(($criteria['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div></div>
<button class="btn btn-secondary" type="submit">جست‌وجو</button></form>
@if($criteria)<form method="post" action="{{ route('admin.repair-cases.reset') }}" class="mt-3">@csrf<button class="admin-text-link">نمایش همه</button></form>@endif</section>
<section class="admin-panel admin-table-wrap mt-5"><table class="admin-table"><thead><tr><th>کد پیگیری</th><th>مشتری</th><th>دستگاه</th><th>وضعیت</th><th><span class="sr-only">پرونده</span></th></tr></thead><tbody>
@forelse($cases as $case)<tr><td><bdi>{{ \App\Support\FaNumber::digits($case->tracking_reference) }}</bdi></td><td>{{ $canReadCustomer ? $case->customer_name : '—' }}</td><td>{{ $canReadCustomer ? $case->device_model : '—' }}</td><td>{{ $case->status->label() }}</td><td><a class="admin-text-link" href="{{ route('admin.repair-cases.show', $case) }}">مشاهده</a></td></tr>@empty<tr><td colspan="5">پرونده‌ای پیدا نشد.</td></tr>@endforelse
</tbody></table>{{ $cases->links() }}</section>
@endsection
