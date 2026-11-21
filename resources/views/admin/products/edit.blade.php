@extends('layouts.admin')

@section('title', 'ویرایش محصول')

@section('content')
    <div class="admin-form-surface">
        <header class="admin-page-heading">
            <div>
                <h1>ویرایش محصول</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $product->name }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.products.feedback.index', $product) }}" class="btn btn-secondary">دیدگاه‌ها و پرسش‌ها</a>
                <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">فهرست محصولات</a>
            </div>
        </header>

        @include('admin.products._form', [
            'action' => route('admin.products.update', $product),
            'method' => 'PUT',
            'submitLabel' => 'ذخیرهٔ تغییرات',
        ])
    </div>
@endsection
