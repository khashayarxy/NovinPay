@extends('layouts.app')

@section('title', 'صفحه پیدا نشد')

@section('content')
    <div class="container-page section">
        <div class="mx-auto max-w-xl text-center">
            <p class="text-sm font-semibold text-brand-700">خطای ۴۰۴</p>
            <h1 class="mt-3 text-2xl font-bold tracking-tight sm:text-3xl">
                صفحه‌ای که دنبال آن بودید پیدا نشد
            </h1>
            <p class="mt-4 text-sm leading-8 text-slate-600">
                ممکن است آدرس صفحه اشتباه باشد یا محصول موردنظر از فهرست حذف شده یا هنوز منتشر نشده باشد.
                می‌توانید از فهرست محصولات دیدن کنید یا به صفحهٔ اصلی بازگردید.
            </p>
            <div class="mt-7 flex flex-wrap justify-center gap-3">
                <a href="{{ route('products.index') }}" class="btn btn-primary">مشاهده محصولات</a>
                <a href="{{ route('home') }}" class="btn btn-secondary">بازگشت به صفحهٔ اصلی</a>
            </div>
        </div>
    </div>
@endsection
