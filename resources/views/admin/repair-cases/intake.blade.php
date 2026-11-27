@extends('layouts.admin')
@section('title', 'پذیرش جدید')
@section('content')
<div class="admin-form-surface">
    <a class="admin-back-link" href="{{ route('admin.repair-cases.index') }}">← پرونده‌های تعمیر</a>
    <header class="admin-page-heading">
        <div>
            <span class="admin-eyebrow">پذیرش حضوری یا مرسوله</span>
            <h1>پذیرش جدید تعمیر</h1>
        </div>
    </header>
    <x-admin.errors />
    <form method="post" action="{{ route('admin.repair-cases.store') }}" class="admin-panel admin-form">
        @csrf
        <input type="hidden" name="simple_intake" value="1">
        <input type="hidden" name="submission_token" value="{{ $token }}">
        @if($source)
            <input type="hidden" name="repair_request_id" value="{{ $source->id }}">
            <div class="admin-filter-context">
                <span>مرتبط با درخواست آنلاین: <strong dir="ltr"><bdi>{{ \App\Support\FaNumber::digits($source->tracking_reference) }}</bdi></strong></span>
                <a class="admin-text-link" href="{{ route('admin.repairs.show', $source) }}">مشاهده درخواست ←</a>
            </div>
        @endif
        <div class="admin-form-grid">
            <fieldset class="admin-form">
                <legend>اطلاعات مشتری</legend>
                <x-operation-field name="customer_name" label="نام و نام خانوادگی" :value="$source?->customer_name ?? ''" :mark-required="true" :maxlength="100"/>
                <x-operation-field name="contact_phone" label="شماره موبایل" type="tel" :value="$source?->contact_phone ?? ''" :mark-required="true" :maxlength="40"/>
            </fieldset>
            <fieldset class="admin-form">
                <legend>اطلاعات دستگاه</legend>
                <div>
                    <label class="label" for="intake-brand-select">برند دستگاه</label>
                    <select id="intake-brand-select" class="input" data-intake-brand-select>
                        <option value="">انتخاب برند دستگاه…</option>
                        @foreach(\App\Enums\RepairBrand::cases() as $brand)
                            <option value="{{ $brand->value }}" @selected($source?->device_brand === $brand)>{{ $brand->value }}</option>
                        @endforeach
                    </select>
                </div>
                <x-operation-field name="device_model" label="مدل دستگاه" :value="$source ? \App\Support\RepairIdentity::deviceLabel($source->device_brand, $source->device_model) : ''" :mark-required="true" :maxlength="100"/>
                <x-operation-field name="device_serial" label="شماره سریال (اختیاری)" :required="false" :maxlength="120"/>
            </fieldset>
        </div>
        <div class="admin-form-grid">
            <x-operation-field name="issue" label="شرح مشکل دستگاه" kind="textarea" :value="$source?->issue ?? ''" :mark-required="true"/>
            <x-operation-field name="physical_condition" label="وضعیت ظاهری هنگام دریافت" kind="textarea" :mark-required="true"/>
        </div>
        <div class="admin-form-footer justify-between">
            <span class="admin-muted">فقط هنگام تحویل فیزیکی دستگاه ثبت شود (<span class="admin-required">*</span> الزامی).</span>
            <button type="submit" class="btn btn-primary admin-btn-mobile-full">ثبت پذیرش</button>
        </div>
    </form>
</div>
@endsection
