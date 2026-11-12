@extends('layouts.admin')
@section('title', 'جزئیات درخواست آنلاین')
@section('content')
<x-customer-association :record="$repair"/>
@if($physicalCase)<div class="admin-notice"><h2>دریافت دستگاه ثبت شده است</h2><a class="admin-text-link" href="{{ route('admin.repair-cases.show', $physicalCase) }}">مشاهده پرونده تعمیر ←</a></div>@else @can('repairs.intake') @can('repairs.customer_data')<div class="admin-notice"><h2>دستگاه هنوز پذیرش نشده است</h2><p>پذیرش را هنگام دریافت واقعی دستگاه ثبت کنید.</p><a class="btn btn-primary" href="{{ route('admin.repair-cases.create', ['request'=>$repair->id]) }}">باز کردن فرم پذیرش</a></div>@endcan @endcan @endif
<header class="admin-page-heading"><div><h1>درخواست آنلاین شمارهٔ {{ \App\Support\FaNumber::digits($repair->id) }}</h1><p>{{ $physicalCase ? 'ثبت اولیهٔ مشتری؛ اطلاعات دریافت در پروندهٔ مرتبط نگهداری می‌شود.' : 'ثبت اولیهٔ مشتری؛ دریافت فیزیکی دستگاه تأیید نشده است.' }}</p></div><a href="{{ route('admin.repairs.index') }}" class="admin-text-link">بازگشت به فهرست ←</a></header>
<div class="admin-detail-grid">
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
            <div class="admin-detail-groups">
                <div>
                    <h3>مشتری</h3>
                    <dl class="admin-definition">
                        <div><dt>نام مشتری</dt><dd>{{ $repair->customer_name }}</dd></div>
                        <div><dt>شماره موبایل</dt><dd><bdi dir="ltr">{{ \App\Support\FaNumber::digits($repair->contact_phone) }}</bdi></dd></div>
                    </dl>
                </div>
                <div>
                    <h3>دستگاه</h3>
                    <dl class="admin-definition">
                        <div><dt>برند</dt><dd><bdi>{{ $repair->device_brand->value }}</bdi></dd></div>
                        <div><dt>مدل اعلام‌شده</dt><dd><bdi dir="auto">{{ $repair->device_model }}</bdi></dd></div>
                        <div><dt>شرح مشکل دستگاه</dt><dd class="whitespace-pre-line">{{ $repair->issue }}</dd></div>
                    </dl>
                </div>
            </div>
            <p class="admin-hint">مشاهدهٔ این اطلاعات در تاریخچهٔ فعالیت ثبت می‌شود.</p>
        @else
            <h2 id="customer-data-title">اطلاعات شخصی محدود است</h2>
            <p class="admin-hint">نام، شماره، مدل دستگاه و شرح مشکل به مجوز جداگانه نیاز دارند و برای این حساب نمایش داده نمی‌شوند.</p>
        @endif
    </section>
</div>
@endsection
