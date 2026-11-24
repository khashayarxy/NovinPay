@extends('layouts.app')
@section('title', 'درخواست تعمیر')
@section('meta_description', 'ثبت آنلاین درخواست تعمیر کارتخوان، انتخاب روش تحویل و دریافت کد پیگیری نوین‌پی')
@section('content')
@php
    $isGuest = ! auth('customer')->check();
    $selectedBrand = old('device_brand', '');
    $selectedBrand = is_string($selectedBrand) ? $selectedBrand : '';
    $selectedModel = old('device_model', '');
    $selectedModel = is_string($selectedModel) ? $selectedModel : '';
    $selectedIssue = old('issue', '');
    $selectedIssue = is_string($selectedIssue) ? $selectedIssue : '';
    $selectedAccessories = old('accessories', []);
    $selectedAccessories = is_array($selectedAccessories) ? $selectedAccessories : [];
    $selectedProvince = old('province', '');
    $selectedProvince = is_string($selectedProvince) ? $selectedProvince : '';
    $selectedCity = old('city', '');
    $selectedCity = is_string($selectedCity) ? $selectedCity : '';
    $selectedHandover = old('handover_method', '');
    $selectedHandover = is_string($selectedHandover) ? $selectedHandover : '';
    $pickupAddress = old('pickup_address', '');
    $pickupAddress = is_string($pickupAddress) ? $pickupAddress : '';
    $pickupLat = old('pickup_latitude', '');
    $pickupLng = old('pickup_longitude', '');
    $responsivePhone = old('responsive_phone', '');
    $responsivePhone = is_string($responsivePhone) ? $responsivePhone : '';
    $customerName = old('customer_name', auth('customer')->user()?->name ?? '');
    $customerName = is_string($customerName) ? $customerName : '';
    $initialStep = 1;
    if ($errors->hasAny(['device_brand', 'device_model', 'device_serial', 'accessories', 'accessories.*'])) {
        $initialStep = 1;
    } elseif ($errors->hasAny(['issue'])) {
        $initialStep = 2;
    } elseif ($errors->hasAny(['customer_name', 'contact_phone', 'province', 'city', 'handover_method', 'pickup_address', 'responsive_phone', 'submission_token'])) {
        $initialStep = 3;
    }
    $issuePresets = [
        ['چاپگر و رول کاغذ', 'printer', false],
        ['باتری و شارژ', 'battery-charging', false],
        ['صفحه‌نمایش و کیپد', 'layout-dashboard', false],
        ['آنتن‌دهی و سیم‌کارت', 'antenna-bars-5', false],
        ['خطای تمپر و نرم‌افزار', 'shield-check', false],
        ['ارتقای ماژول 2G به 4G', 'tool', true],
        ['سایر موارد', 'tool', false],
    ];
    $authRemaining = max(0, (int) ($authResendAt ?? 0) - now()->timestamp);
