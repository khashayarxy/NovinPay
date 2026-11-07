@extends('layouts.admin')
@section('title', 'پرونده تعمیر')
@section('content')
@php
    $price = $case->simple_workflow ? ($case->repair_price_toman ?? 0) : ($case->currentEstimate?->total() ?? 0);
    $settled = $repairDocument && $repairDocument->charged_minor > 0 && $repairDocument->charged_minor === $repairDocument->paid_minor;
    $status = match($case->status->value) {
        'received','waiting_inspection','inspecting','inspected','waiting_approval','approved' => 'received',
        'repaired','ready' => 'ready', 'returned','return_pending' => 'return_pending', default => $case->status->value,
    };
@endphp
<header class="admin-page-heading"><h1>پرونده تعمیر <bdi>{{ \App\Support\FaNumber::digits($case->tracking_reference) }}</bdi></h1><a class="admin-text-link" href="{{ route('admin.repair-cases.index') }}">پرونده‌های تعمیر</a></header>
<x-admin.errors />
@if(session('success'))<p role="status">{{ session('success') }}</p>@endif
@if($customer)
@can('customers.view')
@if($case->customer_id)<a class="admin-text-link" href="{{ route('admin.customers.show', $case->customer) }}">پرونده مشتری</a>
@else @can('customers.link')<a class="admin-text-link" href="{{ route('admin.customers.index') }}">اتصال به پرونده مشتری</a>@endcan @endif
@endcan
<section class="admin-panel"><dl class="simple-repair-details">
<div><dt>مشتری</dt><dd>{{ $case->customer_name }} · <bdi>{{ \App\Support\FaNumber::digits($case->contact_phone) }}</bdi></dd></div>
<div><dt>دستگاه</dt><dd>{{ $case->device_model }} @if($case->device_serial)· <bdi>{{ $case->device_serial }}</bdi>@endif</dd></div>
<div><dt>مشکل اعلام‌شده</dt><dd>{{ $case->issue }}</dd></div><div><dt>وضعیت ظاهری</dt><dd>{{ $case->physical_condition }}</dd></div>
<div><dt>زمان پذیرش</dt><dd>{{ \App\Support\RepairTime::display($case->received_at) }}</dd></div><div><dt>وضعیت</dt><dd>{{ $case->status->label() }}</dd></div>
</dl></section>
@unless($case->status->closed())
@can('repairs.transition')
<form method="post" action="{{ route('admin.repair-cases.record', $case) }}" class="admin-panel admin-form mt-5" data-simple-repair>@csrf
<input type="hidden" name="version" value="{{ $case->version }}">
<div class="admin-form-grid"><div><label class="label" for="repair-status">وضعیت تعمیر</label><select class="input" id="repair-status" name="status">
@foreach(['received'=>'پذیرش‌شده','repairing'=>'در حال تعمیر','ready'=>'آماده تحویل','delivered'=>'تحویل‌شده','return_pending'=>'تعمیرنشده / لغوشده'] as $value=>$label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach
</select></div><x-admin-money name="repair_cost" label="هزینه تعمیر" :amount="$price"/></div>
<x-operation-field name="repair_notes" label="یادداشت تعمیر (اختیاری)" kind="textarea" :required="false" :value="$case->repair_notes ?? ''"/>
@can('finance.view')
<label class="admin-checkbox"><input type="checkbox" name="settled" value="1" @checked($settled)> تسویه شد</label>
<fieldset data-settlement-options @if(!$settled) hidden @endif><legend class="sr-only">روش پرداخت</legend>
@foreach(['in_person'=>'حضوری','online'=>'اینترنتی'] as $value=>$label)<label class="admin-checkbox"><input type="radio" name="settlement_method" value="{{ $value }}" @checked($case->settlement_method === $value) @disabled(!$settled)> {{ $label }}</label>@endforeach
</fieldset>
@endcan
<div class="admin-form-grid"><div><label class="label" for="delivery-method">روش تحویل</label><select class="input" id="delivery-method" name="delivery_method"><option value="in_person" @selected($case->delivery_method !== 'shipping')>حضوری</option><option value="shipping" @selected($case->delivery_method === 'shipping')>ارسال</option></select></div>
<div data-carrier-options @if($case->delivery_method !== 'shipping') hidden @endif><label class="label" for="delivery-carrier">شرکت ارسال</label><select class="input" id="delivery-carrier" name="delivery_carrier" @disabled($case->delivery_method !== 'shipping')>@foreach(['post'=>'پست','tipax'=>'تیپاکس','courier'=>'پیک'] as $value=>$label)<option value="{{ $value }}" @selected($case->delivery_carrier === $value)>{{ $label }}</option>@endforeach</select></div></div>
<button class="btn btn-primary" type="submit">ذخیره پرونده</button>
</form>
@endcan
@endunless
<nav class="admin-actions mt-5" aria-label="اسناد تعمیر">
@can('operate', [$case, 'print'])<a class="btn btn-secondary" href="{{ route('admin.repair-cases.document', [$case, 'intake']) }}">رسید پذیرش</a><a class="btn btn-secondary" href="{{ route('admin.repair-cases.document', [$case, 'summary']) }}">صورتحساب تعمیر</a>@endcan
@can('finance.view')<a class="btn btn-secondary" href="{{ route('admin.finance.sources.show', ['type'=>'repair','reference'=>$case->public_id]) }}">سند مالی</a>@endcan
</nav>
<details class="admin-panel mt-5"><summary>سوابق پرونده</summary>@foreach($events as $event)<p>{{ \App\Support\RepairTime::display($event->created_at) }} · {{ $event->actor?->name }} · {{ \App\Enums\RepairStage::from($event->to_status)->label() }}</p>@endforeach{{ $events->links() }}</details>
@else
<p>برای مشاهده اطلاعات مشتری، دسترسی لازم است.</p>
@endif
@endsection
