@extends('layouts.app')
@section('title', 'درخواست تعمیر')
@section('meta_description', 'ثبت آنلاین درخواست تعمیر کارتخوان و دریافت کد پیگیری نوین‌پی')
@section('content')
<div class="np-container public-section repair-entry">
    <header class="store-page-intro">
        <h1>ثبت درخواست تعمیر کارتخوان</h1>
        <p>مشخصات دستگاه و شرح مشکل را ثبت کنید تا کد پیگیری دریافت کنید.</p>
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
                    <x-repair-field name="contact_phone" label="شماره موبایل" :required="false"><input readonly value="{{ \App\Support\FaNumber::digits(auth('customer')->user()?->phone ?? '') }}" type="tel" id="contact_phone" name="contact_phone" class="input" dir="ltr" inputmode="tel" autocomplete="tel" maxlength="40" placeholder="۰۹۱۲۳۴۵۶۷۸۹" aria-invalid="{{ $errors->has('contact_phone') ? 'true' : 'false' }}" aria-describedby="contact_phone-feedback"></x-repair-field>
                    <x-repair-field name="device_brand" label="برند دستگاه"><select id="device_brand" name="device_brand" class="input" required aria-invalid="{{ $errors->has('device_brand') ? 'true' : 'false' }}" aria-describedby="device_brand-feedback"><option value="" disabled hidden @selected(! old('device_brand'))>انتخاب برند</option>@foreach ($brands as $brand)<option value="{{ $brand->value }}" @selected(old('device_brand') === $brand->value)>{{ $brand->value }}</option>@endforeach</select></x-repair-field>
                    <x-repair-field name="device_model" label="مدل دستگاه"><input id="device_model" name="device_model" value="{{ old('device_model') }}" class="input" placeholder="مثلاً S90 یا ME31" required maxlength="100" aria-invalid="{{ $errors->has('device_model') ? 'true' : 'false' }}" aria-describedby="device_model-feedback"></x-repair-field>
                </div>
                <x-repair-field name="issue" label="شرح مشکل دستگاه"><textarea id="issue" name="issue" class="input" rows="4" placeholder="مشکل دستگاه را کوتاه توضیح دهید؛ مثلاً روشن نمی‌شود یا کارت را نمی‌خواند." required minlength="10" maxlength="2000" aria-invalid="{{ $errors->has('issue') ? 'true' : 'false' }}" aria-describedby="issue-feedback">{{ old('issue') }}</textarea></x-repair-field>
                <div class="repair-entry-actions">
                    <button class="np-button np-button--primary" type="submit">ثبت درخواست تعمیر <x-home.icon name="arrow-left" /></button>
                    <a class="np-button np-button--outline" href="{{ route('repair.track') }}">پیگیری درخواست قبلی <x-storefront.icon name="arrow" /></a>
                </div>
            </form>
            @else
                <p>برای ثبت درخواست، شماره موبایل خود را تأیید کنید.</p>
                <div class="repair-entry-actions">
                    <a class="np-button np-button--primary" href="{{ route('repair.start') }}">ورود و ثبت درخواست <x-storefront.icon name="arrow" /></a>
                    <a class="np-button np-button--outline" href="{{ route('repair.track') }}">پیگیری درخواست قبلی <x-storefront.icon name="arrow" /></a>
                </div>
            @endif
        </section>
        <aside class="repair-entry-notes" aria-label="روند ثبت و بررسی تعمیر">
            <div class="repair-guide-card">
                <div class="repair-guide-card__title">
                    <x-storefront.icon name="shield" />
                    <h2>بعد از ثبت درخواست چه می‌شود؟</h2>
                </div>
                <ul>
                    <li>ثبت آنلاین یعنی دستگاه هنوز به ما تحویل داده نشده است؛ هزینه و زمان دقیق تعمیر پس از بررسی فنی اعلام می‌شود.</li>
                    <li>پس از ثبت، کد پیگیری هشت‌رقمی دریافت می‌کنید که همراه با شماره موبایل برای پیگیری وضعیت کافی است.</li>
                </ul>
            </div>
        </aside>
    </div>
</div>
@endsection
