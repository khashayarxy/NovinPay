@extends('layouts.admin')
@section('title', $order ? 'ویرایش پیش‌نویس' : 'سفارش جدید')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">توافق فروش داخلی · تومان</p><h1>{{ $order ? 'ویرایش پیش‌نویس' : 'سفارش جدید' }}</h1><p>ساخت یا ویرایش پیش‌نویس موجودی را رزرو نمی‌کند.</p></div><a class="admin-text-link" href="{{ route('admin.orders.index') }}">فهرست سفارش‌ها ←</a></header><x-admin.errors/>
<section class="admin-panel"><form class="admin-form" method="post" action="{{ $order ? route('admin.orders.update',$order) : route('admin.orders.store') }}">@csrf @if($order) @method('PUT')<input type="hidden" name="version" value="{{ $order->version }}">@endif<input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<x-operation-field name="customer" label="شناسهٔ کامل پروفایل مشتری تأییدشده" :value="$order?->customer?->public_id ?? $customerReference ?? ''" :maxlength="36"/><p class="admin-hint"><a class="admin-text-link" href="{{ route('admin.customers.index') }}" target="_blank" rel="noopener">یافتن مشتری در دفتر مشتریان (پنجرهٔ جدید)</a> · نام و همراه را در فیلد دلیل وارد نکنید.</p>
<div class="admin-panel-heading"><h2>اقلام سفارش</h2><a class="admin-text-link" href="{{ route('admin.inventory.index') }}" target="_blank" rel="noopener">یافتن شناسهٔ کالا در انبار (پنجرهٔ جدید) ←</a></div><p class="admin-hint">کالاهای تأییدشدهٔ موجودی، شامل اقلام مستقل یا مرتبط با کاتالوگ. قیمت هر واحد به تومان و حداکثر دو رقم اعشار است.</p>
<datalist id="order-item-options">@foreach($suggestedItems as $suggestion)<option value="{{ $suggestion->public_id }}" label="{{ $suggestion->sku }} · {{ $suggestion->name }} · {{ \App\Enums\ProductCondition::from($suggestion->condition)->label() }} · آزاد {{ $suggestion->available }}"></option>@endforeach</datalist>
<p class="admin-hint">با نوشتن کد یا عنوان، از پیشنهادهای ۱۰۰ کالای نخست انتخاب کنید؛ برای سایر کالاها شناسهٔ کامل را از انبار بردارید. موجودی هنگام رزرو دوباره بررسی می‌شود.</p>
<div id="order-line-rows" data-next-index="{{ max(1,$lines->count()) }}">@forelse($lines as $index=>$line) @include('admin.orders.line', ['index'=>$index, 'line'=>$line]) @empty @include('admin.orders.line',['index'=>0,'line'=>null]) @endforelse</div>
<button class="btn btn-secondary" type="button" data-add-order-line>افزودن ردیف کالا</button>
<x-operation-field name="reason" label="دلیل ثبت یا تغییر؛ بدون اطلاعات شخصی" kind="textarea" :maxlength="1000"/><button class="btn btn-primary" type="submit">ذخیرهٔ پیش‌نویس بدون رزرو</button>
</form></section><template id="order-line-template">@include('admin.orders.line',['index'=>'__INDEX__','line'=>null])</template>
@endsection
