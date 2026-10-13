@extends('layouts.app')

@section('title', 'حریم خصوصی')
@section('meta_description', 'ساختار صفحهٔ حریم خصوصی وب‌سایت NovinPay')

@section('content')
    <div class="container-page section max-w-3xl">
        <header>
            <h1 class="section-title">حریم خصوصی</h1>
            <p class="section-lead">
                این صفحه ساختار کلی نحوهٔ برخورد با اطلاعات بازدیدکنندگان را نشان می‌دهد. متن نهایی پس از بازبینی
                حقوقی منتشر می‌شود و تا آن زمان هیچ ادعایی دربارهٔ جمع‌آوری یا استفاده از داده‌ها مطرح نیست.
            </p>
        </header>

        <div class="mt-6 rounded-xl border border-dashed border-slate-300 px-5 py-4">
            <p class="text-sm leading-7 text-slate-600">
                <strong class="font-semibold text-slate-800">وضعیت:</strong> پیش‌نویس — متن نهایی هنوز تصویب
                نشده است. در این نسخه از وب‌سایت هیچ فرآیند ثبت‌نام، خرید یا ارسال فرم فعال نیست و داده‌ای از
                بازدیدکنندگان دریافت نمی‌شود.
            </p>
        </div>

        <ol class="mt-8 space-y-6">
            <li class="card p-6">
                <h2 class="text-base font-semibold">۱. داده‌های دریافتی</h2>
                <p class="mt-3 text-sm leading-8 text-slate-600">
                    [متن نهایی پس از بازبینی حقوقی درج می‌شود.]
                </p>
            </li>
            <li class="card p-6">
                <h2 class="text-base font-semibold">۲. نحوهٔ استفاده از اطلاعات</h2>
                <p class="mt-3 text-sm leading-8 text-slate-600">
                    [متن نهایی پس از بازبینی حقوقی درج می‌شود.]
                </p>
            </li>
            <li class="card p-6">
                <h2 class="text-base font-semibold">۳. نگهداری و حفاظت از داده‌ها</h2>
                <p class="mt-3 text-sm leading-8 text-slate-600">
                    [متن نهایی پس از بازبینی حقوقی درج می‌شود.]
                </p>
            </li>
            <li class="card p-6">
                <h2 class="text-base font-semibold">۴. کوکی‌ها و ابزارهای تحلیلی</h2>
                <p class="mt-3 text-sm leading-8 text-slate-600">
                    [متن نهایی پس از بازبینی حقوقی درج می‌شود. در این نسخه ابزار تحلیلی از شخص ثالث اضافه نشده
                    است.]
                </p>
            </li>
            <li class="card p-6">
                <h2 class="text-base font-semibold">۵. حقوق کاربر</h2>
                <p class="mt-3 text-sm leading-8 text-slate-600">
                    [متن نهایی پس از بازبینی حقوقی درج می‌شود.]
                </p>
            </li>
            <li class="card p-6">
                <h2 class="text-base font-semibold">۶. تماس با ما دربارهٔ حریم خصوصی</h2>
                <p class="mt-3 text-sm leading-8 text-slate-600">
                    [متن نهایی پس از بازبینی حقوقی درج می‌شود. راه‌های تماس در
                    <a href="{{ route('contact') }}" class="font-semibold text-brand-700 hover:underline">صفحهٔ تماس
                        با ما</a>
                    اعلام خواهد شد.]
                </p>
            </li>
        </ol>
    </div>
@endsection
