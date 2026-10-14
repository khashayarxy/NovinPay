@extends('layouts.app')

@section('title', 'سایت در حال تعمیر است')

@section('content')
    <div class="container-page section">
        <div class="mx-auto max-w-xl text-center">
            <p class="text-sm font-semibold text-brand-700">خطای ۵۰۳</p>
            <h1 class="mt-3 text-2xl font-bold tracking-tight sm:text-3xl">
                سایت موقتاً در دسترس نیست
            </h1>
            <p class="mt-4 text-sm leading-8 text-slate-600">
                وب‌سایت برای انجام تعمیرات یا به‌روزرسانی به‌صورت موقت از دسترس خارج است. لطفاً کمی بعد دوباره مراجعه
                کنید.
            </p>
            <div class="mt-7 flex flex-wrap justify-center gap-3">
                <a href="{{ route('home') }}" class="btn btn-primary">تلاش مجدد</a>
                <a href="{{ route('contact') }}" class="btn btn-secondary">تماس با ما</a>
            </div>
        </div>
    </div>
@endsection
