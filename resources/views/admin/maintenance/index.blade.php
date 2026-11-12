@extends('layouts.admin')
@section('title', 'وضعیت و نگهداری سایت')
@section('content')
<div class="admin-form-surface">
<header class="admin-page-heading"><div><h1>وضعیت و نگهداری سایت</h1></div><a class="btn btn-secondary" href="{{ route('admin.maintenance.preview') }}">پیش‌نمایش صفحهٔ نگهداری</a></header>
<x-admin.errors />
@if(session('maintenance_warning'))<div class="admin-notice" role="alert">{{ session('maintenance_warning') }}</div>@endif
<section class="admin-panel" aria-labelledby="maintenance-state-title">
    <div class="admin-panel-heading">
        <h2 id="maintenance-state-title">وضعیت فعلی: {{ $fault ? 'خطای خواندن وضعیت؛ دسترسی عمومی بسته است' : ($current['enabled'] ? 'فعال' : 'غیرفعال') }}</h2>
        <span @class(['admin-state-dot', 'admin-state-dot--attention' => $fault || $current['enabled']]) aria-hidden="true"></span>
    </div>
    <p class="admin-hint">با فعال‌سازی، بازدیدکنندگان صفحهٔ نگهداری را می‌بینند. درخواست‌های قبلی و کارهای پس‌زمینه ممکن است ادامه پیدا کنند.</p>
    @if($fault)
        <div class="admin-notice" role="alert">وضعیت سایت قابل خواندن نیست. از راهنمای بازیابی اضطراری استفاده کنید؛ تا رفع خطا امکان تغییر از این فرم وجود ندارد.</div>
    @else
        @if($current['updated_at'])<p class="admin-hint">آخرین تغییر: <x-finance-time :value="\Carbon\Carbon::parse($current['updated_at'])" /></p>@endif
        @can('maintenance.manage')
        <form class="admin-form admin-password-form" method="post" action="{{ route($current['enabled'] ? 'admin.maintenance.disable' : 'admin.maintenance.enable') }}">
            @csrf
            <input type="hidden" name="version" value="{{ $current['version'] }}">
            <div><label class="label" for="current_password">رمز عبور فعلی شما</label><input class="input" type="password" name="current_password" id="current_password" required autocomplete="current-password" maxlength="255"></div>
            <label class="admin-checkbox"><input type="checkbox" name="confirm" value="1" required> تأیید می‌کنم که حالت نگهداری سایت {{ $current['enabled'] ? 'غیرفعال' : 'فعال' }} شود.</label>
            <div class="admin-form-footer"><button class="btn btn-primary" type="submit">{{ $current['enabled'] ? 'غیرفعال‌سازی نگهداری و بازکردن سایت' : 'فعال‌سازی حالت نگهداری' }}</button></div>
        </form>
        @else<p class="admin-hint">این حساب فقط اجازهٔ مشاهده و پیش‌نمایش دارد.</p>@endcan
    @endif
</section>
<details class="admin-panel mt-3"><summary>نگهداری هنگام به‌روزرسانی</summary><p class="admin-hint">این کنترل، نگهداری هنگام به‌روزرسانی را لغو نمی‌کند. در صورت قطع دسترسی مدیریت، از راهنمای بازیابی اضطراری استفاده کنید.</p></details>
</div>
@endsection
