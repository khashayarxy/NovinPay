@extends('layouts.app')

@section('title', 'دسترسی غیرمجاز')

@section('content')
    <div class="container-page section">
        <div class="mx-auto max-w-xl text-center">
            <p class="text-sm font-semibold text-brand-700">خطای ۴۰۳</p>
            <h1 class="mt-3 text-2xl font-bold tracking-tight sm:text-3xl">
                دسترسی به این بخش مجاز نیست
            </h1>
            <p class="mt-4 text-sm leading-8 text-slate-600">
                شما مجوز مشاهدهٔ این صفحه را ندارید. اگر فکر می‌کنید این خطا باید برطرف شود، با مدیر وب‌سایت تماس
                بگیرید یا به صفحهٔ اصلی بازگردید.
            </p>
            <div class="mt-7 flex flex-wrap justify-center gap-3">
                <a href="{{ route('home') }}" class="btn btn-primary">بازگشت به صفحهٔ اصلی</a>
                <a href="{{ route('products.index') }}" class="btn btn-secondary">مشاهده محصولات</a>
            </div>
        </div>
    </div>
@endsection
