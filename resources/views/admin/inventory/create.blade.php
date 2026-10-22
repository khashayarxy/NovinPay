@extends('layouts.admin')
@section('title', 'تعریف کالای موجودی')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">شناسنامهٔ موجودی</p><h1>تعریف کالای موجودی</h1><p>آکبند و استوک را با کدهای جدا ثبت کنید. حالت سریالی پس از ثبت تغییر نمی‌کند.</p></div><a class="admin-text-link" href="{{ route('admin.inventory.index') }}">بازگشت به انبار ←</a></header>
<x-admin.errors/>
<section class="admin-panel commerce-form-panel"><x-commerce-operation :action="route('admin.inventory.store')" title="تعریف کالا با موجودی صفر">
<x-operation-field name="sku" label="کد یکتای کالا؛ حروف بزرگ انگلیسی و عدد" :maxlength="64"/>
<x-operation-field name="name" label="عنوان کالا" :maxlength="150"/>
<div class="admin-form-grid"><div><label class="label" for="item-condition">وضعیت کالا</label><select class="input" id="item-condition" name="condition"><option value="new">{{ \App\Enums\ProductCondition::New->label() }}</option><option value="used">{{ \App\Enums\ProductCondition::Used->label() }}</option></select></div><div><label class="label" for="item-mode">کنترل موجودی</label><select class="input" id="item-mode" name="serialized"><option value="1">هر دستگاه با سریال یکتا</option><option value="0">موجودی تعدادی</option></select></div></div>
<div><label class="label" for="item-product">محصول مرتبط کاتالوگ؛ اختیاری</label><select class="input" id="item-product" name="product_id"><option value="">کالای مستقل</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select><p class="admin-hint">۱۰۰ محصول نخست نمایش داده می‌شود؛ این ارتباط قیمت یا موجودی کاتالوگ را تغییر نمی‌دهد.</p></div>
<x-operation-field name="low_stock_threshold" label="آستانهٔ هشدار موجودی آزاد" type="number" value="2"/>
</x-commerce-operation></section>
@endsection
