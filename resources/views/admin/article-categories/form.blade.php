@extends('layouts.admin')
@section('title',$category?'ویرایش دسته':'دستهٔ جدید')
@section('content')
<header class="admin-page-heading"><div><h1>{{ $category?'ویرایش دستهٔ مقاله':'دستهٔ جدید مقاله' }}</h1><p>دستهٔ دارای مقالهٔ منتشرشده را نمی‌توان غیرفعال کرد.</p></div><a class="btn btn-secondary" href="{{ route('admin.article-categories.index') }}">فهرست دسته‌ها</a></header><x-admin.errors/>
<section class="admin-panel"><form class="admin-form article-form" method="post" action="{{ $category?route('admin.article-categories.update',$category):route('admin.article-categories.store') }}">@csrf
@if($category) @method('PUT')<input type="hidden" name="version" value="{{ $category->version }}">@endif
<x-finance-field direction="rtl" name="name" label="نام دسته" :value="$category?->name??''" :maxlength="120"/>
<x-finance-field name="slug" label="نامک یکتا؛ حروف، عدد و خط تیره" :value="$category?->slug??''" :maxlength="120"/>
<div><label class="label" for="category-active">وضعیت دسته</label><select class="input" id="category-active" name="is_active"><option value="1" @selected(!$category || $category->is_active)>فعال</option><option value="0" @selected($category && !$category->is_active)>غیرفعال</option></select></div>
<p class="admin-hint">نام دسته در نسخه‌های مرتبط نمایش داده می‌شود؛ تغییر آن صریح و ثبت‌شده است. غیرفعال‌سازی، انتخاب برای پیش‌نویس و انتشار تازه را متوقف می‌کند.</p><button class="btn btn-primary" type="submit">ذخیرهٔ دسته</button></form></section>
@endsection
