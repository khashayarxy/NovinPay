@extends('layouts.admin')
@section('title','دسته‌های مقاله')
@section('content')
<header class="admin-page-heading"><div><h1>دسته‌های مقاله</h1><p>دسته‌های محتوا مستقل از دسته‌های محصول هستند. حذف مخرب وجود ندارد.</p></div><a class="btn btn-primary" href="{{ route('admin.article-categories.create') }}">دستهٔ جدید</a></header><x-admin.errors/>
<div class="article-card-list">@forelse($categories as $category)<section class="admin-panel"><h2>{{ $category->name }}</h2><p>{{ $category->is_active?'فعال':'غیرفعال' }}</p><p class="crm-code">{{ $category->slug }}</p><a class="btn btn-secondary mt-4" href="{{ route('admin.article-categories.edit',$category) }}">ویرایش دسته</a></section>@empty<section class="admin-panel admin-empty"><h2>دسته‌ای ثبت نشده است</h2><p>پیش از ایجاد مقاله، یک دستهٔ فعال بسازید.</p></section>@endforelse</div>{{ $categories->links() }}
@endsection
