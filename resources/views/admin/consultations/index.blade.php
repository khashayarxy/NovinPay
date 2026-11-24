@extends('layouts.admin')
@section('title', 'لیست مشاوره‌ها')
@section('content')
<header class="admin-page-heading"><div><h1>لیست مشاوره‌ها</h1></div><a class="admin-text-link" href="{{ route('consultation') }}">مشاهدهٔ فرم عمومی ←</a></header>
<div class="crm-summary"><dl><div><dt>جدید</dt><dd>{{ \App\Support\FaNumber::digits($newCount) }}</dd></div><div><dt>باز</dt><dd>{{ \App\Support\FaNumber::digits($openCount) }}</dd></div></dl><p>هیچ زمان پاسخی به مشتری وعده داده نشده است.</p></div><x-admin.errors/>
@php
    $activeConsultFilters = (int) (! empty($criteria['phone_digest']) || ! empty($criteria['query_cipher']))
        + (int) (! empty($criteria['status']))
        + (int) (! empty($criteria['topic']))
        + (int) (! empty($criteria['assigned_to']));
@endphp
<section class="admin-panel">
<x-admin.filter-drawer id="consultation-filters" :active-count="$activeConsultFilters">
    <form class="crm-filters" method="post" action="{{ route('admin.consultations.search') }}">@csrf
    @if($contact)<x-operation-field name="q" label="جست‌وجوی نام یا شماره موبایل" :required="false" :maxlength="120"/>@endif
    <div><label for="consult-status" class="label">مرحله</label><select id="consult-status" name="status" class="input"><option value="">همه</option>@foreach(\App\Models\Consultation::STATUSES as $value=>$label)<option value="{{ $value }}" @selected(($criteria['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
    <div><label for="consult-topic" class="label">موضوع</label><select id="consult-topic" name="topic" class="input"><option value="">همه</option>@foreach(\App\Models\Consultation::TOPICS as $value=>$label)<option value="{{ $value }}" @selected(($criteria['topic'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
    <div><label for="consult-assignee" class="label">همکار مسئول</label><select id="consult-assignee" name="assigned_to" class="input"><option value="">همه</option>@foreach($staff as $user)<option value="{{ $user->id }}" @selected((string)($criteria['assigned_to'] ?? '') === (string)$user->id)>{{ $user->name }}</option>@endforeach</select></div><button class="btn btn-primary" type="submit">اعمال جستجو و فیلتر</button></form>
</x-admin.filter-drawer>
@if(isset($criteria['phone_digest']) || isset($criteria['query_cipher']))<p class="admin-hint">جستجوی خصوصی فعال است. برای پاک‌کردن، فرم را خالی ارسال کنید.</p>@endif
<div class="admin-table-wrap mt-4"><table @class(['admin-table', 'admin-table--cards', 'crm-table-empty' => $consultations->isEmpty()])><caption class="sr-only">درخواست‌های مشاوره</caption><thead><tr><th scope="col">موضوع / زمان</th>@if($contact)<th scope="col">مشتری</th>@endif<th scope="col">مرحله</th><th scope="col">مسئول</th><th scope="col">جزئیات</th></tr></thead><tbody>
@forelse($consultations as $record)
    <tr>
        <td data-label="موضوع"><strong>{{ \App\Models\Consultation::TOPICS[$record->topic] }}</strong><small class="admin-table-meta"><x-finance-time :value="$record->created_at" /></small></td>
        @if($contact)<td data-label="مشتری"><strong>{{ $record->customer_name }}</strong><small class="admin-table-meta"><bdi dir="ltr">{{ \App\Support\FaNumber::digits($record->contact_phone) }}</bdi></small></td>@endif
        <td data-label="مرحله"><span class="admin-status">{{ \App\Models\Consultation::STATUSES[$record->status] }}</span></td>
        <td data-label="مسئول">{{ $record->assignee?->name ?? 'بدون مسئول' }}</td>
        <td data-label="جزئیات"><a class="admin-text-link" href="{{ route('admin.consultations.show', $record) }}">بررسی درخواست ←</a></td>
    </tr>
@empty
    <tr><td colspan="{{ $contact ? 5 : 4 }}"><div class="admin-empty"><x-home.icon name="headphones"/><h2>درخواستی پیدا نشد</h2><p>درخواست‌های ثبت‌شده در این فهرست نمایش داده می‌شوند. فیلترها را هم بررسی کنید.</p></div></td></tr>
@endforelse
</tbody></table></div>{{ $consultations->links() }}</section>
@endsection
