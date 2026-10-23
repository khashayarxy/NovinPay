@extends('layouts.admin')
@section('title','بازبینی خصوصی مقاله')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">بازبینی خصوصی · فقط کارکنان مجاز</p><h1>بازبینی خصوصی مقاله</h1><p>{{ $mode==='published'?'تصویر ثابت محتوای منتشرشده':'پیش‌نویس فعلی؛ ذخیره به معنی انتشار نیست' }}</p></div><a class="btn btn-secondary" href="{{ route('admin.articles.index') }}">فهرست مقاله‌ها</a></header>
<x-admin.errors/>
<div class="repair-layout"><article class="admin-panel"><h2>{{ $version->title }}</h2><p class="admin-hint">{{ $version->category->name }} · نسخهٔ {{ \App\Support\FaNumber::digits($version->revision) }} · <x-finance-time :value="$version->created_at"/></p><p class="article-summary">{{ $version->summary }}</p><x-article-content :blocks="$version->blocks"/></article>
<aside class="admin-panel"><h2>کنترل انتشار</h2><p class="crm-code">{{ $article->slug }}</p><p>نسخهٔ کنترلی مقاله: {{ \App\Support\FaNumber::digits($article->version) }}</p><p class="admin-hint">هر اقدام فقط روی نسخهٔ همین صفحه انجام می‌شود؛ تغییر هم‌زمان نیازمند بازبینی دوباره است.</p>
<div class="admin-actions mt-4"><a class="btn btn-secondary" href="{{ route('admin.articles.preview',[$article,'mode'=>'draft']) }}">پیش‌نویس</a>@if($article->published_version_id)<a class="btn btn-secondary" href="{{ route('admin.articles.preview',[$article,'mode'=>'published']) }}">نسخهٔ منتشرشده</a>@endif @if($articleAllows('articles.manage'))<a class="btn btn-secondary" href="{{ route('admin.articles.edit',$article) }}">ویرایش پیش‌نویس</a>@endif</div>
@if($article->published_at)<p class="mt-4">زمان انتشار فعلی: <x-finance-time :value="$article->published_at"/></p>@elseif($article->unpublished_at)<p class="mt-4">زمان لغو انتشار: <x-finance-time :value="$article->unpublished_at"/></p>@endif
@if($articleAllows('articles.publish'))
@if($mode==='draft' && $article->draft_version_id!==$article->published_version_id)<form class="article-form mt-4" method="post" action="{{ route('admin.articles.publish',$article) }}">@csrf<input type="hidden" name="version" value="{{ $article->version }}"><input type="hidden" name="reviewed_version_id" value="{{ $version->id }}"><label class="admin-checkbox"><input type="checkbox" name="confirmed" value="1" required> همین نسخه و دسته را برای انتشار صریح بازبینی کردم.</label><button class="btn btn-primary" type="submit">انتشار همین پیش‌نویس</button></form>@endif
@if($mode==='published')<form class="article-form mt-4" method="post" action="{{ route('admin.articles.unpublish',$article) }}">@csrf<input type="hidden" name="version" value="{{ $article->version }}"><input type="hidden" name="reviewed_version_id" value="{{ $version->id }}"><label class="admin-checkbox"><input type="checkbox" name="confirmed" value="1" required> لغو انتشار این نسخه را تأیید می‌کنم؛ سابقه حذف نمی‌شود.</label><button class="btn btn-secondary" type="submit">لغو انتشار</button></form>@endif
@endif
</aside></div>
@endsection
