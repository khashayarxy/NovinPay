@extends('layouts.app')

@section('title', 'نشست منقضی شد')

@section('content')
    <div class="container-page section">
        <div class="mx-auto max-w-xl text-center">
            <p class="text-sm font-semibold text-brand-700">خطای ۴۱۹</p>
            <h1 class="mt-3 text-2xl font-bold tracking-tight sm:text-3xl">
                نشست شما منقضی شده است
            </h1>
            <p class="mt-4 text-sm leading-8 text-slate-600">
                به دلیل گذشت زمان، امنیت فرم ارسال‌شده از بین رفته است. لطفاً صفحه را دوباره باز کنید و مجدداً تلاش
                کنید.
            </p>
            <div class="mt-7 flex flex-wrap justify-center gap-3">
                <a href="{{ route('home') }}" class="btn btn-primary">بازگشت به صفحهٔ اصلی</a>
                <a href="{{ route('admin.login') }}" class="btn btn-secondary">ورود مدیر</a>
            </div>
        </div>
    </div>
@endsection
