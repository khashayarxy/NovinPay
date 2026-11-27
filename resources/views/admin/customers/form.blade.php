@extends('layouts.admin')
@section('title', $customer ? 'ویرایش مشتری' : 'ثبت مشتری')
@section('content')
<div class="admin-form-surface">
    <a class="admin-back-link" href="{{ route('admin.customers.index') }}">← دفتر مشتریان</a>
    <header class="admin-page-heading">
        <div>
            <h1>{{ $customer ? 'ویرایش اطلاعات مشتری' : 'ثبت مشتری جدید' }}</h1>
        </div>
    </header>
    <x-admin.errors/>
    <section class="admin-panel">
        <form class="admin-form" method="post" action="{{ $customer ? route('admin.customers.update', $customer) : route('admin.customers.store') }}">
            @csrf
            @if($customer)
                @method('PUT')
                <input type="hidden" name="version" value="{{ $customer->version }}">
            @endif
            <div class="admin-form-grid">
                <x-operation-field retain name="name" label="نام و نام خانوادگی" :value="$customer?->name ?? ''" :maxlength="100"/>
                <x-operation-field retain name="contact_phone" label="شماره موبایل" type="tel" :value="$customer?->contact_phone ?? ''" :maxlength="40"/>
            </div>
            <div>
                <label for="profile-active" class="label">وضعیت پروفایل</label>
                <select id="profile-active" name="is_active" class="input">
                    <option value="1" @selected(!$customer || $customer->is_active)>فعال</option>
                    <option value="0" @selected($customer && !$customer->is_active)>بایگانی‌شده</option>
                </select>
            </div>
            <x-operation-field retain name="reason" label="توضیح کوتاه ثبت یا ویرایش" kind="textarea" :maxlength="1000"/>
            <label class="admin-checkbox"><input type="checkbox" name="identity_reviewed" value="1" required> هویت مشتری بررسی شد.</label>
            <label class="admin-checkbox"><input type="checkbox" name="duplicate_reviewed" value="1"> در صورت وجود شماره تماس مشابه، پروفایل‌های قبلی بررسی شد.</label>
            <div class="admin-form-footer">
                <button class="btn btn-primary" type="submit">ذخیرهٔ مشتری</button>
            </div>
        </form>
    </section>
</div>
@endsection
