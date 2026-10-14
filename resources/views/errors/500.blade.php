@extends('layouts.app')

@section('title', 'خطای داخلی')

@section('content')
    <div class="container-page section">
        <div class="mx-auto max-w-xl text-center">
            <p class="text-sm font-semibold text-brand-700">خطای ۵۰۰</p>
            <h1 class="mt-3 text-2xl font-bold tracking-tight sm:text-3xl">
                خطایی در سرور رخ داد
            </h1>
            <p class="mt-4 text-sm leading-8 text-slate-600">
                متأسفانه پردازش درخواست شما با خطا مواجه شد. لطفاً چند لحظهٔ دیگر دوباره تلاش کنید؛ اگر مشکل ادامه
                داشت، با ما تماس بگیرید.
            </p>
            <div class="mt-7 flex flex-wrap justify-center gap-3">
                <a href="{{ route('home') }}" class="btn btn-primary">بازگشت به صفحهٔ اصلی</a>
                <a href="{{ route('products.index') }}" class="btn btn-secondary">مشاهده محصولات</a>
            </div>
        </div>
    </div>
@endsection
