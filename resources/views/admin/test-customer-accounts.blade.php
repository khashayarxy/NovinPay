@extends('layouts.admin')
@section('title', 'مدیریت حساب‌های آزمایشی')
@section('content')
<header class="admin-page-heading"><div><h1>حساب‌های آزمایشی</h1></div></header>
<x-admin.errors />
@forelse($rows as $row)
    @php($account = $row['account'])
    @php($scope = $row['scope'])
    <section class="admin-panel mb-3">
        <div class="admin-panel-heading">
            <h2><bdi dir="ltr">{{ \App\Support\FaNumber::digits($row['masked']) }}</bdi></h2>
            <x-admin.status :tone="$account ? 'info' : 'neutral'">{{ $account ? 'ثبت شده' : 'آمادهٔ ثبت‌نام' }}</x-admin.status>
        </div>
        <dl class="admin-record-facts mt-3">
            <div><dt>رمز عبور</dt><dd>{{ $account?->password ? 'تنظیم شده' : 'تنظیم نشده' }}</dd></div>
            <div><dt>آخرین ثبت‌نام یا ورود</dt><dd>{{ \App\Support\FinanceTime::display($account?->last_login_at ?? $account?->created_at) }}</dd></div>
            @if($scope)
                <div><dt>درخواست‌های ثبت‌شده با این حساب</dt><dd>{{ \App\Support\FaNumber::digits(count($scope['requests'])) }}</dd></div>
                <div><dt>وضعیت بازنشانی</dt><dd>{{ $scope['reason'] ?? 'آمادهٔ بازنشانی' }}</dd></div>
            @endif
        </dl>
        <div class="mt-3 grid gap-3 lg:grid-cols-2">
            @if($row['sms'])
                <p class="admin-hint">کد ورود آزمایشی به این شماره پیامک می‌شود.</p>
            @else
            <details class="admin-section" @if($errors->has('current_password') && !$account) open @endif>
                <summary>کد ورود آزمایشی</summary>
            <p class="admin-hint">ابتدا در صفحهٔ ورود مشتری درخواست کد بدهید. کد پیامک نمی‌شود و تا دو دقیقه معتبر است.</p>
            <form class="admin-form" method="post" action="{{ route('admin.test-customers.code', $row['key']) }}">
                @csrf
                <label class="label">رمز عبور فعلی مدیریت<input class="input" type="password" name="current_password" autocomplete="current-password" required></label>
                <button class="btn btn-primary">نمایش کد آزمایشی فعال</button>
            </form>
            </details>
            @endif
            @if($scope && !$scope['reason'])
                @php($description = \App\Support\FaNumber::digits(count($scope['requests'])).' درخواست تعمیر، '.\App\Support\FaNumber::digits(count($scope['addresses'])).' آدرس و '.\App\Support\FaNumber::digits(count($scope['notifications'])).' اعلان آزمایشی')
                <details class="admin-section">
                <summary>بازنشانی حساب</summary>
                <form class="admin-form" method="post" action="{{ route('admin.test-customers.reset', $account->public_id) }}" data-confirm="حساب آزمایشی بازنشانی شود؟ {{ $description }} حذف و نشست‌ها، رمز عبور و کدهای ورود لغو می‌شوند.">
                    @csrf
                    <input type="hidden" name="scope" value="{{ $scope['pin'] }}">
                    <label class="label">رمز عبور فعلی مدیریت<input class="input" type="password" name="current_password" autocomplete="current-password" required></label>
                    <label><input type="checkbox" name="confirm" value="1" required> حذف {{ $description }} و بازنشانی حساب را تأیید می‌کنم.</label>
                    <button class="btn btn-secondary">بازنشانی حساب تست</button>
                </form>
                </details>
            @endif
        </div>
    </section>
@empty
    <section class="admin-panel"><x-admin.empty-state title="حساب آزمایشی در دسترس نیست" icon="shield-check">ورود آزمایشی فعال نیست یا شماره‌ای برای آزمایش تعریف نشده است.</x-admin.empty-state></section>
@endforelse
@endsection
