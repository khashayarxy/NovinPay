@extends('layouts.admin')
@section('title', 'پذیرش جدید')
@section('content')
@php
    $parsed = $source?->parsedIssue() ?? [];
    $cleanProblem = ($parsed['problem'] ?? '') !== '' ? $parsed['problem'] : ($source?->issue ?? '');
    $cleanModel = trim((string) ($source?->device_model ?? ''));
    if ($source?->device_brand && $cleanModel !== '') {
        $brandValue = $source->device_brand->value;
        if (stripos($cleanModel, $brandValue) === 0) {
            $stripped = trim(substr($cleanModel, strlen($brandValue)));
            if ($stripped !== '') {
                $cleanModel = $stripped;
            }
        }
    }
    $prefillSerial = trim((string) ($parsed['serial'] ?? ''));
    $prefillAccessories = trim((string) ($parsed['accessories'] ?? ''));
    $prefillLocation = trim((string) ($parsed['location'] ?? ''));
    $prefillProvince = '';
    $prefillCity = '';
    if ($prefillLocation !== '') {
        $locParts = array_values(array_filter(array_map('trim', preg_split('/\s*[-–—،,]\s*/u', $prefillLocation) ?: [])));
        $prefillProvince = $locParts[0] ?? '';
        $prefillCity = $locParts[1] ?? '';
    }
    $prefillHandover = trim((string) ($parsed['handover_label'] ?? ''));
    $prefillPickupAddress = trim((string) ($parsed['pickup_address'] ?? ''));
    $prefillPickupNotes = trim((string) ($parsed['pickup_notes'] ?? ''));
    $prefillResponsivePhone = trim((string) ($parsed['responsive_phone'] ?? ''));

    $lockCustomerName = $source !== null && trim((string) $source->customer_name) !== '';
    $lockContactPhone = $source !== null && trim((string) $source->contact_phone) !== '';
    $lockBrand = $source !== null && $source->device_brand !== null;
    $lockModel = $source !== null && $cleanModel !== '';
    $lockSerial = $source !== null && $prefillSerial !== '';
    $lockAccessories = $source !== null && $prefillAccessories !== '';
    $lockIssue = $source !== null && trim((string) $cleanProblem) !== '';
@endphp
<div class="admin-form-surface admin-form-surface--intake">
    <a class="admin-back-link" href="{{ route('admin.repair-cases.index') }}">← پرونده‌های تعمیر</a>
    <header class="admin-page-heading">
        <div>
            <span class="admin-eyebrow">پذیرش حضوری یا مرسوله</span>
            <h1>پذیرش جدید تعمیر</h1>
        </div>
    </header>
    <x-admin.errors />
    <form method="post" action="{{ route('admin.repair-cases.store') }}" class="admin-panel admin-form admin-intake-form">
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

        {{-- Section 1: Customer & Delivery Info --}}
        <fieldset class="admin-intake-section">
            <legend>{{ $source ? 'اطلاعات مشتری و تحویل' : 'اطلاعات مشتری' }}</legend>
            <div @class(['admin-intake-row', 'admin-intake-row--customer' => (bool) $source, 'admin-intake-row--2' => !$source])>
                <x-operation-field name="customer_name" label="نام و نام خانوادگی" :value="$source?->customer_name ?? ''" :mark-required="true" :readonly="$lockCustomerName" :maxlength="100"/>
                <x-operation-field name="contact_phone" label="شماره موبایل" type="tel" :value="$source?->contact_phone ?? ''" :mark-required="true" :readonly="$lockContactPhone" :maxlength="40"/>
                @if($source)
                    <div>
                        <label class="label" for="intake-province">استان</label>
                        <input id="intake-province" type="text" class="input" value="{{ $prefillProvince !== '' ? $prefillProvince : 'ثبت نشده' }}" readonly>
                    </div>
                    <div>
                        <label class="label" for="intake-city">شهر</label>
                        <input id="intake-city" type="text" class="input" value="{{ $prefillCity !== '' ? $prefillCity : 'ثبت نشده' }}" readonly>
                    </div>
                    <div>
                        <label class="label" for="intake-handover">نحوه تحویل دستگاه</label>
                        <input id="intake-handover" type="text" class="input" value="{{ $prefillHandover !== '' ? $prefillHandover : 'ثبت نشده' }}" readonly>
                    </div>
                @endif
            </div>
            @if($source && ($prefillPickupAddress !== '' || $prefillResponsivePhone !== '' || $prefillPickupNotes !== ''))
                <div class="admin-info-card mt-3">
                    <dl class="admin-record-facts">
                        @if($prefillPickupAddress !== '')
                            <div class="repair-fact--full"><dt>نشانی دریافت پیک</dt><dd>{{ $prefillPickupAddress }}</dd></div>
                        @endif
                        @if($prefillResponsivePhone !== '')
                            <div><dt>شماره تماس تحویل‌دهنده</dt><dd><bdi dir="ltr">{{ \App\Support\FaNumber::digits($prefillResponsivePhone) }}</bdi></dd></div>
                        @endif
                        @if($prefillPickupNotes !== '')
                            <div class="repair-fact--full"><dt>توضیحات برای پیک</dt><dd>{{ $prefillPickupNotes }}</dd></div>
                        @endif
                    </dl>
                </div>
            @endif
        </fieldset>

        {{-- Section 2: Device & Accessories --}}
        <fieldset class="admin-intake-section">
            <legend>اطلاعات دستگاه و اقلام همراه</legend>
            <div class="admin-intake-row admin-intake-row--4">
                <div>
                    <label class="label" for="intake-brand-select">برند دستگاه</label>
                    <select id="intake-brand-select" class="input" data-intake-brand-select @disabled($lockBrand)>
                        <option value="">انتخاب برند دستگاه…</option>
                        @foreach(\App\Enums\RepairBrand::cases() as $brand)
                            <option value="{{ $brand->value }}" @selected($source?->device_brand === $brand)>{{ $brand->value }}</option>
                        @endforeach
                    </select>
                </div>
                <x-operation-field name="device_model" label="مدل دستگاه" :value="$cleanModel" :mark-required="true" :readonly="$lockModel" :maxlength="100"/>
                <x-operation-field name="device_serial" label="شماره سریال (اختیاری)" :required="false" :value="$prefillSerial" :readonly="$lockSerial" :maxlength="120"/>
                <x-operation-field name="accessories" label="لوازم همراه (اختیاری)" :required="false" :value="$prefillAccessories" :readonly="$lockAccessories" :maxlength="500"/>
            </div>
        </fieldset>

        {{-- Section 3: Problem Description & Physical Condition --}}
        <div class="admin-intake-row admin-intake-row--2">
            <x-operation-field name="issue" label="شرح مشکل دستگاه" kind="textarea" :value="$cleanProblem" :mark-required="true" :readonly="$lockIssue"/>
            <x-operation-field name="physical_condition" label="وضعیت ظاهری هنگام دریافت" kind="textarea" :mark-required="true"/>
        </div>

        <div class="admin-form-footer justify-between">
            <span class="admin-muted">فقط هنگام تحویل فیزیکی دستگاه ثبت شود (<span class="admin-required">*</span> الزامی).</span>
            <button type="submit" class="btn btn-primary admin-btn-mobile-full">ثبت پذیرش</button>
        </div>
    </form>
</div>
@endsection
