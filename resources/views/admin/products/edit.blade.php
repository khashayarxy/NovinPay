@extends('layouts.admin')

@section('title', 'ویرایش محصول')

@section('content')
    <div class="admin-form-surface">
        <header class="admin-page-heading">
            <div>
                <h1>ویرایش محصول</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $product->name }}</p>
            </div>
            <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">فهرست محصولات</a>
        </header>

        @include('admin.products._form', [
            'action' => route('admin.products.update', $product),
            'method' => 'PUT',
            'submitLabel' => 'ذخیرهٔ تغییرات',
        ])
    </div>
@endsection
