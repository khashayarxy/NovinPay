@extends('layouts.admin')
@section('title', 'جزئیات درخواست آنلاین')
@section('content')
<a class="admin-back-link" href="{{ route('admin.repairs.index') }}">← درخواست‌های آنلاین تعمیر</a>
<header class="admin-page-heading repair-request-header">
    <div>
        <div class="repair-case-header__title-row">
            <h1>درخواست آنلاین #{{ \App\Support\FaNumber::digits($repair->id) }}</h1>
            <x-admin.status :tone="$physicalCase ? 'success' : 'warning'">{{ $physicalCase ? 'دریافت دستگاه ثبت شده است' : 'دستگاه هنوز پذیرش نشده است' }}</x-admin.status>
        </div>
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
    </div>
</header>
<x-customer-association :record="$repair" :subtle="true" />

<section class="admin-panel repair-hero-card mt-4" aria-labelledby="customer-data-title">
    <div class="admin-panel-heading">
        <h2 id="customer-data-title">{{ $showCustomerData ? 'اطلاعات درخواست، مشتری و دستگاه' : 'مشخصات ثبت درخواست' }}</h2>
        <span class="repair-code-chip" dir="ltr" title="کد پیگیری عمومی">{{ preg_match('/\A[0-9]+\z/', $repair->tracking_reference) ? \App\Support\FaNumber::digits($repair->tracking_reference) : $repair->tracking_reference }}</span>
    </div>
    @if($showCustomerData)
        @php
            $parsedIssue = $repair->parsedIssue();
            $cleanProblem = !empty($parsedIssue['problem']) ? $parsedIssue['problem'] : $repair->issue;
        @endphp
        <dl class="admin-record-facts repair-intake-facts">
            <div><dt>نام مشتری</dt><dd><strong class="repair-hero-name">{{ $repair->customer_name }}</strong></dd></div>
            <div><dt>شماره موبایل</dt><dd><a class="repair-phone-chip" href="tel:{{ $repair->contact_phone }}" dir="ltr"><bdi>{{ \App\Support\FaNumber::digits($repair->contact_phone) }}</bdi></a></dd></div>
            <div><dt>برند و مدل</dt><dd><bdi dir="ltr">{{ $repair->device_brand->value }}</bdi> · <bdi dir="auto">{{ $repair->device_model }}</bdi></dd></div>
            <div><dt>زمان ثبت</dt><dd><x-finance-time :value="$repair->created_at" /></dd></div>
            @if (! empty($parsedIssue['handover_label']))
                <div><dt>نحوه تحویل</dt><dd>{{ $parsedIssue['handover_label'] }}</dd></div>
            @endif
            @if (! empty($parsedIssue['location']))
                <div><dt>موقعیت / شهر</dt><dd>{{ $parsedIssue['location'] }}</dd></div>
            @endif
            @if (! empty($parsedIssue['responsive_phone']))
                <div><dt>شماره تماس تحویل‌دهنده</dt><dd><a class="repair-phone-chip" href="tel:{{ $parsedIssue['responsive_phone'] }}" dir="ltr"><bdi>{{ \App\Support\FaNumber::digits($parsedIssue['responsive_phone']) }}</bdi></a></dd></div>
            @endif
            @if (! empty($parsedIssue['pickup_address']))
                <div class="repair-fact--full"><dt>آدرس دریافت پیک</dt><dd>{{ $parsedIssue['pickup_address'] }}</dd></div>
            @endif
            @if (! empty($parsedIssue['pickup_notes']))
                <div class="repair-fact--full"><dt>توضیحات برای پیک</dt><dd>{{ $parsedIssue['pickup_notes'] }}</dd></div>
            @endif
            <div class="repair-fact--full repair-callout"><dt>شرح مشکل دستگاه</dt><dd class="whitespace-pre-line">{{ $cleanProblem }}</dd></div>
        </dl>
        @if ($hasPhoto || !empty($sidePhotos))
            <div class="admin-actions mt-3">
                @if ($hasPhoto)<a class="btn btn-secondary btn-sm" href="{{ route('admin.repairs.photo', $repair) }}">دریافت عکس دستگاه</a>@endif
                @foreach ($sidePhotos ?? [] as $sideKey => $sidePath)
                    <a class="btn btn-secondary btn-sm" href="{{ route('admin.repairs.photo', [$repair, 'side' => $sideKey]) }}">عکس {{ \App\Models\RepairRequest::PHOTO_SIDES[$sideKey] ?? $sideKey }}</a>
                @endforeach
            </div>
        @endif
    @else
        <dl class="admin-record-facts">
            <div><dt>زمان ثبت</dt><dd><x-finance-time :value="$repair->created_at" /></dd></div>
            <div><dt>وضعیت</dt><dd>{{ $physicalCase ? 'دریافت دستگاه ثبت شده است' : $repair->status->label() }}</dd></div>
            <div><dt>برند</dt><dd><bdi>{{ $repair->device_brand->value }}</bdi></dd></div>
        </dl>
        <p class="admin-hint mt-3">اطلاعات شخصی محدود است؛ مشاهدهٔ نام، شماره و شرح مشکل نیازمند مجوز اطلاعات مشتری است.</p>
    @endif
</section>
@endsection
