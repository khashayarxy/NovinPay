@extends('layouts.app')

@section('title', 'درخواست تعمیر')
@section('meta_description', 'روند پذیرش، تعمیر و پیگیری دستگاه کارتخوان — NovinPay')

@section('content')
    <div class="container-page section">
        <header>
            <h1 class="section-title">درخواست تعمیر دستگاه کارتخوان</h1>
            <p class="section-lead">
                در این صفحه می‌توانید با روند پذیرش و تعمیر دستگاه و شیوهٔ پیگیری وضعیت آن آشنا شوید. ثبت آنلاین
                درخواست هنوز راه‌اندازی نشده است و در نسخه‌های بعدی وب‌سایت فعال خواهد شد.
            </p>
        </header>

        <div class="mt-10 grid gap-6 lg:grid-cols-3">
            <section class="card p-6" aria-labelledby="step-1">
                <p class="text-xs font-semibold text-brand-700">مرحلهٔ ۱</p>
                <h2 id="step-1" class="mt-2 text-base font-semibold">ثبت درخواست</h2>
                <p class="mt-3 text-sm leading-7 text-slate-600">
                    در حال حاضر ثبت درخواست به‌صورت حضوری و با مراجعه به محل کسب‌وکار انجام می‌شود. برای هماهنگی با
                    <a href="{{ config('business.phone_uri') }}" class="contact-link" dir="ltr">{{ config('business.phone') }}</a> تماس بگیرید.
                </p>
            </section>

            <section class="card p-6" aria-labelledby="step-2">
                <p class="text-xs font-semibold text-brand-700">مرحلهٔ ۲</p>
                <h2 id="step-2" class="mt-2 text-base font-semibold">عیب‌یابی و اعلام وضعیت</h2>
                <p class="mt-3 text-sm leading-7 text-slate-600">
                    دستگاه پس از پذیرش عیب‌یابی می‌شود. وضعیت دستگاه و هزینهٔ احتمالی پیش از انجام تعمیر هزینه‌بر با
                    شما هماهنگ خواهد شد.
                </p>
            </section>

            <section class="card p-6" aria-labelledby="step-3">
                <p class="text-xs font-semibold text-brand-700">مرحلهٔ ۳</p>
                <h2 id="step-3" class="mt-2 text-base font-semibold">تحویل دستگاه</h2>
                <p class="mt-3 text-sm leading-7 text-slate-600">
                    پس از تعمیر و کنترل نهایی، دستگاه به همراه شرح انجام‌شده به شما تحویل داده می‌شود.
                </p>
            </section>
        </div>

        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <section class="card p-6 sm:p-8" aria-labelledby="tracking-heading">
                <h2 id="tracking-heading" class="text-lg font-bold">پیگیری وضعیت تعمیر</h2>
                <p class="mt-4 text-sm leading-8 text-slate-600">
                    برای هر درخواست تعمیر یک <strong class="font-semibold text-slate-800">کد پیگیری</strong> صادر
                    می‌شود. با این کد می‌توانید وضعیت دستگاه خود را پیگیری کنید. پیگیری حضوری و تلفنی در حال حاضر
                    امکان‌پذیر است و پیگیری آنلاین از طریق وب‌سایت در نسخه‌های بعدی اضافه خواهد شد.
                </p>
                <ul class="mt-5 space-y-3 text-sm text-slate-600">
                    <li class="flex gap-3"><span class="text-brand-700">—</span> وضعیت‌ها: پذیرش‌شده، در حال عیب‌یابی، در انتظار قطعه، آمادهٔ تحویل</li>
                    <li class="flex gap-3"><span class="text-brand-700">—</span> ارائهٔ کد پیگیری هنگام تحویل دستگاه</li>
                    <li class="flex gap-3"><span class="text-brand-700">—</span> امکان پیگیری آنلاین با کد پیگیری: به‌زودی</li>
                </ul>
            </section>

            <section aria-labelledby="online-form-heading">
                <div class="card overflow-hidden">
                    <div class="border-b border-slate-200 bg-slate-50 px-6 py-4">
                        <h2 id="online-form-heading" class="text-lg font-bold">فرم آنلاین ثبت درخواست</h2>
                        <p class="mt-1 text-xs text-slate-500">پیش‌نمایش — ارسال نمی‌شود</p>
                    </div>

                    <div class="border-b border-amber-200 bg-amber-50 px-6 py-3">
                        <p class="text-xs leading-6 text-amber-800">
                            <strong class="font-semibold">به‌زودی:</strong> این فرم هنوز به سامانهٔ ثبت درخواست متصل
                            نشده است و دکمهٔ ثبت غیرفعال است. تا زمان فعال‌سازی، درخواست شما را حضوری ثبت می‌کنیم.
                        </p>
                    </div>

                    <form class="p-6" aria-disabled="true">
                        <fieldset disabled class="space-y-4">
                            <div>
                                <label for="repair-name" class="label">نام و نام خانوادگی</label>
                                <input id="repair-name" name="name" type="text" class="input" placeholder="نام شما">
                            </div>
                            <div>
                                <label for="repair-phone" class="label">شماره تماس</label>
                                <input id="repair-phone" name="phone" type="tel" class="input"
                                    placeholder="۰۹۱۲۰۰۰۰۰۰۰" dir="ltr">
                            </div>
                            <div>
                                <label for="repair-device" class="label">مدل دستگاه</label>
                                <input id="repair-device" name="device" type="text" class="input"
                                    placeholder="مدل یا برچسب دستگاه">
                            </div>
                            <div>
                                <label for="repair-issue" class="label">شرح مشکل</label>
                                <textarea id="repair-issue" name="issue" rows="4" class="input" placeholder="مشکل دستگاه را شرح دهید"></textarea>
                            </div>
                            <button type="button" class="btn btn-primary w-full" aria-disabled="true" disabled>
                                ثبت درخواست (به‌زودی)
                            </button>
                        </fieldset>
                    </form>
                </div>

                <p class="mt-4 rounded-lg border border-dashed border-slate-300 px-4 py-3 text-xs leading-6 text-slate-600">
                    این بخش صرفاً پیش‌نمایش ظاهری فرم آینده است؛ هیچ داده‌ای دریافت یا ذخیره نمی‌شود.
                </p>
            </section>
        </div>

        <div class="mt-8 flex flex-wrap gap-3">
            <a href="{{ route('contact') }}" class="btn btn-secondary">اطلاعات تماس</a>
            <a href="{{ route('services') }}" class="btn btn-secondary">سایر خدمات</a>
        </div>
    </div>
@endsection
