@extends('layouts.app')

@section('title', 'تماس با ما')
@section('meta_description', 'اطلاعات تماس NovinPay — شهریار')

@section('content')
    <div class="container-page section">
        <header>
            <h1 class="section-title">تماس با ما</h1>
            <p class="section-lead">
                {{ config('app.name') }} در شهریار فعالیت می‌کند. اطلاعات تماس رسمی هنوز نهایی نشده است و مقادیر
                زیر موقتی و نمادین هستند.
            </p>
        </header>

        <div class="mt-8 rounded-xl border border-dashed border-amber-300 bg-amber-50 px-5 py-4">
            <p class="text-sm leading-7 text-amber-800">
                <strong class="font-semibold">توجه:</strong> نشانی دقیق، شماره تلفن، ایمیل و ساعات کاری در این
                نسخه اعلام نشده‌اند. پیش از انتشار نهایی وب‌سایت، این مقادیر با اطلاعات تأییدشده جایگزین خواهند شد.
            </p>
        </div>

        <dl class="mt-6 grid gap-6 sm:grid-cols-2">
            <div class="card p-6">
                <dt class="text-sm font-semibold text-slate-900">نشانی</dt>
                <dd class="mt-2 text-sm leading-7 text-slate-600">
                    شهریار، استان تهران<br>
                    <span class="text-slate-500">[خیابان و پلاک — در حال تکمیل]</span>
                </dd>
            </div>

            <div class="card p-6">
                <dt class="text-sm font-semibold text-slate-900">تلفن</dt>
                <dd class="mt-2 text-sm leading-7 text-slate-600">
                    <span class="text-slate-500">[شماره تماس — در حال تکمیل]</span>
                </dd>
            </div>

            <div class="card p-6">
                <dt class="text-sm font-semibold text-slate-900">ایمیل</dt>
                <dd class="mt-2 text-sm leading-7 text-slate-600">
                    <span class="text-slate-500">[نشانی ایمیل — در حال تکمیل]</span>
                </dd>
            </div>

            <div class="card p-6">
                <dt class="text-sm font-semibold text-slate-900">ساعات کاری</dt>
                <dd class="mt-2 text-sm leading-7 text-slate-600">
                    <span class="text-slate-500">[ساعات کاری — در حال تکمیل]</span>
                </dd>
            </div>
        </dl>

        <section class="mt-8 card p-6" aria-labelledby="contact-form-heading">
            <h2 id="contact-form-heading" class="text-lg font-bold">پیام اینترنتی</h2>
            <p class="mt-3 text-sm leading-8 text-slate-600">
                فرم ارسال پیام هنوز به سامانه‌ای متصل نیست و در نسخه‌های بعدی وب‌سایت اضافه خواهد شد. تا زمان
                راه‌اندازی، از طریق مراجعهٔ حضوری می‌توانید با ما در ارتباط باشید.
            </p>
            <div class="mt-5 flex flex-wrap gap-3">
                <a href="{{ route('repair') }}" class="btn btn-secondary">درخواست تعمیر دستگاه</a>
                <a href="{{ route('products.index') }}" class="btn btn-secondary">مشاهده محصولات</a>
            </div>
        </section>
    </div>
@endsection
