@extends('layouts.app')
@section('title', 'درخواست تعمیر')
@section('meta_description', 'ثبت آنلاین درخواست تعمیر کارتخوان و دریافت کد پیگیری نوین‌پی')
@section('content')
@php
    $selectedBrand = old('device_brand', '');
    $selectedBrand = is_string($selectedBrand) ? $selectedBrand : '';
    $selectedModel = old('device_model', '');
    $selectedModel = is_string($selectedModel) ? $selectedModel : '';
    $selectedIssue = old('issue', '');
    $selectedIssue = is_string($selectedIssue) ? $selectedIssue : '';
    $customerName = old('customer_name', auth('customer')->user()?->name ?? '');
    $customerName = is_string($customerName) ? $customerName : '';
    $initialStep = 1;
    if ($errors->hasAny(['device_brand', 'device_model'])) {
        $initialStep = 1;
    } elseif ($errors->has('issue')) {
        $initialStep = 2;
    } elseif ($errors->hasAny(['customer_name', 'contact_phone', 'submission_token'])) {
        $initialStep = 3;
    }
    $issuePresets = [
        ['چاپگر و رول کاغذ', 'مشکل چاپگر، کمرنگی چاپ یا گیر کردن رول کاغذ', 'printer'],
        ['باتری و شارژ', 'مشکل باتری، شارژ نشدن یا خاموش شدن ناگهانی دستگاه', 'battery-charging'],
        ['صفحه‌نمایش و کیپد', 'خرابی صفحه‌نمایش (LCD)، ضربه‌خوردگی یا کلیدهای دستگاه', 'layout-dashboard'],
        ['آنتن‌دهی و سیم‌کارت', 'مشکل آنتن‌دهی، عدم شناسایی سیم‌کارت یا قطع ارتباط شبکه', 'antenna-bars-5'],
        ['خطای تمپر و نرم‌افزار', 'خطای تمپر (Tamper)، قفل شدن یا مشکل نرم‌افزاری دستگاه', 'shield-check'],
        ['سایر موارد', '', 'tool'],
    ];
