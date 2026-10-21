@extends('layouts.admin')
@section('title',$article?'ویرایش پیش‌نویس':'مقالهٔ جدید')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">ذخیرهٔ نسخهٔ مستقل</p><h1>{{ $article?'ویرایش پیش‌نویس':'مقالهٔ جدید' }}</h1><p>ذخیره به معنی انتشار نیست. نامک پس از ایجاد ثابت می‌ماند.</p></div><a class="btn btn-secondary" href="{{ route('admin.articles.index') }}">فهرست مقاله‌ها</a></header>
<x-admin.errors/>
<div class="repair-layout"><section class="admin-panel"><form class="admin-form article-form" method="post" action="{{ $article?route('admin.articles.update',$article):route('admin.articles.store') }}">@csrf
@if($article) @method('PUT')<input type="hidden" name="version" value="{{ $article->version }}"><p class="crm-code">{{ $article->slug }}</p>@else<x-finance-field name="slug" label="نامک یکتا؛ حروف، عدد و خط تیره" :maxlength="120"/>@endif
<x-finance-field direction="rtl" name="title" label="عنوان مقاله" :value="$draft?->title??''" :maxlength="180"/>
<div><label class="label" for="article-category">دستهٔ فعال</label><select class="input" id="article-category" name="category_id" required @error('category_id') aria-invalid="true" aria-describedby="article-category-error" @enderror><option value="">دسته را انتخاب کنید</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($draft?->category_id==$category->id)>{{ $category->name }}</option>@endforeach</select>@error('category_id')<p class="finance-field-error" id="article-category-error">{{ $message }}</p>@enderror</div>
<x-finance-field name="summary" label="خلاصهٔ مقاله" kind="textarea" :value="$draft?->summary??''" :maxlength="500"/>
<x-finance-field name="content" label="متن ساختاریافته؛ راهنما در ادامهٔ فرم" kind="textarea" :value="$draft?->source??''" :maxlength="40000"/>
<button class="btn btn-primary" type="submit">ذخیرهٔ نسخهٔ تازهٔ پیش‌نویس</button></form></section>
<aside class="admin-panel article-help"><h2>راهنمای قالب محدود</h2><p>بندها را با یک خط خالی جدا کنید. تیتر و فهرست هم باید از بند قبل فاصله داشته باشند.</p><dl><dt>تیتر دوم یا سوم</dt><dd><code dir="ltr">## عنوان</code> یا <code dir="ltr">### عنوان</code></dd><dt>فهرست</dt><dd>هر سطر با <code dir="ltr">- </code> یا <code dir="ltr">1. </code> آغاز شود.</dd><dt>تأکید</dt><dd><code dir="ltr">**پررنگ**</code> یا <code dir="ltr">*تأکید*</code></dd><dt>پیوند</dt><dd><code dir="ltr">[عنوان](https://example.com)</code></dd></dl><p class="admin-hint">HTML، تصویر، کد اجرایی و نشانی‌های غیر http/https مجاز نیستند. از درج اطلاعات خصوصی مشتری خودداری کنید. ورودی ذخیره‌نشده پس از خطا در نشست نگهداری نمی‌شود.</p></aside></div>
@endsection
