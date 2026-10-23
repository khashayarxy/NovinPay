@extends('layouts.app')
@section('title', 'درخواست تعمیر')
@section('meta_description', 'ثبت آنلاین درخواست تعمیر کارتخوان و دریافت کد پیگیری نوین‌پی')
@section('content')
<div class="np-container public-section">
    <x-page-heading title="تعمیرات تخصصی کارتخوان" eyebrow="تعمیرات نوین‌پی" icon="tool" lead="مشکل دستگاه را برای ما بنویسید و کد پیگیری دریافت کنید. ثبت این فرم فقط یک درخواست آنلاین است و به معنی دریافت دستگاه نیست.">
        <a href="{{ route('repair.track') }}" class="public-inline-link">قبلاً درخواست داده‌اید؟ پیگیری درخواست <x-home.icon name="arrow-left" /></a>
    </x-page-heading>
    <div class="public-split public-split--form">
        <section class="public-panel" aria-labelledby="repair-form-title">
            <div class="public-panel__heading"><span class="public-icon"><x-home.icon name="tool" /></span><div><h2 id="repair-form-title">ثبت درخواست آنلاین</h2><p>همهٔ موارد ستاره‌دار ضروری هستند.</p></div></div>
            <x-form-errors />
            <form method="post" action="{{ route('repair.store') }}" class="public-form">
                @csrf
                <input type="hidden" name="submission_token" value="{{ $submissionToken }}">
                <div class="public-form__grid">
                    <x-repair-field name="customer_name" label="نام و نام خانوادگی"><input id="customer_name" name="customer_name" class="input" autocomplete="name" required minlength="2" maxlength="100" aria-invalid="{{ $errors->has('customer_name') ? 'true' : 'false' }}" aria-describedby="customer_name-feedback"></x-repair-field>
                    <x-repair-field name="contact_phone" label="شمارهٔ همراه" hint="شمارهٔ ۱۱ رقمی با ۰۹؛ برای پیگیری هم لازم است."><input type="tel" id="contact_phone" name="contact_phone" class="input" dir="ltr" inputmode="tel" autocomplete="tel" required maxlength="40" placeholder="0912 000 0000" aria-invalid="{{ $errors->has('contact_phone') ? 'true' : 'false' }}" aria-describedby="contact_phone-feedback"></x-repair-field>
                    <x-repair-field name="device_brand" label="برند دستگاه"><select id="device_brand" name="device_brand" class="input" required aria-invalid="{{ $errors->has('device_brand') ? 'true' : 'false' }}" aria-describedby="device_brand-feedback"><option value="">انتخاب برند</option>@foreach ($brands as $brand)<option value="{{ $brand->value }}">{{ $brand->value }}</option>@endforeach</select></x-repair-field>
                    <x-repair-field name="device_model" label="مدل دستگاه" hint="مدل را از روی برچسب دستگاه بنویسید."><input id="device_model" name="device_model" class="input" required maxlength="100" aria-invalid="{{ $errors->has('device_model') ? 'true' : 'false' }}" aria-describedby="device_model-feedback"></x-repair-field>
                </div>
                <x-repair-field name="issue" label="شرح مشکل" hint="۱۰ تا ۲۰۰۰ نویسه؛ اطلاعات کارت بانکی، رمز یا اطلاعات حساب را وارد نکنید."><textarea id="issue" name="issue" class="input" rows="5" required minlength="10" maxlength="2000" aria-invalid="{{ $errors->has('issue') ? 'true' : 'false' }}" aria-describedby="issue-feedback"></textarea></x-repair-field>
                <p class="public-hint">نام، شماره تماس، مدل و شرح مشکل برای ثبت این درخواست ذخیره می‌شوند. <a class="public-inline-link" href="{{ route('privacy') }}">اطلاعات حریم خصوصی</a></p>
                <button class="np-button np-button--primary" type="submit">ثبت درخواست تعمیر <x-home.icon name="arrow-left" /></button>
            </form>
        </section>
        <aside class="public-sidebar">
            <div class="public-notice"><x-home.icon name="shield-check" /><h2>درخواست آنلاین، پیش از پذیرش دستگاه</h2><p>در این مرحله فقط اطلاعات درخواست ثبت می‌شود. دریافت فیزیکی، هزینه و زمان تعمیر با ارسال فرم تأیید نمی‌شوند.</p></div>
            <div class="public-panel"><h2>پس از ثبت چه می‌بینید؟</h2><ol class="public-steps"><li><span>۱</span><div><h3>کد پیگیری اختصاصی</h3><p>کد نمایش‌داده‌شده را در جای امن نگه دارید.</p></div></li><li><span>۲</span><div><h3>پیگیری با شمارهٔ همراه</h3><p>فقط با کد کامل و شمارهٔ ثبت‌شده، وضعیت اولیه را ببینید.</p></div></li><li><span>۳</span><div><h3>هماهنگی مراجعه</h3><p>برای هماهنگی مراجعه یا پرسش دربارهٔ دستگاه با ما تماس بگیرید.</p></div></li></ol><x-business-phone class="public-inline-link"><x-home.icon name="phone" /></x-business-phone></div>
        </aside>
    </div>
</div>
@endsection
