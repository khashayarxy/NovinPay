@extends('layouts.admin')
@section('title', 'مشتریان')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">ارتباط با مشتریان</p><h1>دفتر مشتریان</h1><p>پروفایل‌های بررسی‌شده؛ مستقل از درخواست آنلاین و پذیرش فیزیکی.</p></div>@can('customers.manage') @can('customers.contact')<a class="btn btn-primary" href="{{ route('admin.customers.create') }}"><x-home.icon name="user"/> ثبت مشتری</a>@endcan @endcan</header>
<div class="crm-summary"><span>همهٔ پروفایل‌ها <strong>{{ \App\Support\FaNumber::digits($total) }}</strong></span><span>فعال <strong>{{ \App\Support\FaNumber::digits($active) }}</strong></span><p>شمارهٔ مشترک به معنی هویت یکسان نیست.</p></div>
<x-admin.errors/>
<section class="admin-panel">
<form class="crm-filters" method="post" action="{{ route('admin.customers.search') }}">@csrf
@if($contact)<x-operation-field name="q" label="جستجوی دقیق نام کامل یا همراه" :required="false" :maxlength="120"/>@endif
<div><label class="label" for="customer-active">وضعیت پروفایل</label><select class="input" name="active" id="customer-active"><option value="">همه</option><option value="1" @selected(($criteria['active'] ?? '') === '1')>فعال</option><option value="0" @selected(($criteria['active'] ?? '') === '0')>بایگانی‌شده</option></select></div><button class="btn btn-primary" type="submit">اعمال جستجو و فیلتر</button></form>
@if(isset($criteria['digest']))<p class="admin-hint">جستجوی خصوصی فعال است. برای پاک‌کردن، فرم را خالی ارسال کنید.</p>@endif
@if(!$contact)<p class="admin-hint">نام و شمارهٔ مشتری به مجوز جداگانه نیاز دارد.</p>@endif
<div class="admin-table-wrap mt-4"><table @class(['admin-table', 'crm-table-empty' => $customers->isEmpty()])><caption class="sr-only">پروفایل‌های مشتری</caption><thead><tr><th scope="col">مشتری / شناسه</th>@if($contact)<th scope="col">شمارهٔ همراه</th>@endif<th scope="col">وضعیت</th><th scope="col">پروفایل</th></tr></thead><tbody>
@forelse($customers as $customer)<tr><td>@if($contact)<strong>{{ $customer->name }}</strong>@endif<small class="crm-code">{{ $customer->public_id }}</small></td>@if($contact)<td dir="ltr">{{ $customer->contact_phone }}</td>@endif<td><span class="admin-status">{{ $customer->merged_into_id ? 'تطبیق‌یافته' : ($customer->is_active ? 'فعال' : 'بایگانی‌شده') }}</span></td><td><a class="admin-text-link" href="{{ route('admin.customers.show', $customer) }}">مشاهدهٔ پروفایل ←</a></td></tr>@empty<tr><td colspan="4"><div class="admin-empty"><x-home.icon name="user"/><h2>مشتری‌ای پیدا نشد</h2><p>جستجو را پاک کنید یا پس از بررسی هویت، مشتری تازه ثبت کنید.</p></div></td></tr>@endforelse
</tbody></table></div>{{ $customers->links() }}
</section>
@endsection
