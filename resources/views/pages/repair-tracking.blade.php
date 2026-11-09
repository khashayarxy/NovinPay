@extends('layouts.app')
@section('title', 'پیگیری درخواست')
@section('content')
<div class="np-container public-section">
    <x-page-heading title="پیگیری درخواست تعمیر" eyebrow="پیگیری خصوصی" icon="shield-check" lead="کد پیگیری هشت‌رقمی و همان شمارهٔ همراه ثبت‌شده را وارد کنید تا مرحلهٔ فعلی تعمیر را ببینید." />
    <div class="public-split public-split--form">
        <section class="public-panel" aria-labelledby="tracking-title">
            <div class="public-panel__heading"><span class="public-icon"><x-home.icon name="search" /></span><div><h2 id="tracking-title">بررسی وضعیت درخواست</h2><p>هر دو مورد برای پیگیری ضروری هستند.</p></div></div>
            <x-form-errors />
            @if ($matched === false)
                <div class="public-notice public-notice--error" role="status"><h2>اطلاعات واردشده قابل تأیید نیست.</h2><p>کد و شماره را بررسی کنید و دوباره تلاش کنید. برای راهنمایی با نوین‌پی تماس بگیرید.</p></div>
            @elseif ($matched === true)
                <div class="public-notice public-notice--success" role="status">
                    <h2>{{ ($physicalMatch ?? false) ? 'وضعیت فعلی تعمیر' : \App\Enums\RepairRequestStatus::OnlineSubmitted->label() }}</h2>
                    @if ($physicalMatch ?? false)<p>{{ $physicalStatus }}</p>@endif
                    <p>برای هماهنگی مراجعه یا دریافت جزئیات با نوین‌پی تماس بگیرید.</p>
                </div>
            @endif
            <form method="post" action="{{ route('repair.track.store') }}" class="public-form">
                @csrf
                <x-repair-field name="tracking_reference" label="کد پیگیری" hint="هر هشت رقم کد را با اعداد فارسی یا انگلیسی وارد کنید."><input id="tracking_reference" name="tracking_reference" class="input public-code-input" dir="ltr" inputmode="numeric" autocomplete="off" spellcheck="false" required minlength="8" maxlength="8" placeholder="۱۲۳۴۵۶۷۸" aria-invalid="{{ $errors->has('tracking_reference') ? 'true' : 'false' }}" aria-describedby="tracking_reference-feedback"></x-repair-field>
                <x-repair-field name="contact_phone" label="شمارهٔ همراه ثبت‌شده"><input type="tel" id="contact_phone" name="contact_phone" class="input" dir="ltr" inputmode="tel" autocomplete="off" required maxlength="40" placeholder="0912 000 0000" aria-invalid="{{ $errors->has('contact_phone') ? 'true' : 'false' }}" aria-describedby="contact_phone-feedback"></x-repair-field>
                <button type="submit" class="np-button np-button--primary">بررسی وضعیت <x-home.icon name="arrow-left" /></button>
            </form>
        </section>
        <aside class="public-sidebar"><div class="public-notice"><x-home.icon name="shield-check" /><h2>اطلاعات شما در نتیجه نمایش داده نمی‌شود</h2><p>نام، شماره تماس، مدل دستگاه و شرح مشکل در این صفحه بازگو نمی‌شوند. کد پیگیری و شمارهٔ خود را فقط در اختیار فرد مورد اعتماد قرار دهید.</p></div><div class="public-panel"><h2>برای ثبت درخواست تازه</h2><p>اگر هنوز کد پیگیری ندارید، ابتدا فرم درخواست آنلاین را تکمیل کنید.</p><a class="public-inline-link" href="{{ route('repair') }}">ثبت درخواست تعمیر <x-home.icon name="arrow-left" /></a></div></aside>
    </div>
</div>
@endsection
