@extends('layouts.app')

@section('title', 'شرایط استفاده')
@section('meta_description', 'ساختار صفحهٔ شرایط استفاده از وب‌سایت NovinPay')

@section('content')
    <div class="container-page section max-w-3xl">
        <header>
            <h1 class="section-title">شرایط استفاده</h1>
            <p class="section-lead">
                این صفحه در مرحلهٔ اولیهٔ ساختار خود را دارد و متن نهایی آن پس از بازبینی حقوقی منتشر خواهد شد.
                هیچ شرط، تعهد یا ضمانتی در این نسخه اعلام نمی‌شود.
            </p>
        </header>

        <div class="mt-6 rounded-xl border border-dashed border-slate-300 px-5 py-4">
            <p class="text-sm leading-7 text-slate-600">
                <strong class="font-semibold text-slate-800">وضعیت:</strong> پیش‌نویس — مفاد نهایی هنوز تصویب
                نشده است.
            </p>
        </div>

        <ol class="mt-8 space-y-6">
            <li class="card p-6">
                <h2 class="text-base font-semibold">۱. دامنهٔ کاربرد</h2>
                <p class="mt-3 text-sm leading-8 text-slate-600">
                    [متن نهایی پس از بازبینی حقوقی درج می‌شود.]
                </p>
            </li>
            <li class="card p-6">
                <h2 class="text-base font-semibold">۲. شرایط استفاده از وب‌سایت</h2>
                <p class="mt-3 text-sm leading-8 text-slate-600">
                    [متن نهایی پس از بازبینی حقوقی درج می‌شود.]
                </p>
            </li>
            <li class="card p-6">
                <h2 class="text-base font-semibold">۳. سفارش، قیمت و پرداخت</h2>
                <p class="mt-3 text-sm leading-8 text-slate-600">
                    [متن نهایی پس از بازبینی حقوقی درج می‌شود. امکان خرید آنلاین در این نسخه فعال نیست.]
                </p>
            </li>
            <li class="card p-6">
                <h2 class="text-base font-semibold">۴. خدمات تعمیر و گارانتی</h2>
                <p class="mt-3 text-sm leading-8 text-slate-600">
                    [متن نهایی پس از بازبینی حقوقی درج می‌شود. شرایط تعمیر و گارانتی هنوز اعلام نشده است.]
                </p>
            </li>
            <li class="card p-6">
                <h2 class="text-base font-semibold">۵. مسئولیت‌ها و محدودیت‌ها</h2>
                <p class="mt-3 text-sm leading-8 text-slate-600">
                    [متن نهایی پس از بازبینی حقوقی درج می‌شود.]
                </p>
            </li>
            <li class="card p-6">
                <h2 class="text-base font-semibold">۶. تغییرات شرایط</h2>
                <p class="mt-3 text-sm leading-8 text-slate-600">
                    [متن نهایی پس از بازبینی حقوقی درج می‌شود.]
                </p>
            </li>
        </ol>

        <p class="mt-8 text-sm text-slate-600">
            برای اطلاع از وضعیت این صفحه، به
            <a href="{{ route('contact') }}" class="font-semibold text-brand-700 hover:underline">صفحهٔ تماس با ما</a>
            مراجعه کنید.
        </p>
    </div>
@endsection
