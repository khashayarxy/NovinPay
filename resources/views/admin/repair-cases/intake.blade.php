@extends('layouts.admin')
@section('title', 'پذیرش جدید')
@section('content')
<div class="admin-form-surface">
<header class="admin-page-heading"><h1>پذیرش جدید</h1><a class="admin-text-link" href="{{ route('admin.repair-cases.index') }}">پرونده‌های تعمیر</a></header>
<x-admin.errors />
<form method="post" action="{{ route('admin.repair-cases.store') }}" class="admin-panel admin-form">@csrf
<input type="hidden" name="simple_intake" value="1"><input type="hidden" name="submission_token" value="{{ $token }}">
@if($source)
    <input type="hidden" name="repair_request_id" value="{{ $source->id }}">
    <p>درخواست آنلاین مرتبط: <bdi>{{ \App\Support\FaNumber::digits($source->tracking_reference) }}</bdi></p>
@endif
<div class="admin-form-grid">
    <fieldset class="admin-form"><legend>اطلاعات مشتری</legend>
        <x-operation-field name="customer_name" label="نام و نام خانوادگی" :value="$source?->customer_name ?? ''" :maxlength="100"/>
        <x-operation-field name="contact_phone" label="شماره موبایل" type="tel" :value="$source?->contact_phone ?? ''" :maxlength="40"/>
    </fieldset>
    <fieldset class="admin-form"><legend>اطلاعات دستگاه</legend>
        <x-operation-field name="device_model" label="مدل دستگاه" :value="$source?->device_model ?? ''" :maxlength="100"/>
        <x-operation-field name="device_serial" label="شماره سریال (اختیاری)" :required="false" :maxlength="120"/>
    </fieldset>
</div>
<div class="admin-form-grid"><x-operation-field name="issue" label="شرح مشکل دستگاه" kind="textarea" :value="$source?->issue ?? ''"/><x-operation-field name="physical_condition" label="وضعیت ظاهری هنگام دریافت" kind="textarea"/></div>
<p class="admin-hint">پذیرش را هنگام دریافت واقعی دستگاه ثبت کنید.</p>
<div class="admin-form-footer"><button type="submit" class="btn btn-primary">ثبت پذیرش</button></div>
</form>
</div>
@endsection
