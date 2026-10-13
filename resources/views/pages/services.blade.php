@extends('layouts.app')

@section('title', 'خدمات')
@section('meta_description', 'فروش، نصب و تعمیر دستگاه‌های کارتخوان (POS) — خدمات NovinPay')

@section('content')
    <div class="container-page section">
        <header>
            <h1 class="section-title">خدمات فروش و تعمیر دستگاه کارتخوان</h1>
            <p class="section-lead">
                {{ config('app.name') }} دو خدمت اصلی به کسب‌وکارهای شهریار ارائه می‌دهد: تأمین دستگاه کارتخوان و
                تعمیر دستگاه‌های موجود. جزئیات قیمت، شرایط گارانتی و زمان‌بندی هنوز نهایی نشده است.
            </p>
        </header>

        <div class="mt-10 grid gap-6 lg:grid-cols-2">
            <section class="card p-6 sm:p-8" aria-labelledby="sales-heading">
                <h2 id="sales-heading" class="text-xl font-bold">فروش دستگاه کارتخوان</h2>
                <p class="mt-4 text-sm leading-8 text-slate-600">
                    دستگاه‌های کارتخوان برای فروشگاه‌ها، رستوران‌ها، واحدهای خدماتی و سایر کسب‌وکارها در دو دستهٔ
                    ثابت و سیار عرضه می‌شوند. پیش از خرید، نوع دستگاه بر اساس نوع کسب‌وکار، حجم تراکنش و محل نصب
                    به شما پیشنهاد می‌شود.
                </p>
                <ul class="mt-5 space-y-3 text-sm text-slate-600">
                    <li class="flex gap-3"><span class="text-brand-700">—</span> دستگاه‌های کارتخوان نو و کارکرده با ذکر شفاف وضعیت کالا</li>
                    <li class="flex gap-3"><span class="text-brand-700">—</span> راهنمای انتخاب دستگاه متناسب با نیاز کسب‌وکار</li>
                    <li class="flex gap-3"><span class="text-brand-700">—</span> لوازم جانبی: کابل، کیف محافظ و لوازم مصرفی</li>
                    <li class="flex gap-3"><span class="text-brand-700">—</span> راهنمای نصب و راه‌اندازی اولیهٔ دستگاه</li>
                </ul>
                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('products.index') }}" class="btn btn-primary">مشاهده محصولات</a>
                </div>
                <p class="mt-4 text-xs leading-6 text-slate-500">
                    شرایط قیمت‌گذاری، ارسال و گارانتی پس از تأیید نهایی در این صفحه اعلام می‌شود.
                </p>
            </section>

            <section class="card p-6 sm:p-8" aria-labelledby="repair-service-heading">
                <h2 id="repair-service-heading" class="text-xl font-bold">تعمیر و سرویس دستگاه</h2>
                <p class="mt-4 text-sm leading-8 text-slate-600">
                    دستگاه‌های کارتخوان پس از پذیرش، توسط کارشناسان عیب‌یابی می‌شوند. وضعیت دستگاه و هزینهٔ احتمالی
                    پیش از انجام کارهای هزینه‌بر با شما هماهنگ خواهد شد.
                </p>
                <ul class="mt-5 space-y-3 text-sm text-slate-600">
                    <li class="flex gap-3"><span class="text-brand-700">—</span> پذیرش حضوری دستگاه و ثبت مشکل اعلامی</li>
                    <li class="flex gap-3"><span class="text-brand-700">—</span> عیب‌یابی و اعلام وضعیت پیش از تعمیر</li>
                    <li class="flex gap-3"><span class="text-brand-700">—</span> تعمیر، سرویس دوره‌ای و تعویض قطعات</li>
                    <li class="flex gap-3"><span class="text-brand-700">—</span> ارائهٔ کد پیگیری برای پیگیری روند تعمیر</li>
                </ul>
                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('repair') }}" class="btn btn-primary">درخواست تعمیر</a>
                </div>
                <p class="mt-4 text-xs leading-6 text-slate-500">
                    ثبت آنلاین درخواست و پیگیری آنلاین تعمیر در نسخه‌های بعدی وب‌سایت فعال می‌شود.
                </p>
            </section>
        </div>

        <section class="mt-10 rounded-xl border border-dashed border-slate-300 p-6" aria-labelledby="pending-heading">
            <h2 id="pending-heading" class="text-base font-semibold text-slate-800">در حال تکمیل</h2>
            <p class="mt-2 text-sm leading-7 text-slate-600">
                تعرفهٔ خدمات، شرایط گارانتی، زمان تقریبی تعمیر و نحوهٔ ارسال دستگاه هنوز اعلام نشده است. این موارد
                پس از تأیید نهایی به همین صفحه اضافه خواهد شد.
            </p>
        </section>
    </div>
@endsection
