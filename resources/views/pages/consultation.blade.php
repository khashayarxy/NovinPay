@extends('layouts.app')
@section('title', 'درخواست مشاوره')
@section('meta_description', 'ثبت پرسش دربارهٔ خرید و خدمات کارتخوان در نوین‌پی')
@section('content')
<div class="repair-entry-bg">
    <div class="np-container public-section repair-entry consultation-entry">
        <div class="repair-entry-shell">
            <div class="repair-split-card consultation-split-card">
                <section class="repair-split-form consultation-split-form" aria-labelledby="consultation-form-title">
                    <header class="consultation-card-head">
                        <span class="consultation-card-head__badge">مشاورهٔ تخصصی نوین‌پی</span>
                        <h1 id="consultation-form-title">درخواست مشاوره خرید و خدمات</h1>
                        <p>پرسش خود را دربارهٔ انتخاب کارتخوان یا خدمات فنی بنویسید تا کارشناسان ما با شما تماس بگیرند.</p>
                    </header>

                    <x-form-errors />

                    <form class="public-form" method="post" action="{{ route('consultation.store') }}" data-public-submit-loading>
                        @csrf
                        <input type="hidden" name="submission_token" value="{{ $token }}" />

                        <div class="public-form__grid">
                            <x-operation-field name="customer_name" label="نام و نام خانوادگی" autocomplete="name" :maxlength="100" :retain="true" :floating="true" />
                            <x-operation-field name="contact_phone" label="شماره موبایل" type="tel" inputmode="numeric" autocomplete="tel" :maxlength="11" :retain="true" :floating="true" />
                        </div>

                        <div class="public-field">
                            <div class="np-float-field" data-float-field>
                                <select class="input" id="consultation-topic" name="topic" required>
                                    <option value="">انتخاب موضوع مشاوره…</option>
                                    @foreach (\App\Models\Consultation::TOPICS as $value => $label)
                                        <option value="{{ $value }}" @selected(old('topic') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <label class="label" for="consultation-topic">موضوع مشاوره</label>
                            </div>
                        </div>

                        <x-operation-field name="device_info" label="مدل یا اطلاعات مرتبط دستگاه (اختیاری)" :required="false" :maxlength="200" :retain="true" :floating="true" />
                        <x-operation-field name="question" label="پرسش شما (حداقل ۱۰ کاراکتر)" kind="textarea" :retain="true" :floating="true" />

                        <div class="consultation-form-actions">
                            <button class="np-button np-button--primary" type="submit" data-loading-label="در حال ارسال...">ثبت درخواست مشاوره</button>
                        </div>
                    </form>
                </section>

                <aside class="repair-split-trust consultation-split-trust" aria-label="مزایای مشاوره تخصصی نوین‌پی">
                    <div class="repair-split-trust__glow" aria-hidden="true"></div>
                    <div class="repair-split-trust__top">
                        <span class="repair-trust-card__eyebrow">همراهی پیش از تصمیم</span>
                        <h2>راهنمایی دقیق متناسب با کسب‌وکار شما</h2>
                        <p class="repair-split-trust__lead">پیش از خرید دستگاه یا ثبت تعمیر، شرایط واقعی مدل‌ها، آنتن‌دهی و هزینه‌ها را شفاف بررسی کنید.</p>

                        <ul class="repair-trust-list">
                            <li>
                                <span class="repair-trust-list__icon" aria-hidden="true"><x-home.icon name="tag" /></span>
                                <div>
                                    <strong>پیشنهاد کارتخوان متناسب با صنف</strong>
                                    <p>مقایسهٔ عملی سرعت تراکنش، دوام باتری و پوشش شبکه برای محل فعالیت شما.</p>
                                </div>
                            </li>
                            <li>
                                <span class="repair-trust-list__icon" aria-hidden="true"><x-home.icon name="tool" /></span>
                                <div>
                                    <strong>بررسی شفاف تعمیر یا ارتقای 4G</strong>
                                    <p>ارزیابی به‌صرفه بودن تعمیر تخصصی یا ارتقای ماژول پیش از هرگونه هزینه.</p>
                                </div>
                            </li>
                            <li>
                                <span class="repair-trust-list__icon" aria-hidden="true"><x-home.icon name="shield-check" /></span>
                                <div>
                                    <strong>پاسخ‌گویی سریع و حفظ محرمانگی</strong>
                                    <p>اطلاعات تماس شما صرفاً برای پاسخ‌گویی به همین درخواست استفاده می‌شود.</p>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div class="repair-split-trust__footer">
                        <span>تماس مستقیم با مشاوران نوین‌پی:</span>
                        <a href="{{ config('business.phone_uri') }}" dir="ltr">{{ \App\Support\FaNumber::digits(config('business.phone')) }}</a>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</div>
@endsection
