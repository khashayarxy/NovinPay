@extends('layouts.app')
@section('title', 'پیگیری درخواست')
@section('content')
<div class="np-container public-section repair-track-page">
    <div class="repair-track-shell">
        <header class="store-page-intro">
            <h1>پیگیری درخواست تعمیر</h1>
            <p>کد پیگیری هشت‌رقمی و شماره موبایل ثبت‌شده را وارد کنید.</p>
        </header>
        <section class="public-panel" aria-labelledby="tracking-title">
            <h2 id="tracking-title" class="sr-only">فرم بررسی وضعیت درخواست</h2>
            <x-form-errors />
            @if ($matched === false)
                <div class="public-notice public-notice--error" role="status"><h2>درخواستی با این کد و شماره پیدا نشد.</h2><p>کد و شماره را بررسی کنید و دوباره تلاش کنید.</p></div>
            @elseif ($matched === true)
                <div class="public-notice public-notice--success" role="status">
                    <h2>{{ ($physicalMatch ?? false) ? 'وضعیت فعلی تعمیر' : \App\Enums\RepairRequestStatus::OnlineSubmitted->label() }}</h2>
                    @if ($physicalMatch ?? false)<p>{{ $physicalStatus }}</p>@endif
                    <p>برای هماهنگی مراجعه یا دریافت جزئیات با نوین‌پی تماس بگیرید.</p>
                </div>
            @endif
            <form method="post" action="{{ route('repair.track.store') }}" class="public-form">
                @csrf
                <x-repair-field name="tracking_reference" label="کد پیگیری هشت‌رقمی"><input id="tracking_reference" name="tracking_reference" value="{{ old('tracking_reference', $trackingCode ?? '') }}" class="input public-code-input" dir="ltr" inputmode="numeric" autocomplete="off" spellcheck="false" required minlength="8" maxlength="8" placeholder="۱۲۳۴۵۶۷۸" aria-invalid="{{ $errors->has('tracking_reference') ? 'true' : 'false' }}" aria-describedby="tracking_reference-feedback"></x-repair-field>
                <x-repair-field name="contact_phone" label="شماره موبایل ثبت‌شده"><input type="tel" id="contact_phone" name="contact_phone" class="input" dir="ltr" inputmode="tel" autocomplete="off" required maxlength="40" placeholder="۰۹۱۲۳۴۵۶۷۸۹" aria-invalid="{{ $errors->has('contact_phone') ? 'true' : 'false' }}" aria-describedby="contact_phone-feedback"></x-repair-field>
                <div class="repair-entry-actions">
                    <button type="submit" class="np-button np-button--primary">بررسی وضعیت <x-home.icon name="arrow-left" /></button>
                    <a class="np-button np-button--outline" href="{{ route('repair') }}">ثبت درخواست تعمیر <x-home.icon name="arrow-left" /></a>
                </div>
            </form>
            <p class="repair-privacy-note"><x-home.icon name="shield-check" /><span>اطلاعات شخصی و جزئیات دستگاه در نتیجهٔ پیگیری نمایش داده نمی‌شود.</span></p>
        </section>
    </div>
</div>
@endsection
