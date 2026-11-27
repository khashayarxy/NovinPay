@extends('layouts.admin')

@section('title', 'افزودن محصول')

@section('content')
    <div class="admin-form-surface">
        <a href="{{ route('admin.products.index') }}" class="admin-back-link">← فهرست محصولات</a>
        <header class="admin-page-heading">
            <div>
                <h1>افزودن محصول</h1>
            </div>
        </header>

        @include('admin.products._form', [
            'action' => route('admin.products.store'),
            'method' => 'POST',
            'submitLabel' => 'ایجاد محصول',
        ])
    </div>
@endsection
