@extends('layouts.admin')
@section('title','مقاله‌ها و پیش‌نویس‌ها')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">محتوای سایت</p><h1>مقاله‌ها و پیش‌نویس‌ها</h1><p>ویرایش پیش‌نویس، نسخهٔ منتشرشده را تغییر نمی‌دهد.</p></div><div class="admin-actions">@if($articleAllows('articles.manage'))<a class="btn btn-primary" href="{{ route('admin.articles.create') }}">مقالهٔ جدید</a>@endif @if($articleAllows('article_categories.manage'))<a class="btn btn-secondary" href="{{ route('admin.article-categories.index') }}">مدیریت دسته‌ها</a>@endif</div></header>
<x-admin.errors/>
<section class="admin-panel">
<form method="get" class="commerce-filters article-form">
<div><label class="label" for="article-search">جستجوی عنوان پیش‌نویس</label><input class="input" id="article-search" name="q" maxlength="100" value="{{ $filters['q']??'' }}"></div>
<div><label class="label" for="article-status">وضعیت انتشار</label><select class="input" name="status" id="article-status"><option value="">همه</option>@foreach(['draft'=>'بدون انتشار','published'=>'دارای نسخهٔ منتشرشده','changed'=>'پیش‌نویس تازه‌تر از انتشار'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['status']??'')===$key)>{{ $label }}</option>@endforeach</select></div>
<div><label class="label" for="article-category-filter">دستهٔ پیش‌نویس</label><select class="input" name="category" id="article-category-filter"><option value="">همهٔ دسته‌ها</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(($filters['category']??'')==$category->id)>{{ $category->name }}</option>@endforeach</select></div>
<button class="btn btn-primary" type="submit">اعمال فیلتر</button>
</form></section>
<div class="article-card-list mt-4">
@forelse($articles as $article)<article class="admin-panel"><div class="admin-panel-heading"><h2>{{ $article->draftVersion->title }}</h2><span class="repair-stage">{{ $article->published_version_id ? 'دارای نسخهٔ منتشرشده' : 'بدون انتشار' }}</span></div><p>{{ $article->draftVersion->category->name }} · نسخهٔ پیش‌نویس {{ \App\Support\FaNumber::digits($article->draftVersion->revision) }}</p>@if($article->published_version_id && $article->published_version_id!==$article->draft_version_id)<p class="admin-hint">تغییرات پیش‌نویس هنوز منتشر نشده‌اند.</p>@endif<p class="crm-code">{{ $article->slug }}</p><div class="admin-actions mt-4"><a class="btn btn-secondary" href="{{ route('admin.articles.preview',$article) }}">بازبینی و انتشار</a>@if($articleAllows('articles.manage'))<a class="btn btn-secondary" href="{{ route('admin.articles.edit',$article) }}">ویرایش پیش‌نویس</a>@endif</div></article>
@empty<section class="admin-panel admin-empty"><h2>مقاله‌ای با این شرایط وجود ندارد</h2><p>برای شروع، دستهٔ فعال و سپس پیش‌نویس بسازید.</p></section>@endforelse
</div>{{ $articles->links() }}
@endsection