@endphp
<div class="np-container public-section repair-entry">
    <div class="repair-entry-shell">
        <header class="store-page-intro repair-hero-intro">
            <div class="repair-hero-intro__row">
                <div>
                    <h1>ثبت درخواست تعمیر کارتخوان</h1>
                    <p>در سه مرحلهٔ ساده درخواست خود را ثبت کنید و کد پیگیری بگیرید.</p>
                </div>
                <a class="public-inline-link" href="{{ route('repair.track') }}">پیگیری درخواست قبلی <x-home.icon name="arrow-left" /></a>
            </div>
        </header>

        <div class="repair-entry-layout repair-entry-layout--single">
        <section class="public-panel repair-stepper-panel" aria-label="ثبت درخواست تعمیر">
            <x-form-errors />

            <form method="post"
                  action="{{ route('repair.store') }}"
                  enctype="multipart/form-data"
                  class="public-form repair-stepper"
                  data-repair-stepper
                  data-initial-step="{{ $initialStep }}"
                  data-is-guest="{{ $isGuest ? '1' : '0' }}"
                  data-reverse-geocode-url="{{ route('cart.checkout.reverse-geocode') }}"
                  data-draft-key="np_repair_draft_v2"
                  novalidate>
                @csrf
                <input type="hidden" name="submission_token" value="{{ $submissionToken }}">

                <ol class="repair-progress" aria-label="مراحل ثبت درخواست تعمیر">
                    <li class="repair-progress__item" data-step-indicator="1" aria-current="{{ $initialStep === 1 ? 'step' : 'false' }}">
                        <span class="repair-progress__num">۱</span>
                        <span class="repair-progress__label">مشخصات دستگاه</span>
                    </li>
                    <li class="repair-progress__item" data-step-indicator="2" aria-current="{{ $initialStep === 2 ? 'step' : 'false' }}">
                        <span class="repair-progress__num">۲</span>
                        <span class="repair-progress__label">شرح مشکل</span>
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
                                <label @class(['repair-choice-card repair-choice-card--brand', 'repair-choice-card--brand-full' => $brand->value === 'سایر'])>
                                    <input type="radio" name="device_brand" value="{{ $brand->value }}" @checked($selectedBrand === $brand->value) required>
                                    <span class="repair-choice-card__title" @if($brand->value !== 'سایر') dir="ltr" @endif>{{ $brand->value }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p class="public-field-error" data-field-error="device_brand" id="device_brand-feedback" role="alert" @unless($errors->has('device_brand')) hidden @endunless>@error('device_brand'){{ $message }}@enderror</p>
                    </fieldset>
                    <div class="public-form__grid repair-paired-fields">
                        <x-repair-field name="device_model" label="مدل دستگاه">
                            <input id="device_model" name="device_model" value="{{ $selectedModel }}" class="input np-latin-mixed" dir="rtl" placeholder="مثلاً ME31" required maxlength="100" aria-invalid="{{ $errors->has('device_model') ? 'true' : 'false' }}" aria-describedby="device_model-feedback">
                        </x-repair-field>
                        <x-repair-field name="device_serial" label="شماره سریال دستگاه (اختیاری)" :required="false">
                            <input id="device_serial" name="device_serial" value="{{ old('device_serial', '') }}" class="input np-latin-mixed" dir="rtl" maxlength="100" placeholder="کد S/N درج‌شده روی برچسب پشت دستگاه" aria-invalid="{{ $errors->has('device_serial') ? 'true' : 'false' }}" aria-describedby="device_serial-feedback">
                        </x-repair-field>
                    </div>
                    <fieldset class="repair-choice-fieldset">
                        <legend class="label">لوازم همراه دستگاه</legend>
                        <div class="repair-accessory-grid" role="group" aria-label="لوازم همراه دستگاه">
                            @foreach (\App\Models\RepairRequest::ACCESSORIES as $key => $label)
                                <label class="repair-choice-card repair-choice-card--accessory">
                                    <input type="checkbox" name="accessories[]" value="{{ $key }}" @checked(in_array($key, $selectedAccessories, true))>
                                    <span class="repair-choice-card__check" aria-hidden="true">
                                        <svg class="repair-choice-card__tick" viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.2 8.4 6.5 11.7 12.8 4.6"/></svg>
                                    </span>
                                    <span class="repair-choice-card__title">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div class="repair-step-actions">
                        <button type="button" class="np-button np-button--primary" data-step-next>مرحلهٔ بعد <x-home.icon name="arrow-left" /></button>
                    </div>
                </div>

                {{-- Step 2: Issue Selection --}}
                <div class="repair-step" data-step="2" @if($initialStep !== 2) hidden @endif>
                    <div class="repair-step__head">
                        <h2>شرح مشکل</h2>
                        <p>موضوع اصلی مشکل را انتخاب کنید و توضیح کوتاهی دربارهٔ ایراد دستگاه بنویسید.</p>
                    </div>
                    <div class="repair-issue-grid" role="group" aria-label="مشکلات رایج دستگاه">
                        @foreach ($issuePresets as [$label, $icon, $isUpgrade])
                            <button type="button"
                                    @class(['repair-choice-card repair-choice-card--issue', 'repair-choice-card--issue-full' => $label === 'سایر موارد'])
                                    data-issue-preset
                                    data-issue-label="{{ $label }}"
                                    @if($isUpgrade) data-issue-upgrade="1" @endif
                                    aria-pressed="false">
                                <span class="repair-choice-card__icon"><x-home.icon :name="$icon" /></span>
                                <span class="repair-choice-card__title">{{ \App\Support\LatinText::html($label) }}</span>
                            </button>
                        @endforeach
                    </div>
                    <div class="repair-upgrade-estimate" data-upgrade-estimate role="status" aria-live="polite" hidden>
                        <p>برآورد اولیه ارتقای ماژول <bdi class="np-latin" dir="ltr">2G</bdi> به <bdi class="np-latin" dir="ltr">4G</bdi>: حدود ۴٬۰۰۰٬۰۰۰ تومان — هزینه و امکان‌پذیری نهایی پس از بررسی فنی اعلام می‌شود.</p>
                    </div>
                    <x-repair-field name="issue" label="شرح مشکل دستگاه">
                        <textarea id="issue" name="issue" class="input" rows="4" placeholder="نشانهٔ خرابی، پیام خطای نمایشگر و زمان بروز مشکل را بنویسید (حداقل ۱۰ کاراکتر)" required minlength="10" maxlength="2000" aria-invalid="{{ $errors->has('issue') ? 'true' : 'false' }}" aria-describedby="issue-feedback">{{ $selectedIssue }}</textarea>
                    </x-repair-field>
                    <div class="repair-step-actions">
                        <button type="button" class="np-button np-button--primary" data-step-next>مرحلهٔ بعد <x-home.icon name="arrow-left" /></button>
                        <button type="button" class="np-button np-button--outline" data-step-prev>مرحلهٔ قبل</button>
                    </div>
                </div>

                {{-- Step 3: Location, Handover, Inline OTP (for guests), Contact & Submit --}}
                <div class="repair-step" data-step="3" @if($initialStep !== 3) hidden @endif>
                    <div class="repair-step__head">
                        <h2>موقعیت، روش تحویل و اطلاعات تماس</h2>
                        <p>استان و شهر خود را انتخاب کنید تا روش‌های در دسترس تحویل نمایش داده شوند.</p>
                    </div>

                    <div class="repair-location-block" data-repair-location>
                        <div class="public-form__grid">
                            <div class="public-field">
                                <label for="repair_province">استان <span aria-hidden="true">*</span></label>
                                <select id="repair_province" name="province" class="input" data-repair-province aria-invalid="{{ $errors->has('province') ? 'true' : 'false' }}">
                                    <option value="">انتخاب استان...</option>
                                    @foreach (array_keys($iranLocations) as $provinceName)
                                        <option value="{{ $provinceName }}" @selected($selectedProvince === $provinceName)>{{ $provinceName }}</option>
                                    @endforeach
                                </select>
                                <p class="public-field-error" data-field-error="province" role="alert" @unless($errors->has('province')) hidden @endunless>@error('province'){{ $message }}@enderror</p>
                            </div>
                            <div class="public-field">
                                <label for="repair_city">شهر <span aria-hidden="true">*</span></label>
                                <select id="repair_city" name="city" class="input" data-repair-city data-selected-city="{{ $selectedCity }}" aria-invalid="{{ $errors->has('city') ? 'true' : 'false' }}">
                                    <option value="">ابتدا استان را انتخاب کنید...</option>
                                </select>
                                <p class="public-field-error" data-field-error="city" role="alert" @unless($errors->has('city')) hidden @endunless>@error('city'){{ $message }}@enderror</p>
                            </div>
                        </div>

                        <div class="repair-handover-section" data-handover-section>
                            <p class="label">نحوهٔ تحویل دستگاه به نوین‌پی</p>
                            <p class="repair-handover-hint" data-handover-hint>پس از انتخاب استان و شهر، روش‌های در دسترس برای منطقهٔ شما فعال می‌شود.</p>
                            <div class="repair-handover-grid" role="radiogroup" aria-label="نحوهٔ تحویل دستگاه">
                                <label class="repair-choice-card repair-choice-card--handover" data-handover-option="self_ship">
                                    <input type="radio" name="handover_method" value="self_ship" @checked($selectedHandover === 'self_ship')>
                                    <span class="repair-choice-card__title">خودم ارسال میکنم</span>
                                    <span class="repair-choice-card__sub">ارسال از سراسر کشور با پست یا تیپاکس</span>
                                </label>
                                <label class="repair-choice-card repair-choice-card--handover" data-handover-option="in_person">
                                    <input type="radio" name="handover_method" value="in_person" @checked($selectedHandover === 'in_person')>
                                    <span class="repair-choice-card__title">خودم حضوری میارم</span>
                                    <span class="repair-choice-card__sub">تحویل مستقیم در فروشگاه نوین‌پی شهریار</span>
                                </label>
                                <label class="repair-choice-card repair-choice-card--handover" data-handover-option="doorstep_pickup" hidden>
                                    <input type="radio" name="handover_method" value="doorstep_pickup" @checked($selectedHandover === 'doorstep_pickup') disabled>
                                    <span class="repair-choice-card__title">به پیک شما تحویل میدم</span>
                                    <span class="repair-choice-card__sub">دریافت دستگاه از محل شما توسط پیک نوین‌پی</span>
                                </label>
                            </div>
                            <p class="public-field-error" data-field-error="handover_method" role="alert" @unless($errors->has('handover_method')) hidden @endunless>@error('handover_method'){{ $message }}@enderror</p>
                        </div>

                        {{-- Conditional Details: Self-Ship --}}
                        <div class="repair-handover-panel repair-handover-panel--office" data-handover-panel="self_ship" hidden>
                            <h3>نشانی و راهنمای ارسال پستی / تیپاکس</h3>
                            <dl class="repair-shipping-details">
                                <div>
                                    <dt>آدرس گیرنده:</dt>
                                    <dd>استان تهران، شهریار، بلوار رسول اکرم، خیابان مفتح، پاساژ الماس شهر، طبقه زیر همکف، واحد ۸۷</dd>
                                </div>
                                <div>
                                    <dt>شماره موبایل:</dt>
                                    <dd><a href="tel:09362896373" dir="ltr">۰۹۳۶۲۸۹۶۳۷۳</a></dd>
                                </div>
                                <div>
                                    <dt>کد پستی:</dt>
                                    <dd dir="ltr">۳۳۵۱۶۱۱۸۲۸</dd>
                                </div>
                            </dl>
                            <div class="repair-shipping-copy-row">
                                <button type="button"
                                        class="btn btn-secondary btn-sm repair-shipping-copy-btn"
                                        data-copy-shipping
                                        data-copy-text="آدرس گیرنده: استان تهران، شهریار، بلوار رسول اکرم، خیابان مفتح، پاساژ الماس شهر، طبقه زیر همکف، واحد ۸۷&#10;شماره موبایل: ۰۹۳۶۲۸۹۶۳۷۳&#10;کد پستی: ۳۳۵۱۶۱۱۸۲۸"
                                        aria-describedby="repair-shipping-copy-status">کپی اطلاعات ارسال</button>
                                <span id="repair-shipping-copy-status" class="repair-shipping-copy-status" role="status" aria-live="polite"></span>
                            </div>
                            <ul class="repair-checklist">
                                <li>سیم‌کارت شخصی، رول اضافه و لوازم غیرضروری را پیش از ارسال از دستگاه خارج کنید.</li>
                                <li>دستگاه را درون جعبه و با ضربه‌گیر مناسب بسته‌بندی کنید و کد پیگیری را داخل بسته بنویسید.</li>
                            </ul>
                            <p class="repair-insurance-alert">
                                <strong>یادآوری مهم بیمه مرسوله:</strong> هنگام تحویل بسته به پست یا تیپاکس، حتماً گزینهٔ <strong>«بیمهٔ مرسوله»</strong> را معادل ارزش واقعی دستگاه فعال کنید.
                            </p>
                        </div>

                        {{-- Conditional Details: In-Person Drop-Off --}}
                        <div class="repair-handover-panel repair-handover-panel--office" data-handover-panel="in_person" hidden>
                            <h3>نشانی مراجعهٔ حضوری نوین‌پی</h3>
                            <p class="repair-office-address">{{ config('business.address') }}</p>
                            <p class="repair-office-hours">ساعات کاری: <x-business-hours /> — تلفن: <a href="{{ config('business.phone_uri') }}" dir="ltr">{{ \App\Support\FaNumber::digits(config('business.phone')) }}</a></p>
                            <div class="repair-inperson-routing">
                                <span class="repair-inperson-routing__label">مسیریابی با:</span>
                                <nav class="repair-inperson-routing__links" aria-label="مسیریابی مراجعه حضوری">
                                    @foreach (['نشان' => ['neshan.svg', 16, 16], 'بلد' => ['balad.svg', 16, 16], 'Google Maps' => ['google-maps-2026.png', 13, 17]] as $mapLabel => [$iconFile, $iconW, $iconH])
                                        <a href="{{ config('business.maps')[$mapLabel] }}" target="_blank" rel="noopener noreferrer">
                                            <img src="{{ asset('brand/maps/'.$iconFile) }}" width="{{ $iconW }}" height="{{ $iconH }}" alt="" aria-hidden="true" loading="lazy" decoding="async">
                                            <span>{{ $mapLabel === 'Google Maps' ? 'گوگل مپ' : $mapLabel }}</span>
                                        </a>
                                    @endforeach
                                </nav>
                            </div>
                        </div>

                        {{-- Conditional Details: Doorstep Pickup --}}
                        <div class="repair-handover-panel repair-handover-panel--pickup" data-handover-panel="pickup" hidden>
                            <h3>مشخصات تحویل در محل (پیک نوین‌پی)</h3>
                            <div class="checkout-map repair-pickup-map" data-repair-map data-neshan-key="{{ $neshanWebKey }}" data-default-lat="35.6597" data-default-lng="51.0592">
                                <div class="checkout-map__header">
                                    <span>موقعیت تحویل روی نقشه (کلیک، جابه‌جایی پین یا «موقعیت من»)</span>
                                    <div class="checkout-map__controls">
                                        <button type="button" class="checkout-map__btn" data-map-locate title="موقعیت فعلی من">موقعیت من</button>
                                        <button type="button" class="checkout-map__btn" data-map-zoom="in" aria-label="بزرگ‌نمایی">+</button>
                                        <button type="button" class="checkout-map__btn" data-map-zoom="out" aria-label="کوچک‌نمایی">−</button>
                                    </div>
                                </div>
                                <div class="checkout-map__viewport" data-map-viewport tabindex="0" role="application" aria-label="انتخاب موقعیت روی نقشه">
                                    <div class="checkout-map__tiles" data-map-tiles></div>
                                    <div class="checkout-map__pin" data-map-fallback-pin aria-hidden="true"></div>
                                </div>
                                <div class="checkout-map__footer">
                                    <span class="checkout-map__coords" data-map-coords dir="ltr">{{ $pickupLat && $pickupLng ? $pickupLat.', '.$pickupLng : '۳۵.۶۵۹۷, ۵۱.۰۵۹۲' }}</span>
                                    <span class="checkout-map__attr" data-map-attr>© نقشه نشان</span>
                                </div>
                                <input type="hidden" name="pickup_latitude" value="{{ $pickupLat }}" data-map-lat>
                                <input type="hidden" name="pickup_longitude" value="{{ $pickupLng }}" data-map-lng>
                            </div>
                            <div class="public-field">
                                <label for="pickup_address">نشانی دقیق جهت دریافت دستگاه <span aria-hidden="true">*</span></label>
                                <textarea id="pickup_address" name="pickup_address" class="input" rows="2" maxlength="500" placeholder="خیابان، کوچه، پلاک، طبقه و واحد" aria-invalid="{{ $errors->has('pickup_address') ? 'true' : 'false' }}">{{ $pickupAddress }}</textarea>
                                <p class="public-field-error" data-field-error="pickup_address" role="alert" @unless($errors->has('pickup_address')) hidden @endunless>@error('pickup_address'){{ $message }}@enderror</p>
                            </div>
                            <div class="public-field">
                                <label for="responsive_phone">شماره تماس پاسخگو جهت هماهنگی پیک (اختیاری)</label>
                                <input id="responsive_phone" name="responsive_phone" type="tel" value="{{ $responsivePhone }}" class="input" dir="ltr" inputmode="tel" maxlength="20" placeholder="در صورت تفاوت با شماره حساب، مثلاً ۰۹۱۲۳۴۵۶۷۸۹" aria-invalid="{{ $errors->has('responsive_phone') ? 'true' : 'false' }}">
                                <p class="public-hint">اگر فرد دیگری دستگاه را تحویل پیک می‌دهد، شماره پاسخگو را اینجا وارد کنید.</p>
                                <p class="public-field-error" data-field-error="responsive_phone" role="alert" @unless($errors->has('responsive_phone')) hidden @endunless>@error('responsive_phone'){{ $message }}@enderror</p>
                            </div>
                        </div>
                    </div>

                    <div class="repair-summary-box" data-repair-summary>
                        <div class="repair-summary-box__item">
                            <span>دستگاه</span>
                            <strong class="np-latin-mixed" data-summary-device>—</strong>
                        </div>
                        <div class="repair-summary-box__item">
                            <span>شرح مشکل</span>
                            <strong data-summary-issue>—</strong>
                        </div>
                        <div class="repair-summary-box__item">
                            <span>تحویل و موقعیت</span>
                            <strong data-summary-handover>—</strong>
                        </div>
                    </div>

                    @if ($isGuest)
                        <div class="repair-inline-auth-box"
                             data-repair-inline-auth
                             data-send-url="{{ route('repair.auth.send') }}"
                             data-verify-url="{{ route('repair.auth.verify') }}"
                             data-reset-url="{{ route('repair.auth.reset') }}"
                             data-resend-remaining="{{ $authRemaining }}">
                            <div class="repair-inline-auth-box__head">
                                <h3>تأیید شماره موبایل جهت دریافت کد پیگیری</h3>
                                <p>برای ارسال پیامک وضعیت تعمیر و ثبت نهایی، شماره موبایل خود را تأیید کنید.</p>
                            </div>

                            <div class="repair-inline-auth__step" data-auth-step="phone" @if(!empty($authChallenge)) hidden @endif>
                                <div class="public-field">
                                    <label for="repair_auth_phone">شماره موبایل <span aria-hidden="true">*</span></label>
                                    <div class="repair-inline-auth__row">
                                        <input id="repair_auth_phone" type="tel" class="input" dir="ltr" inputmode="tel" autocomplete="tel-national" maxlength="20" placeholder="۰۹۱۲۳۴۵۶۷۸۹">
                                        <button type="button" class="np-button np-button--primary" data-auth-send-btn>ارسال کد تأیید</button>
                                    </div>
                                    <p class="public-field-error" data-field-error="repair_auth_phone" role="alert" hidden></p>
                                </div>
                            </div>

                            <div class="repair-inline-auth__step" data-auth-step="otp" @if(empty($authChallenge)) hidden @endif>
                                <div class="repair-recovery-phone-bar">
                                    <span>کد تأیید به شماره <strong dir="ltr" data-auth-phone-display>{{ $authDisplayPhone ?? '' }}</strong> ارسال شد.</span>
                                    <button type="button" class="repair-recovery-edit-btn" data-auth-change-phone>تغییر شماره موبایل</button>
                                </div>
                                <div class="public-field">
                                    <label for="repair_auth_code">کد تأیید شش‌رقمی <span aria-hidden="true">*</span></label>
                                    <div class="repair-inline-auth__row">
                                        <input id="repair_auth_code" type="text" class="input" dir="ltr" inputmode="numeric" autocomplete="one-time-code" maxlength="10" placeholder="۱۲۳۴۵۶">
                                        <button type="button" class="np-button np-button--primary" data-auth-verify-btn>تأیید شماره</button>
                                    </div>
                                    <p class="public-field-error" data-field-error="repair_auth_code" role="alert" hidden></p>
                                </div>
                                <div class="repair-recovery-resend">
                                    <button type="button" class="repair-recovery-resend__btn" data-auth-resend-btn @if($authRemaining > 0) disabled @endif>ارسال مجدد کد</button>
                                    <span class="repair-recovery-resend__timer" data-auth-timer>@if($authRemaining > 0){{ \App\Support\FaNumber::digits($authRemaining) }} ثانیه تا ارسال مجدد@endif</span>
                                </div>
                            </div>

                            <p class="repair-inline-auth__verified" data-auth-verified hidden>✓ شماره موبایل شما تأیید شد. اکنون می‌توانید درخواست را ثبت نهایی کنید.</p>
                        </div>
                    @endif

                    <div class="public-form__grid">
                        <x-repair-field name="customer_name" label="نام و نام خانوادگی">
                            <input id="customer_name" name="customer_name" value="{{ $customerName }}" class="input" autocomplete="name" required minlength="2" maxlength="100" aria-invalid="{{ $errors->has('customer_name') ? 'true' : 'false' }}" aria-describedby="customer_name-feedback">
                        </x-repair-field>
                        <x-repair-field name="contact_phone" label="شماره موبایل" :required="false">
                            <input readonly value="{{ \App\Support\FaNumber::digits(auth('customer')->user()?->phone ?? '') }}" type="tel" id="contact_phone" name="contact_phone" class="input" dir="ltr" inputmode="tel" autocomplete="tel" maxlength="40" placeholder="پس از تأیید شماره ثبت می‌شود" aria-invalid="{{ $errors->has('contact_phone') ? 'true' : 'false' }}" aria-describedby="contact_phone-feedback">
                        </x-repair-field>
                    </div>
                    <div class="repair-step-actions">
                        <button class="np-button np-button--primary" type="submit" data-repair-submit>ثبت درخواست تعمیر <x-home.icon name="arrow-left" /></button>
                        <button type="button" class="np-button np-button--outline" data-step-prev>مرحلهٔ قبل</button>
                    </div>
                </div>
            </form>
            <script type="application/json" id="repair-iran-locations">@json($iranLocations)</script>
            <script type="application/json" id="repair-courier-zones">@json($courierZones)</script>
        </section>
        </div>
    </div>
</div>
@endsection