@endphp
<div class="np-container public-section repair-entry">
    <header class="store-page-intro">
        <h1>ثبت درخواست تعمیر کارتخوان</h1>
        <p>در سه مرحلهٔ کوتاه مشخصات دستگاه و مشکل آن را ثبت کنید تا کد پیگیری دریافت کنید.</p>
    </header>
    <div class="repair-entry-layout">
        <section class="public-panel repair-stepper-panel" aria-label="ثبت درخواست تعمیر">
            <x-form-errors />
            @if(auth('customer')->check())
            <form method="post" action="{{ route('repair.store') }}" class="public-form repair-stepper" data-repair-stepper data-initial-step="{{ $initialStep }}" novalidate>
                @csrf
                <input type="hidden" name="submission_token" value="{{ $submissionToken }}">

                <ol class="repair-progress" aria-label="مراحل ثبت درخواست تعمیر">
                    <li class="repair-progress__item" data-step-indicator="1" aria-current="{{ $initialStep === 1 ? 'step' : 'false' }}">
                        <span class="repair-progress__num">۱</span>
                        <span class="repair-progress__label">مشخصات دستگاه</span>
                    </li>
                    <li class="repair-progress__item" data-step-indicator="2" aria-current="{{ $initialStep === 2 ? 'step' : 'false' }}">
                        <span class="repair-progress__num">۲</span>
                        <span class="repair-progress__label">نوع خرابی</span>
                    </li>
                    <li class="repair-progress__item" data-step-indicator="3" aria-current="{{ $initialStep === 3 ? 'step' : 'false' }}">
                        <span class="repair-progress__num">۳</span>
                        <span class="repair-progress__label">اطلاعات تماس و ثبت</span>
                    </li>
                </ol>

                <p class="repair-step-alert" data-step-alert role="alert" hidden></p>

                {{-- Step 1: Brand & Model --}}
                <div class="repair-step" data-step="1" @if($initialStep !== 1) hidden @endif>
                    <div class="repair-step__head">
                        <h2>مشخصات دستگاه</h2>
                        <p>برند دستگاه کارتخوان را انتخاب کنید و مدل آن را بنویسید.</p>
                    </div>
                    <fieldset class="repair-choice-fieldset">
                        <legend class="label">برند دستگاه <span class="required-mark">*</span></legend>
                        <div class="repair-brand-grid" role="radiogroup" aria-label="برند دستگاه">
                            @foreach ($brands as $brand)
                                <label class="repair-choice-card repair-choice-card--brand">
                                    <input type="radio" name="device_brand" value="{{ $brand->value }}" @checked($selectedBrand === $brand->value) required>
                                    <span class="repair-choice-card__title" @if($brand->value !== 'سایر') dir="ltr" @endif>{{ $brand->value }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('device_brand')<p class="field-error" id="device_brand-feedback">{{ $message }}</p>@enderror
                    </fieldset>
                    <x-repair-field name="device_model" label="مدل دستگاه">
                        <input id="device_model" name="device_model" value="{{ $selectedModel }}" class="input" placeholder="مثلاً S90، ME31 یا P10" required maxlength="100" aria-invalid="{{ $errors->has('device_model') ? 'true' : 'false' }}" aria-describedby="device_model-feedback">
                    </x-repair-field>
                    <div class="repair-step-actions">
                        <button type="button" class="np-button np-button--primary" data-step-next>مرحلهٔ بعد <x-home.icon name="arrow-left" /></button>
                        <a class="np-button np-button--outline" href="{{ route('repair.track') }}">پیگیری درخواست قبلی</a>
                    </div>
                </div>

                {{-- Step 2: Issue Selection --}}
                <div class="repair-step" data-step="2" @if($initialStep !== 2) hidden @endif>
                    <div class="repair-step__head">
                        <h2>نوع خرابی</h2>
                        <p>مشکل اصلی دستگاه را انتخاب کنید یا در کادر زیر توضیح دهید.</p>
                    </div>
                    <div class="repair-issue-grid" role="group" aria-label="مشکلات رایج دستگاه">
                        @foreach ($issuePresets as [$label, $presetText, $icon])
                            <button type="button" class="repair-choice-card repair-choice-card--issue" data-issue-preset="{{ $presetText }}" data-issue-label="{{ $label }}" aria-pressed="false">
                                <span class="repair-choice-card__icon"><x-home.icon :name="$icon" /></span>
                                <span class="repair-choice-card__title">{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>
                    <x-repair-field name="issue" label="شرح مشکل دستگاه">
                        <textarea id="issue" name="issue" class="input" rows="4" placeholder="مشکل دستگاه را کوتاه توضیح دهید (حداقل ۱۰ کاراکتر)؛ مثلاً روشن نمی‌شود یا کارت را نمی‌خواند." required minlength="10" maxlength="2000" aria-invalid="{{ $errors->has('issue') ? 'true' : 'false' }}" aria-describedby="issue-feedback">{{ $selectedIssue }}</textarea>
                    </x-repair-field>
                    <div class="repair-step-actions">
                        <button type="button" class="np-button np-button--primary" data-step-next>مرحلهٔ بعد <x-home.icon name="arrow-left" /></button>
                        <button type="button" class="np-button np-button--outline" data-step-prev>مرحلهٔ قبل</button>
                    </div>
                </div>

                {{-- Step 3: Contact & Submit --}}
                <div class="repair-step" data-step="3" @if($initialStep !== 3) hidden @endif>
                    <div class="repair-step__head">
                        <h2>اطلاعات تماس و ثبت نهایی</h2>
                        <p>خلاصهٔ درخواست را بررسی کنید و ثبت را نهایی نمایید.</p>
                    </div>
                    <div class="repair-summary-box" data-repair-summary>
                        <div class="repair-summary-box__item">
                            <span>دستگاه</span>
                            <strong data-summary-device>—</strong>
                        </div>
                        <div class="repair-summary-box__item">
                            <span>شرح مشکل</span>
                            <strong data-summary-issue>—</strong>
                        </div>
                    </div>
                    <div class="public-form__grid">
                        <x-repair-field name="customer_name" label="نام و نام خانوادگی">
                            <input id="customer_name" name="customer_name" value="{{ $customerName }}" class="input" autocomplete="name" required minlength="2" maxlength="100" aria-invalid="{{ $errors->has('customer_name') ? 'true' : 'false' }}" aria-describedby="customer_name-feedback">
                        </x-repair-field>
                        <x-repair-field name="contact_phone" label="شماره موبایل" :required="false">
                            <input readonly value="{{ \App\Support\FaNumber::digits(auth('customer')->user()?->phone ?? '') }}" type="tel" id="contact_phone" name="contact_phone" class="input" dir="ltr" inputmode="tel" autocomplete="tel" maxlength="40" placeholder="۰۹۱۲۳۴۵۶۷۸۹" aria-invalid="{{ $errors->has('contact_phone') ? 'true' : 'false' }}" aria-describedby="contact_phone-feedback">
                        </x-repair-field>
                    </div>
                    <div class="repair-step-actions">
                        <button class="np-button np-button--primary" type="submit">ثبت درخواست تعمیر <x-home.icon name="arrow-left" /></button>
                        <button type="button" class="np-button np-button--outline" data-step-prev>مرحلهٔ قبل</button>
                    </div>
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
