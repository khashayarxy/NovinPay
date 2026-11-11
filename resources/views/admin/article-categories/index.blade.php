@extends('layouts.admin')
@section('title','دسته‌بندی مقالات')
@section('content')
<header class="admin-page-heading"><h1>دسته‌بندی مقالات</h1><a class="btn btn-primary" href="{{ route('admin.article-categories.create') }}">دستهٔ جدید</a></header>
<x-admin.errors/>
<section class="admin-panel">
    @forelse($categories as $category)
        <div class="admin-record-row">
            <div><h2>{{ $category->name }}</h2><bdi class="admin-muted">{{ $category->slug }}</bdi></div>
            <span class="badge {{ $category->is_active ? 'badge-success' : 'badge-neutral' }}">{{ $category->is_active ? 'فعال' : 'غیرفعال' }}</span>
            <a class="admin-text-link" href="{{ route('admin.article-categories.edit', $category) }}">ویرایش دسته</a>
        </div>
    @empty
        <p class="admin-hint">دسته‌ای ثبت نشده است.</p>
    @endforelse
    {{ $categories->links() }}
</section>
@endsection
