@extends('layouts.admin')

@section('title', 'ویرایش محصول')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold">ویرایش محصول</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $product->name }}</p>
            </div>
            <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">بازگشت به فهرست</a>
        </div>

        @include('admin.products._form', [
            'action' => route('admin.products.update', $product),
            'method' => 'PUT',
            'submitLabel' => 'ذخیرهٔ تغییرات',
        ])
    </div>
@endsection
