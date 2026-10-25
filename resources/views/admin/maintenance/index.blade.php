@extends('layouts.admin')
@section('title', 'حالت نگهداری سایت')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">کنترل وضعیت سایت</p><h1>حالت نگهداری سایت</h1><p>دسترسی بازدیدکنندگان را مدیریت کنید؛ ورود مدیران مجاز همچنان امکان‌پذیر است.</p></div><a class="btn btn-secondary" href="{{ route('admin.maintenance.preview') }}">پیش‌نمایش صفحهٔ نگهداری</a></header>
<x-admin.errors />
@if(session('maintenance_warning'))<div class="admin-notice" role="alert">{{ session('maintenance_warning') }}</div>@endif
<section class="admin-panel" aria-labelledby="maintenance-state-title">
    <h2 id="maintenance-state-title">وضعیت فعلی: {{ $fault ? 'خطای خواندن وضعیت؛ دسترسی عمومی بسته است' : ($current['enabled'] ? 'فعال' : 'غیرفعال') }}</h2>
    <p class="admin-hint">با فعال‌سازی، بازدیدکنندگان صفحهٔ نگهداری را می‌بینند. درخواست‌های قبلی و کارهای پس‌زمینه ممکن است ادامه پیدا کنند.</p>
    @if($fault)
        <div class="admin-notice" role="alert">وضعیت سایت قابل خواندن نیست. از راهنمای بازیابی اضطراری استفاده کنید؛ تا رفع خطا امکان تغییر از این فرم وجود ندارد.</div>
    @else
        @if($current['updated_at'])<p class="admin-hint">آخرین تغییر: {{ \App\Support\RepairTime::display(\Carbon\Carbon::parse($current['updated_at'])) }}</p>@endif
        @can('maintenance.manage')
        <form class="admin-form admin-password-form" method="post" action="{{ route($current['enabled'] ? 'admin.maintenance.disable' : 'admin.maintenance.enable') }}">
            @csrf
            <input type="hidden" name="version" value="{{ $current['version'] }}">
            <div><label class="label" for="current_password">گذرواژهٔ فعلی شما</label><input class="input" type="password" name="current_password" id="current_password" required autocomplete="current-password" maxlength="255"></div>
            <label class="admin-hint" style="display:flex;align-items:center;gap:8px;min-height:44px"><input type="checkbox" name="confirm" value="1" required> تأیید می‌کنم که حالت نگهداری سایت {{ $current['enabled'] ? 'غیرفعال' : 'فعال' }} شود.</label>
            <button class="btn btn-primary" type="submit">{{ $current['enabled'] ? 'غیرفعال‌سازی نگهداری و بازکردن سایت' : 'فعال‌سازی حالت نگهداری' }}</button>
        </form>
        @else<p class="admin-hint">این حساب فقط اجازهٔ مشاهده و پیش‌نمایش دارد.</p>@endcan
    @endif
</section>
<div class="admin-notice"><h2>نگهداری هنگام به‌روزرسانی جداگانه کنترل می‌شود</h2><p>این کنترل، نگهداری هنگام به‌روزرسانی را لغو نمی‌کند. اگر ورود به مدیریت امکان‌پذیر نباشد، از راهنمای بازیابی اضطراری استفاده کنید.</p></div>
@endsection
