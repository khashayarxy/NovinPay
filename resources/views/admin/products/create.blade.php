@extends('layouts.admin')

@section('title', 'افزودن محصول')

@section('content')
    <div class="admin-form-surface">
        <header class="admin-page-heading">
            <div>
                <h1>افزودن محصول</h1>
            </div>
            <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">فهرست محصولات</a>
        </header>

        @include('admin.products._form', [
            'action' => route('admin.products.store'),
            'method' => 'POST',
            'submitLabel' => 'ایجاد محصول',
        ])
    </div>
@endsection
