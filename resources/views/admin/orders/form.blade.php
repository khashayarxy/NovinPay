@extends('layouts.admin')
@section('title', $order ? 'ویرایش پیش‌نویس' : 'سفارش جدید')
@section('content')
<div class="admin-form-surface">
    <header class="admin-page-heading">
        <div>
            <h1>{{ $order ? 'ویرایش پیش‌نویس سفارش' : 'سفارش جدید' }}</h1>
        </div>
        <a class="btn btn-secondary" href="{{ route('admin.orders.index') }}">فهرست سفارش‌ها</a>
    </header>
    <x-admin.errors/>
    <section class="admin-panel">
        <form class="admin-form" method="post" action="{{ $order ? route('admin.orders.update',$order) : route('admin.orders.store') }}">
            @csrf
            @if($order)
                @method('PUT')
                <input type="hidden" name="version" value="{{ $order->version }}">
            @endif
            <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">

            <div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="label mb-0">مشخصات مشتری</span>
                    <a class="admin-text-link" href="{{ route('admin.customers.index') }}" target="_blank" rel="noopener">دفتر مشتریان (پنجرهٔ جدید) ←</a>
                </div>
                <x-operation-field name="customer" label="شناسهٔ مشتری (UUID)" :value="$order?->customer?->public_id ?? $customerReference ?? ''" :maxlength="36"/>
            </div>

            <div class="admin-section pt-2">
                <div class="admin-panel-heading">
                    <h2>اقلام سفارش</h2>
                    <a class="admin-text-link" href="{{ route('admin.inventory.index') }}" target="_blank" rel="noopener">انبار کالاها (پنجرهٔ جدید) ←</a>
                </div>
                <datalist id="order-item-options">
                    @foreach($suggestedItems as $suggestion)
                        <option value="{{ $suggestion->public_id }}" label="{{ $suggestion->sku }} · {{ $suggestion->name }} · {{ \App\Enums\ProductCondition::from($suggestion->condition)->label() }} · آزاد {{ $suggestion->available }}"></option>
                    @endforeach
                </datalist>
                <div id="order-line-rows" data-next-index="{{ max(1,$lines->count()) }}">
                    @forelse($lines as $index=>$line)
                        @include('admin.orders.line', ['index'=>$index, 'line'=>$line])
                    @empty
                        @include('admin.orders.line',['index'=>0,'line'=>null])
                    @endforelse
                </div>
                <button class="btn btn-secondary btn-sm mt-1" type="button" data-add-order-line>+ افزودن ردیف کالا</button>
            </div>

            <x-operation-field name="reason" label="دلیل ثبت یا تغییر؛ بدون اطلاعات شخصی" kind="textarea" :maxlength="1000"/>
            <div class="admin-form-footer">
                <button class="btn btn-primary" type="submit">ذخیرهٔ پیش‌نویس بدون رزرو</button>
            </div>
        </form>
    </section>
    <template id="order-line-template">@include('admin.orders.line',['index'=>'__INDEX__','line'=>null])</template>
</div>
@endsection
