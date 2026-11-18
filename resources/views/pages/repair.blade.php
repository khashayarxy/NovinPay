@extends('layouts.app')
@section('title', 'درخواست تعمیر')
@section('meta_description', 'ثبت آنلاین درخواست تعمیر کارتخوان و دریافت کد پیگیری نوین‌پی')
@section('content')
<div class="np-container public-section repair-entry">
    <header class="store-page-intro">
        <h1>ثبت درخواست تعمیر کارتخوان</h1>
        <p>مشکل دستگاه را ثبت کنید و کد پیگیری بگیرید.</p>
    </header>
    <div class="repair-entry-layout">
        <section class="public-panel" aria-label="ثبت درخواست تعمیر">
            <x-form-errors />
            @if(auth('customer')->check())
            <form method="post" action="{{ route('repair.store') }}" class="public-form">
                @csrf
                <input type="hidden" name="submission_token" value="{{ $submissionToken }}">
                <div class="public-form__grid">
                    <x-repair-field name="customer_name" label="نام و نام خانوادگی"><input id="customer_name" name="customer_name" value="{{ auth('customer')->user()?->name }}" class="input" autocomplete="name" required minlength="2" maxlength="100" aria-invalid="{{ $errors->has('customer_name') ? 'true' : 'false' }}" aria-describedby="customer_name-feedback"></x-repair-field>
                    <x-repair-field name="contact_phone" label="شماره موبایل" :required="false" hint="برای پیگیری به همین شماره نیاز دارید."><input readonly value="{{ \App\Support\FaNumber::digits(auth('customer')->user()?->phone ?? '') }}" type="tel" id="contact_phone" name="contact_phone" class="input" dir="ltr" inputmode="tel" autocomplete="tel" maxlength="40" placeholder="۰۹۱۲۳۴۵۶۷۸۹" aria-invalid="{{ $errors->has('contact_phone') ? 'true' : 'false' }}" aria-describedby="contact_phone-feedback"></x-repair-field>
                    <x-repair-field name="device_brand" label="برند دستگاه"><select id="device_brand" name="device_brand" class="input" required aria-invalid="{{ $errors->has('device_brand') ? 'true' : 'false' }}" aria-describedby="device_brand-feedback"><option value="">انتخاب برند</option>@foreach ($brands as $brand)<option value="{{ $brand->value }}">{{ $brand->value }}</option>@endforeach</select></x-repair-field>
                    <x-repair-field name="device_model" label="مدل دستگاه" hint="مدل را از روی برچسب دستگاه بنویسید."><input id="device_model" name="device_model" class="input" required maxlength="100" aria-invalid="{{ $errors->has('device_model') ? 'true' : 'false' }}" aria-describedby="device_model-feedback"></x-repair-field>
                </div>
                <x-repair-field name="issue" label="شرح مشکل دستگاه" hint="۱۰ تا ۲۰۰۰ کاراکتر؛ اطلاعات کارت بانکی، رمز یا اطلاعات حساب را وارد نکنید."><textarea id="issue" name="issue" class="input" rows="5" placeholder="مشکل دستگاه را توضیح دهید؛ مثلاً روشن نمی‌شود یا کارت را نمی‌خواند." required minlength="10" maxlength="2000" aria-invalid="{{ $errors->has('issue') ? 'true' : 'false' }}" aria-describedby="issue-feedback"></textarea></x-repair-field>
                <p class="public-hint">نام، شماره موبایل، مدل دستگاه و شرح مشکل برای ثبت درخواست ذخیره می‌شوند. <a class="public-inline-link" href="{{ route('privacy') }}">اطلاعات حریم خصوصی</a></p>
                <button class="np-button np-button--primary" type="submit">ثبت درخواست تعمیر <x-home.icon name="arrow-left" /></button>
            </form>
            @else
                <p>برای ثبت درخواست، شماره موبایل خود را تأیید کنید.</p>
                <a class="np-button np-button--primary" href="{{ route('repair.start') }}">ورود و ثبت درخواست <x-storefront.icon name="arrow" /></a>
            @endif
            <a class="public-inline-link repair-tracking-link" href="{{ route('repair.track') }}">پیگیری درخواست قبلی <x-storefront.icon name="arrow" /></a>
        </section>
        <aside class="repair-entry-notes">
            <p class="repair-before-delivery"><x-storefront.icon name="shield" /><span>ثبت درخواست یعنی دستگاه هنوز به ما تحویل داده نشده است. هزینه و زمان تعمیر پس از بررسی مشخص می‌شود.</span></p>
            <details>
                <summary>بعد از ثبت درخواست چه می‌شود؟</summary>
                <p>کد پیگیری را نگه دارید. با این کد و شمارهٔ موبایل ثبت‌شده می‌توانید وضعیت درخواست را ببینید.</p>
            </details>
            <section class="repair-contact" aria-label="تماس با تعمیرگاه">
                <h2>تماس با تعمیرگاه</h2>
                <x-business-phone both />
            </section>
        </aside>
    </div>
</div>
@endsection
