@extends('layouts.admin')
@section('title', 'امنیت حساب من')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">حساب کاربری</p><h1>امنیت حساب من</h1><p>گذرواژهٔ شخصی خود را تغییر دهید؛ نشست‌های قبلی پس از ذخیره لغو می‌شوند.</p></div></header>
@if($account->must_change_password)<div class="admin-notice"><h2>پیش از ادامه، گذرواژهٔ اولیه را تغییر دهید</h2><p>برای دسترسی به بخش‌های مجاز، ابتدا گذرواژهٔ شخصی تازه تعیین کنید.</p></div>@endif
<x-admin.errors />
<form class="admin-panel admin-form admin-password-form" method="post" action="{{ route('admin.password.update') }}">@csrf @method('PUT')
    <div><label class="label" for="current_password">گذرواژهٔ فعلی</label><input class="input" type="password" name="current_password" id="current_password" required autocomplete="current-password" maxlength="255"></div>
    <p class="admin-hint">حداقل ۱۲ نویسه شامل حروف بزرگ و کوچک لاتین و عدد؛ متفاوت با گذرواژهٔ فعلی.</p>
    <div><label class="label" for="password">گذرواژهٔ تازه</label><input class="input" type="password" name="password" id="password" required autocomplete="new-password" minlength="12" maxlength="255"></div>
    <div><label class="label" for="password_confirmation">تکرار گذرواژهٔ تازه</label><input class="input" type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password" minlength="12" maxlength="255"></div>
    <button type="submit" class="btn btn-primary">ذخیرهٔ گذرواژه</button>
</form>
@endsection
