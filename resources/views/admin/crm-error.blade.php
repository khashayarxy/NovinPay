@extends('layouts.admin')
@section('title', 'عملیات ثبت نشد')
@section('content')
@if(request()->is('admin/orders*','admin/inventory*'))<div class="admin-actions mb-4">@can('orders.view')<a class="btn btn-secondary" href="{{ route('admin.orders.index') }}">سفارش‌ها</a>@endcan @can('inventory.view')<a class="btn btn-secondary" href="{{ route('admin.inventory.index') }}">موجودی کالا</a>@endcan</div>@endif
<header class="admin-page-heading"><h1>عملیات ثبت نشد</h1></header><section class="admin-panel admin-form-surface" role="alert"><p>{{ $message }}</p><p class="admin-hint">رکورد را دوباره از فهرست باز کنید. اطلاعات شخصی فرم بازپخش نمی‌شود؛ نسخهٔ جدید را پیش از ثبت بررسی کنید.</p><div class="admin-actions">@can('customers.view')<a class="btn btn-primary" href="{{ route('admin.customers.index') }}">فهرست مشتریان</a>@endcan @can('consultations.view')<a class="btn btn-secondary" href="{{ route('admin.consultations.index') }}">صندوق مشاوره</a>@endcan</div></section>
@endsection
