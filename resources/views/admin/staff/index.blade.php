@extends('layouts.admin')
@section('title', 'همکاران و دسترسی‌ها')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">مدیریت دسترسی</p><h1>همکاران و دسترسی‌ها</h1><p>ایجاد حساب، تعیین مجوزها و کنترل فعال بودن همکاران</p></div><a href="{{ route('admin.staff.create') }}" class="btn btn-primary">تعریف همکار جدید</a></header>
<div class="admin-notice"><h2>حساب مدیر کل محافظت می‌شود</h2><p>نقش، مجوز و فعال بودن مدیر کل از این صفحه قابل تغییر نیست. هر تغییر حساب همکار، نشست‌های قبلی او را لغو می‌کند.</p></div>
<div class="admin-panel admin-table-wrap" role="region" aria-label="فهرست حساب‌های مدیریت" tabindex="0"><table class="admin-table"><thead><tr><th scope="col">همکار</th><th scope="col">نقش</th><th scope="col">وضعیت</th><th scope="col">دسترسی‌ها</th><th scope="col">عملیات</th></tr></thead><tbody>
@forelse($accounts as $account)<tr><td><strong>{{ $account->user->name }}</strong><p dir="ltr">{{ $account->user->email }}</p></td><td>{{ $account->isSuperAdmin() ? 'مدیر کل محافظت‌شده' : 'کارمند' }}</td><td><span class="badge {{ $account->is_active ? 'badge-success' : 'badge-warning' }}">{{ $account->is_active ? 'فعال' : 'غیرفعال' }}</span>@if($account->must_change_password)<p>نیازمند تغییر گذرواژه</p>@endif</td><td>{{ $account->isSuperAdmin() ? 'تمام مجوزهای فعلی' : \App\Support\FaNumber::digits(count($account->permissions ?? [])).' مجوز' }}</td><td>@if(!$account->isSuperAdmin())<a href="{{ route('admin.staff.edit', $account) }}" class="admin-text-link">ویرایش حساب</a>@else<span class="admin-muted">محافظت‌شده</span>@endif</td></tr>@empty<tr><td colspan="5">حسابی برای نمایش وجود ندارد.</td></tr>@endforelse
</tbody></table></div><div class="mt-6">{{ $accounts->links() }}</div>
@endsection
