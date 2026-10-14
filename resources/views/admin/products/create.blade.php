@extends('layouts.admin')

@section('title', 'افزودن محصول')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold">افزودن محصول</h1>
                <p class="mt-1 text-sm text-slate-600">مشخصات محصول را وارد کنید.</p>
            </div>
            <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">بازگشت به فهرست</a>
        </div>

        @include('admin.products._form', [
            'action' => route('admin.products.store'),
            'method' => 'POST',
            'submitLabel' => 'ایجاد محصول',
        ])
    </div>
@endsection
