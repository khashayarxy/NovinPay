@extends('layouts.admin')
@section('title', $staff ? 'ویرایش همکار' : 'تعریف همکار')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">همکاران و دسترسی‌ها</p><h1>{{ $staff ? 'ویرایش حساب همکار' : 'تعریف همکار جدید' }}</h1><p>نقش این حساب «کارمند» است؛ فقط مجوزهای انتخاب‌شده اعمال می‌شوند.</p></div><a href="{{ route('admin.staff.index') }}" class="admin-text-link">بازگشت به فهرست ←</a></header>
<x-admin.errors />
@php
    $selectedPermissions = old('permissions', $staff?->permissions ?? []);
    $selectedPermissions = is_array($selectedPermissions) ? array_filter($selectedPermissions, 'is_string') : [];
    $staffName = old('name', $staff?->user->name);
    $staffName = is_string($staffName) ? $staffName : '';
    $staffEmail = old('email', $staff?->user->email);
    $staffEmail = is_string($staffEmail) ? $staffEmail : '';
    $activeChecked = in_array(old('is_active', $staff?->is_active ?? true), [true, 1, '1'], true);
@endphp
<form method="post" action="{{ $staff ? route('admin.staff.update', $staff) : route('admin.staff.store') }}" class="admin-form admin-panel">
    @csrf @if($staff) @method('PUT') @endif
    <div class="admin-form-grid"><div><label class="label" for="name">نام همکار</label><input class="input" id="name" name="name" value="{{ $staffName }}" required maxlength="100" autocomplete="off"></div><div><label class="label" for="email">ایمیل ورود</label><input class="input" id="email" name="email" type="email" dir="ltr" value="{{ $staffEmail }}" required maxlength="255" autocomplete="off"></div></div>
    <fieldset><legend>{{ $staff ? 'بازنشانی گذرواژه (اختیاری)' : 'گذرواژهٔ اولیهٔ همکار' }}</legend><p class="admin-hint">حداقل ۱۲ نویسه شامل حروف بزرگ و کوچک لاتین و عدد. {{ $staff ? 'برای حفظ گذرواژهٔ فعلی خالی بگذارید.' : 'گذرواژه را از مسیر امن به همکار بدهید؛ در اولین ورود باید آن را تغییر دهد.' }}</p><div class="admin-form-grid"><div><label class="label" for="password">گذرواژهٔ همکار</label><input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="255" @required(!$staff)></div><div><label class="label" for="password_confirmation">تکرار گذرواژه</label><input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="255" @required(!$staff)></div></div></fieldset>
    <fieldset><legend>مجوزهای دسترسی</legend><p class="admin-hint">ویرایش محصولات به مشاهدهٔ محصولات و دسترسی به اطلاعات مشتری به مشاهدهٔ درخواست‌ها نیاز دارد.</p><div class="admin-permissions">@foreach($permissions as $permission)<label><input type="checkbox" name="permissions[]" value="{{ $permission->value }}" @checked(in_array($permission->value, $selectedPermissions, true))><span>{{ $permission->label() }}</span></label>@endforeach</div></fieldset>
    <input type="hidden" name="is_active" value="0"><label class="admin-checkbox"><input type="checkbox" name="is_active" value="1" @checked($activeChecked)> حساب فعال باشد</label>
    <div class="admin-notice"><h2>تأیید مدیر کل</h2><p>ثبت این تغییر، نشست‌های قبلی همکار را لغو می‌کند. برای تأیید، گذرواژهٔ فعلی حساب خودتان را وارد کنید.</p><label class="label" for="current_password">گذرواژهٔ فعلی مدیر کل</label><input class="input" id="current_password" name="current_password" type="password" autocomplete="current-password" required maxlength="255"></div>
    <div class="admin-actions"><button class="btn btn-primary" type="submit">{{ $staff ? 'ذخیرهٔ تغییرات' : 'ایجاد حساب همکار' }}</button><a class="btn btn-secondary" href="{{ route('admin.staff.index') }}">انصراف</a></div>
</form>
@endsection
