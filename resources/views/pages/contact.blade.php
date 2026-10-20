@extends('layouts.app')

@section('title', 'تماس با ما')
@section('meta_description', 'اطلاعات تماس NovinPay — شهریار')

@section('content')
    <div class="container-page section">
        <header>
            <h1 class="section-title">تماس با ما</h1>
            <p class="section-lead">
                برای مشاورهٔ خرید یا هماهنگی مراجعهٔ حضوری به {{ config('business.name') }}، از اطلاعات زیر استفاده کنید.
            </p>
        </header>

        <dl class="mt-6 grid gap-6 sm:grid-cols-2">
            <div class="card p-6">
                <dt class="text-sm font-semibold text-slate-900">نشانی</dt>
                <dd class="mt-2 text-sm leading-7 text-slate-600">
                    {{ config('business.address') }}
                </dd>
            </div>

            <div class="card p-6">
                <dt class="text-sm font-semibold text-slate-900">تلفن</dt>
                <dd class="mt-2 text-sm leading-7 text-slate-600">
                    <a href="{{ config('business.phone_uri') }}" class="contact-link" dir="ltr">{{ config('business.phone') }}</a>
                </dd>
            </div>

            <div class="card p-6">
                <dt class="text-sm font-semibold text-slate-900">مسیریابی</dt>
                <dd class="mt-2 text-sm leading-7 text-slate-600">
                    <x-map-links />
                </dd>
            </div>

            <div class="card p-6">
                <dt class="text-sm font-semibold text-slate-900">ساعات کاری</dt>
                <dd class="mt-2 text-sm leading-7 text-slate-600">
                    {{ config('business.hours.open') }}<br>{{ config('business.hours.closed') }}
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
