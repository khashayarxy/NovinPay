@extends('layouts.admin')
@section('title', 'مدیریت حساب‌های آزمایشی')
@section('content')
<header class="admin-page-heading"><div><h1>مدیریت حساب‌های آزمایشی</h1><p>فقط شماره‌هایی نمایش داده می‌شوند که در تنظیمات خصوصی سایت برای آزمایش مجاز شده‌اند.</p></div></header>
<x-admin.errors />
@forelse($rows as $row)
    @php($account = $row['account'])
    <section class="admin-panel mb-4">
        <h2><bdi dir="ltr">{{ \App\Support\FaNumber::digits($row['masked']) }}</bdi></h2>
        <dl class="mt-3 grid gap-2 sm:grid-cols-2">
            <div><dt>وضعیت ثبت‌نام</dt><dd>{{ $account ? 'ثبت شده' : 'آمادهٔ ثبت‌نام' }}</dd></div>
            <div><dt>رمز عبور</dt><dd>{{ $account?->password ? 'تنظیم شده' : 'تنظیم نشده' }}</dd></div>
            <div><dt>تأیید شماره</dt><dd>{{ $account ? 'آزمایشی، بدون تأیید پیامکی' : 'در انتظار تأیید آزمایشی' }}</dd></div>
            <div><dt>آخرین ثبت‌نام یا ورود</dt><dd>{{ \App\Support\FinanceTime::display($account?->last_login_at ?? $account?->created_at) }}</dd></div>
        </dl>
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <form class="admin-form" method="post" action="{{ route('admin.test-customers.code', $row['key']) }}">
                @csrf
                <label class="label">رمز عبور فعلی مدیریت<input class="input" type="password" name="current_password" autocomplete="current-password" required></label>
                <button class="btn btn-primary">نمایش کد آزمایشی فعال</button>
            </form>
            @if($account)
                <form class="admin-form" method="post" action="{{ route('admin.test-customers.reset', $account->public_id) }}" data-confirm="حساب آزمایشی بازنشانی شود؟ نشست‌ها، رمز عبور و آدرس‌های آزمایشی حذف می‌شوند.">
                    @csrf
                    <label class="label">رمز عبور فعلی مدیریت<input class="input" type="password" name="current_password" autocomplete="current-password" required></label>
                    <label><input type="checkbox" name="confirm" value="1" required> بازنشانی این حساب آزمایشی را تأیید می‌کنم.</label>
                    <button class="btn btn-secondary">بازنشانی حساب تست</button>
                </form>
            @endif
        </div>
    </section>
@empty
    <section class="admin-panel"><p>ورود آزمایشی فعال نیست یا شماره‌ای برای آزمایش تعریف نشده است.</p></section>
@endforelse
@endsection
