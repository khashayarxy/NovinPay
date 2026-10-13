@extends('layouts.app')

@section('content')
    <section class="border-b border-slate-200 bg-white">
        <div class="container-page section grid items-center gap-10 lg:grid-cols-2">
            <div>
                <p class="text-sm font-semibold text-brand-700">دستگاه کارتخوان (POS)</p>
                <h1 class="mt-3 text-3xl font-bold leading-10 tracking-tight sm:text-4xl">
                    فروش و تعمیر دستگاه‌های کارتخوان در شهریار
                </h1>
                <p class="mt-5 max-w-xl text-base leading-8 text-slate-600 sm:text-lg">
                    {{ config('app.name') }} در زمینهٔ فروش، نصب و تعمیر دستگاه‌های کارتخوان برای فروشگاه‌ها و
                    کسب‌وکارها فعالیت می‌کند. این نسخهٔ اولیهٔ وب‌سایت است؛ امکان خرید آنلاین و ثبت درخواست تعمیر
                    به‌صورت آنلاین در نسخه‌های بعدی اضافه خواهد شد.
                </p>
                <div class="mt-7 flex flex-wrap gap-3">
                    <a href="{{ route('products.index') }}" class="btn btn-primary">مشاهده محصولات</a>
                    <a href="{{ route('repair') }}" class="btn btn-secondary">درخواست تعمیر دستگاه</a>
                </div>
            </div>

            <div class="card p-6 sm:p-7">
                <h2 class="text-base font-semibold text-slate-900">خدمات {{ config('app.name') }}</h2>
                <ul class="mt-5 space-y-5">
                    <li class="flex gap-4">
                        <span class="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-sm font-bold text-brand-700">
                            ۱
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-slate-900">فروش دستگاه کارتخوان</p>
                            <p class="mt-1 text-sm leading-7 text-slate-600">
                                ارائهٔ دستگاه‌های نو و کارکرده به همراه لوازم جانبی و مصرفی موردنیاز کسب‌وکار.
                            </p>
                        </div>
                    </li>
                    <li class="flex gap-4">
                        <span class="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-sm font-bold text-brand-700">
                            ۲
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-slate-900">تعمیر و سرویس</p>
                            <p class="mt-1 text-sm leading-7 text-slate-600">
                                عیب‌یابی و تعمیر دستگاه‌های کارتخوان توسط کارشناسان و پیگیری مرحله به مرحلهٔ تعمیر.
                            </p>
                        </div>
                    </li>
                    <li class="flex gap-4">
                        <span class="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-sm font-bold text-brand-700">
                            ۳
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-slate-900">لوازم جانبی و مصرفی</p>
                            <p class="mt-1 text-sm leading-7 text-slate-600">
                                کابل، کیف محافظ، کاغذ حرارتی و سایر ملزومات دستگاه‌های کارتخوان.
                            </p>
                        </div>
                    </li>
                </ul>
                <a href="{{ route('services') }}" class="mt-6 inline-block text-sm font-semibold text-brand-700 hover:underline">
                    توضیحات کامل خدمات →
                </a>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="services-heading">
        <div class="container-page">
            <h2 id="services-heading" class="section-title">فروش و تعمیر دستگاه کارتخوان</h2>
            <p class="section-lead">
                دو خدمت اصلی {{ config('app.name') }} از این بخش قابل دسترسی است؛ جزئیات هر خدمت در صفحهٔ خدمات آمده
                است.
            </p>

            <div class="mt-8 grid gap-6 md:grid-cols-2">
                <article class="card p-6">
                    <h3 class="text-lg font-semibold">فروش دستگاه کارتخوان</h3>
                    <p class="mt-3 text-sm leading-8 text-slate-600">
                        دستگاه‌های کارتخوان نو و کارکرده برای فروشگاه‌ها، رستوران‌ها و کسب‌وکارهای کوچک، همراه با
                        راهنمای انتخاب دستگاه متناسب با نوع کسب‌وکار.
                    </p>
                    <ul class="mt-4 space-y-2 text-sm text-slate-600">
                        <li>— دستگاه‌های ثابت و سیار</li>
                        <li>— وضعیت نو و کارکرده با ذکر شفاف وضعیت کالا</li>
                        <li>— لوازم جانبی و مصرفی مرتبط</li>
                    </ul>
                    <a href="{{ route('products.index') }}" class="btn btn-secondary mt-6">مشاهده محصولات</a>
                </article>

                <article class="card p-6">
                    <h3 class="text-lg font-semibold">تعمیر و سرویس دستگاه</h3>
                    <p class="mt-3 text-sm leading-8 text-slate-600">
                        عیب‌یابی و تعمیر دستگاه‌های کارتخوان، تعیین وضعیت دستگاه پس از بررسی و هماهنگی با شما پیش از
                        انجام کارهای هزینه‌بر.
                    </p>
                    <ul class="mt-4 space-y-2 text-sm text-slate-600">
                        <li>— پذیرش حضوری دستگاه</li>
                        <li>— عیب‌یابی و اعلام وضعیت قبل از تعمیر</li>
                        <li>— کد پیگیری برای پیگیری روند تعمیر</li>
                    </ul>
                    <a href="{{ route('repair') }}" class="btn btn-secondary mt-6">توضیحات درخواست تعمیر</a>
                </article>
            </div>
        </div>
    </section>

    <section class="border-y border-slate-200 bg-white" aria-labelledby="featured-heading">
        <div class="container-page section">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 id="featured-heading" class="section-title">محصولات</h2>
                    <p class="section-lead">
                        نمونه‌ای از کالاهای قابل ارائه. قیمت‌ها و موجودی‌ها پس از تکمیل بخش مدیریت، به‌صورت واقعی
                        به‌روزرسانی می‌شوند.
                    </p>
                </div>
                <a href="{{ route('products.index') }}" class="btn btn-secondary">مشاهده همه محصولات</a>
            </div>

            <div class="mt-8">
                @if ($featuredProducts->isEmpty())
                    <x-empty-state title="هنوز محصولی ثبت نشده است"
                        message="کاتالوگ محصولات در حال تکمیل است. به‌محض انتشار اطلاعات کالاها، این بخش نمایش داده خواهد شد.">
                        <a href="{{ route('repair') }}" class="btn btn-secondary">درخواست تعمیر دستگاه</a>
                    </x-empty-state>
                @else
                    <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($featuredProducts as $product)
                            <x-product-card :product="$product" />
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="repair-cta-heading">
        <div class="container-page">
            <div class="card flex flex-col items-start gap-6 p-6 sm:p-8 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 id="repair-cta-heading" class="text-xl font-bold sm:text-2xl">دستگاه شما مشکل دارد؟</h2>
                    <p class="mt-3 max-w-2xl text-sm leading-8 text-slate-600">
                        روند پذیرش و تعمیر دستگاه، مدارک موردنیاز و شیوهٔ پیگیری وضعیت تعمیر را در صفحهٔ تعمیر بخوانید.
                        ثبت آنلاین درخواست در نسخه‌های بعدی فعال می‌شود.
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-3">
                    <a href="{{ route('repair') }}" class="btn btn-primary">ورود به صفحهٔ تعمیر</a>
                    <a href="{{ route('contact') }}" class="btn btn-secondary">تماس با ما</a>
                </div>
            </div>
        </div>
    </section>

    <section class="border-t border-slate-200 bg-white" aria-labelledby="contact-strip-heading">
        <div class="container-page py-10">
            <h2 id="contact-strip-heading" class="text-base font-semibold text-slate-900">اطلاعات تماس</h2>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                <div class="rounded-lg border border-dashed border-slate-300 p-4">
                    <dt class="font-medium text-slate-500">نشانی</dt>
                    <dd class="mt-1 text-slate-700">شهریار — [نشانی دقیق در حال تکمیل]</dd>
                </div>
                <div class="rounded-lg border border-dashed border-slate-300 p-4">
                    <dt class="font-medium text-slate-500">تلفن</dt>
                    <dd class="mt-1 text-slate-700">[در حال تکمیل]</dd>
                </div>
                <div class="rounded-lg border border-dashed border-slate-300 p-4">
                    <dt class="font-medium text-slate-500">ساعات کاری</dt>
                    <dd class="mt-1 text-slate-700">[در حال تکمیل]</dd>
                </div>
            </dl>
            <p class="mt-4 text-xs leading-6 text-slate-500">
                این مقادیر موقتی هستند و پیش از انتشار نهایی با اطلاعات رسمی جایگزین می‌شوند.
            </p>
        </div>
    </section>
@endsection
