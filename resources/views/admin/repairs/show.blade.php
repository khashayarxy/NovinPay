@extends('layouts.admin')
@section('title', 'جزئیات درخواست آنلاین')
@section('content')
<header class="admin-page-heading repair-request-header">
    <div>
        <div class="repair-case-header__title-row">
            <h1>درخواست آنلاین شمارهٔ {{ \App\Support\FaNumber::digits($repair->id) }}</h1>
            <x-admin.status :tone="$physicalCase ? 'success' : 'warning'">{{ $physicalCase ? 'دریافت دستگاه ثبت شده است' : 'دستگاه هنوز پذیرش نشده است' }}</x-admin.status>
        </div>
        <p>{{ $physicalCase ? 'ثبت اولیهٔ مشتری؛ اطلاعات دریافت در پروندهٔ مرتبط نگهداری می‌شود.' : 'پذیرش را هنگام دریافت واقعی دستگاه ثبت کنید.' }}</p>
    </div>
    <div class="admin-actions">
        @if($physicalCase)
            <a class="btn btn-primary" href="{{ route('admin.repair-cases.show', $physicalCase) }}">مشاهده پرونده تعمیر ←</a>
        @else
            @can('repairs.intake')
                @can('repairs.customer_data')
                    <a class="btn btn-primary" href="{{ route('admin.repair-cases.create', ['request'=>$repair->id]) }}">باز کردن فرم پذیرش</a>
                @endcan
            @endcan
        @endif
        <a href="{{ route('admin.repairs.index') }}" class="btn btn-secondary">بازگشت به فهرست</a>
    </div>
</header>
<x-customer-association :record="$repair" :subtle="true" />
<div class="admin-detail-grid mt-4">
    <section class="admin-panel" aria-labelledby="request-data-title">
        <h2 id="request-data-title">اطلاعات درخواست</h2>
        <dl class="admin-definition">
            <div><dt>کد پیگیری عمومی</dt><dd><bdi dir="ltr">{{ preg_match('/\A[0-9]+\z/', $repair->tracking_reference) ? \App\Support\FaNumber::digits($repair->tracking_reference) : $repair->tracking_reference }}</bdi></dd></div>
            <div><dt>زمان ثبت</dt><dd><x-finance-time :value="$repair->created_at" /></dd></div>
            <div><dt>وضعیت</dt><dd>{{ $physicalCase ? 'دریافت دستگاه ثبت شده است' : $repair->status->label() }}</dd></div>
            @unless($showCustomerData)
                <div><dt>برند</dt><dd><bdi>{{ $repair->device_brand->value }}</bdi></dd></div>
            @endunless
        </dl>
        <p class="admin-hint">سوابق هزینه، تعمیر و تحویل در پروندهٔ فیزیکی جداگانه نگهداری می‌شوند.</p>
    </section>
    <section class="admin-panel" aria-labelledby="customer-data-title">
        @if($showCustomerData)
            <h2 id="customer-data-title">اطلاعات مشتری و دستگاه</h2>
            <dl class="admin-record-facts repair-intake-facts">
                <div><dt>نام مشتری</dt><dd><strong>{{ $repair->customer_name }}</strong></dd></div>
                <div><dt>شماره موبایل</dt><dd><a class="repair-phone-link" href="tel:{{ $repair->contact_phone }}" dir="ltr"><bdi>{{ \App\Support\FaNumber::digits($repair->contact_phone) }}</bdi></a></dd></div>
                <div><dt>برند</dt><dd><bdi dir="ltr">{{ $repair->device_brand->value }}</bdi></dd></div>
                <div><dt>مدل اعلام‌شده</dt><dd><bdi dir="auto">{{ $repair->device_model }}</bdi></dd></div>
                <div class="repair-fact--full"><dt>شرح مشکل دستگاه</dt><dd class="whitespace-pre-line">{{ $repair->issue }}</dd></div>
            </dl>
            <p class="admin-hint">مشاهدهٔ این اطلاعات در تاریخچهٔ فعالیت ثبت می‌شود.</p>
        @else
            <h2 id="customer-data-title">اطلاعات شخصی محدود است</h2>
            <p class="admin-hint">نام، شماره، مدل دستگاه و شرح مشکل به مجوز جداگانه نیاز دارند و برای این حساب نمایش داده نمی‌شوند.</p>
        @endif
    </section>
</div>
@endsection
