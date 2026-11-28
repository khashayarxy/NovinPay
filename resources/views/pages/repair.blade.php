@extends('layouts.app')
@section('title', 'درخواست تعمیر')
@section('meta_description', 'ثبت آنلاین درخواست تعمیر کارتخوان، انتخاب روش تحویل و دریافت کد پیگیری نوین‌پی')
@section('content')
@php
    $isGuest = ! auth('customer')->check();
    $prefill = $prefillDevice ?? null;
    $savedCustomerDevices = $savedDevices ?? collect();
    $selectedBrand = old('device_brand', $prefill->device_brand ?? '');
    $selectedBrand = is_string($selectedBrand) ? $selectedBrand : '';
    $selectedModel = old('device_model', $prefill->device_model ?? '');
    $selectedModel = is_string($selectedModel) ? $selectedModel : '';
    $selectedSerial = strtoupper((string) old('device_serial', $prefill->device_serial ?? ''));
    $selectedIssue = old('issue', '');
    $selectedIssue = is_string($selectedIssue) ? $selectedIssue : '';
    $selectedAccessories = old('accessories', []);
    $selectedAccessories = is_array($selectedAccessories) ? $selectedAccessories : [];
    $selectedProvince = old('province', '');
    $selectedProvince = is_string($selectedProvince) ? $selectedProvince : '';
    $selectedCity = old('city', '');
    $selectedCity = is_string($selectedCity) ? $selectedCity : '';
    $citiesForProvince = config('iran_locations', [])[$selectedProvince] ?? [];
    $selectedHandover = old('handover_method', '');
    $selectedHandover = is_string($selectedHandover) ? $selectedHandover : '';
    $pickupAddress = old('pickup_address', '');
    $pickupAddress = is_string($pickupAddress) ? $pickupAddress : '';
    $pickupLat = old('pickup_latitude', '');
    $pickupLng = old('pickup_longitude', '');
    $responsivePhone = old('responsive_phone', '');
    $responsivePhone = is_string($responsivePhone) ? $responsivePhone : '';
    $pickupNotes = old('pickup_notes', '');
    $pickupNotes = is_string($pickupNotes) ? $pickupNotes : '';
    $hasOtherPerson = (bool) old('other_person_handover', $responsivePhone !== '' || $errors->has('responsive_phone'));
    $customerName = old('customer_name', auth('customer')->user()?->name ?? '');
    $customerName = is_string($customerName) ? $customerName : '';
    $customerPhone = old('contact_phone', auth('customer')->user()?->phone ?? ($authDisplayPhone ?? ''));
    $customerPhone = is_string($customerPhone) ? $customerPhone : '';
    $initialStep = 1;
    if ($errors->hasAny(['device_brand', 'device_model', 'device_serial', 'accessories', 'accessories.*'])) {
        $initialStep = 1;
    } elseif ($errors->hasAny(['issue'])) {
        $initialStep = 2;
    } elseif ($errors->hasAny(['customer_name', 'contact_phone', 'province', 'city', 'handover_method', 'pickup_address', 'pickup_notes', 'responsive_phone'])) {
        $initialStep = 3;
    } elseif ($errors->hasAny(['submission_token', 'code'])) {
        $initialStep = 4;
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
<div class="repair-entry-bg">
<div class="np-container public-section repair-entry">
    <div class="repair-entry-shell">
        <div class="repair-split-card">
        <section class="repair-split-form" aria-labelledby="repair-form-title">
            <header class="repair-card-head">
                <div class="repair-card-head__row">
                    <h1 id="repair-form-title">ثبت درخواست تعمیر کارتخوان</h1>
                    <a class="public-inline-link repair-card-head__link" href="{{ route('repair.track') }}">پیگیری تعمیر</a>
                </div>
            </header>

            <x-form-errors />

            <form method="post"
                  action="{{ route('repair.store') }}"
                  enctype="multipart/form-data"
                  class="public-form repair-stepper"
                  data-repair-stepper
                  data-initial-step="{{ $initialStep }}"
                  data-is-guest="{{ $isGuest ? '1' : '0' }}"
                  data-reverse-geocode-url="{{ route('repair.reverse-geocode') }}"
                  data-draft-key="np_repair_draft_v3"
                  novalidate>
                @csrf
                <input type="hidden" name="submission_token" value="{{ $submissionToken }}">

                <ol class="repair-progress repair-progress--4" aria-label="مراحل ثبت درخواست تعمیر">
                    <li>
                        <button type="button" class="repair-progress__item" data-step-indicator="1" aria-current="{{ $initialStep === 1 ? 'step' : 'false' }}">
                            <span class="repair-progress__num">۱</span>
                            <span class="repair-progress__label">مشخصات دستگاه</span>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="repair-progress__item" data-step-indicator="2" aria-current="{{ $initialStep === 2 ? 'step' : 'false' }}">
                            <span class="repair-progress__num">۲</span>
                            <span class="repair-progress__label">مشکل دستگاه</span>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="repair-progress__item" data-step-indicator="3" aria-current="{{ $initialStep === 3 ? 'step' : 'false' }}">
                            <span class="repair-progress__num">۳</span>
                            <span class="repair-progress__label">تماس و تحویل</span>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="repair-progress__item" data-step-indicator="4" aria-current="{{ $initialStep === 4 ? 'step' : 'false' }}">
                            <span class="repair-progress__num">۴</span>
                            <span class="repair-progress__label">بازبینی و ثبت</span>
                        </button>
                    </li>
                </ol>

                <p class="repair-step-alert" data-step-alert role="alert" hidden></p>

                {{-- Step 1: Device Details --}}
                <div class="repair-step" data-step="1" @if($initialStep !== 1) hidden @endif>
                    <div class="public-field">
                        <div class="np-float-field np-float-field--select" data-float-field>
                            <select id="device_brand" name="device_brand" class="input" data-custom-select required aria-invalid="{{ $errors->has('device_brand') ? 'true' : 'false' }}" aria-describedby="device_brand-feedback">
                                <option value="" disabled @selected(empty($selectedBrand)) hidden></option>
                                @foreach ($brands as $brand)
                                    <option value="{{ $brand->value }}" @selected($selectedBrand === $brand->value)>{{ $brand->value }}</option>
                                @endforeach
                            </select>
                            <label class="label" for="device_brand">برند دستگاه <span class="required-mark" aria-hidden="true">*</span></label>
                        </div>
                        <p class="public-field-error" data-field-error="device_brand" id="device_brand-feedback" role="alert" @unless($errors->has('device_brand')) hidden @endunless>@error('device_brand'){{ $message }}@enderror</p>
                    </div>
                    <div class="public-form__grid repair-paired-fields">
                        <x-repair-field name="device_model" label="مدل دستگاه">
                            <input id="device_model" name="device_model" value="{{ $selectedModel }}" class="input np-latin-mixed" dir="rtl" placeholder="مثلاً ME31" required maxlength="100" aria-invalid="{{ $errors->has('device_model') ? 'true' : 'false' }}" aria-describedby="device_model-feedback">
                        </x-repair-field>
                        <x-repair-field name="device_serial" label="شماره سریال دستگاه (اختیاری)" :required="false">
                            <input id="device_serial" name="device_serial" value="{{ $selectedSerial }}" class="input np-latin-mixed pos-serial-input" dir="ltr" autocapitalize="characters" spellcheck="false" autocomplete="off" data-pos-serial maxlength="100" placeholder="کد S/N درج‌شده روی برچسب پشت دستگاه" aria-invalid="{{ $errors->has('device_serial') ? 'true' : 'false' }}" aria-describedby="device_serial-feedback">
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
                        <button type="button" class="np-button np-button--primary" data-step-next>مرحلهٔ بعد <x-home.icon name="chevron-left" /></button>
                    </div>
                </div>

                {{-- Step 2: Issue Selection --}}
                <div class="repair-step" data-step="2" @if($initialStep !== 2) hidden @endif>
                    <div class="repair-step__head">
                        <h2>مشکل دستگاه</h2>
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
                    <div class="public-form__grid repair-paired-fields">
                        <div class="public-field">
                            <div class="np-float-field np-float-field--select" data-float-field>
                                <select id="activation_status" name="activation_status" class="input" data-custom-select aria-invalid="{{ $errors->has('activation_status') ? 'true' : 'false' }}">
                                    <option value="" disabled @selected(empty(old('activation_status'))) hidden></option>
                                    @foreach (\App\Models\RepairRequest::ACTIVATION_STATUSES as $statusKey => $statusLabel)
                                        <option value="{{ $statusKey }}" @selected(old('activation_status') === $statusKey)>{{ $statusLabel }}</option>
                                    @endforeach
                                </select>
                                <label class="label" for="activation_status">وضعیت اتصال و فعال‌سازی دستگاه</label>
                            </div>
                        </div>
                        <div class="public-field">
                            <div class="np-float-field np-float-field--select" data-float-field>
                                <select id="tax_file_status" name="tax_file_status" class="input" data-custom-select aria-invalid="{{ $errors->has('tax_file_status') ? 'true' : 'false' }}">
                                    <option value="" disabled @selected(empty(old('tax_file_status'))) hidden></option>
                                    @foreach (\App\Models\RepairRequest::TAX_FILE_STATUSES as $taxKey => $taxLabel)
                                        <option value="{{ $taxKey }}" @selected(old('tax_file_status') === $taxKey)>{{ $taxLabel }}</option>
                                    @endforeach
                                </select>
                                <label class="label" for="tax_file_status">وضعیت پرونده مالیاتی</label>
                            </div>
                        </div>
                    </div>
                    <div class="repair-step-actions">
                        <button type="button" class="np-button np-button--primary" data-step-next>مرحلهٔ بعد <x-home.icon name="chevron-left" /></button>
                        <button type="button" class="np-button np-button--outline" data-step-prev>مرحلهٔ قبل</button>
                    </div>
                </div>

                {{-- Step 3: Contact, Searchable Location & Handover --}}
                <div class="repair-step" data-step="3" @if($initialStep !== 3) hidden @endif>
                    <div class="repair-step__head">
                        <h2>اطلاعات تماس و تحویل</h2>
                    </div>

                    <div class="public-form__grid">
                        <x-repair-field name="customer_name" label="نام و نام خانوادگی">
                            <input id="customer_name" name="customer_name" value="{{ $customerName }}" class="input" autocomplete="name" required minlength="2" maxlength="100" placeholder="مثلاً علی رضایی" aria-invalid="{{ $errors->has('customer_name') ? 'true' : 'false' }}" aria-describedby="customer_name-feedback">
                        </x-repair-field>
                        <x-repair-field name="contact_phone" label="شماره موبایل" :hint="!$isGuest ? 'شماره موبایل حساب کاربری شما تأیید شده است.' : null">
                            <input @if(!$isGuest) readonly @endif value="{{ $customerPhone !== '' ? \App\Support\FaNumber::digits($customerPhone) : '' }}" type="tel" id="contact_phone" name="contact_phone" class="input" dir="ltr" inputmode="numeric" data-digits-only="11" autocomplete="tel" maxlength="11" placeholder="۰۹۱۲۳۴۵۶۷۸۹" required aria-invalid="{{ $errors->has('contact_phone') ? 'true' : 'false' }}" aria-describedby="contact_phone-feedback">
                        </x-repair-field>
                    </div>

                    <div class="repair-location-block" data-repair-location>
                        <div class="public-form__grid">
                            <div class="public-field">
                                <div class="np-float-field" data-float-field>
                                    <select id="repair_province" name="province" class="input" data-custom-select data-repair-province aria-invalid="{{ $errors->has('province') ? 'true' : 'false' }}">
                                        <option value="">انتخاب استان...</option>
                                        @foreach (($iranLocationProvinces ?? array_keys($iranLocations ?? [])) as $provinceName)
                                            <option value="{{ $provinceName }}" @selected($selectedProvince === $provinceName)>{{ $provinceName }}</option>
                                        @endforeach
                                    </select>
                                    <label for="repair_province">استان <span aria-hidden="true">*</span></label>
                                </div>
                                <p class="public-field-error" data-field-error="province" role="alert" @unless($errors->has('province')) hidden @endunless>@error('province'){{ $message }}@enderror</p>
                            </div>
                            <div class="public-field">
                                <div class="np-float-field" data-float-field>
                                    <select id="repair_city" name="city" class="input" data-custom-select data-repair-city data-selected-city="{{ $selectedCity }}" aria-invalid="{{ $errors->has('city') ? 'true' : 'false' }}" @disabled(empty($selectedProvince))>
                                        <option value="">{{ $selectedProvince !== '' ? 'انتخاب شهر...' : 'ابتدا استان را انتخاب کنید...' }}</option>
                                        @foreach ($citiesForProvince as $cityName)
                                            <option value="{{ $cityName }}" @selected($selectedCity === $cityName)>{{ $cityName }}</option>
                                        @endforeach
                                    </select>
                                    <label for="repair_city">شهر <span aria-hidden="true">*</span></label>
                                </div>
                                <p class="public-field-error" data-field-error="city" role="alert" @unless($errors->has('city')) hidden @endunless>@error('city'){{ $message }}@enderror</p>
                            </div>
                        </div>

                        <div class="repair-handover-section" data-handover-section>
                            <h3 class="repair-handover-title">نحوهٔ تحویل دستگاه به نوین‌پی <span class="required-mark">*</span></h3>
                            <p class="repair-handover-hint" data-handover-hint hidden></p>
                            <div class="repair-handover-grid" role="radiogroup" aria-label="نحوهٔ تحویل دستگاه">
                                <label class="repair-choice-card repair-choice-card--handover" data-handover-option="self_ship">
                                    <input type="radio" name="handover_method" value="self_ship" @checked($selectedHandover === 'self_ship')>
                                    <span class="repair-handover-card__head" aria-hidden="true">
                                        <span class="repair-handover-card__icon">
                                            <svg class="repair-handover-card__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                                        </span>
                                        <span class="repair-handover-card__dot"></span>
                                    </span>
                                    <span class="repair-choice-card__title">خودم ارسال میکنم</span>
                                    <span class="repair-choice-card__sub">ارسال از سراسر کشور با پست یا تیپاکس</span>
                                </label>
                                <label class="repair-choice-card repair-choice-card--handover" data-handover-option="in_person">
                                    <input type="radio" name="handover_method" value="in_person" @checked($selectedHandover === 'in_person')>
                                    <span class="repair-handover-card__head" aria-hidden="true">
                                        <span class="repair-handover-card__icon">
                                            <svg class="repair-handover-card__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5.5-8 11-8 11S4 15.5 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                                        </span>
                                        <span class="repair-handover-card__dot"></span>
                                    </span>
                                    <span class="repair-choice-card__title">خودم حضوری میارم</span>
                                    <span class="repair-choice-card__sub">تحویل مستقیم در فروشگاه نوین‌پی شهریار</span>
                                </label>
                                <label class="repair-choice-card repair-choice-card--handover" data-handover-option="doorstep_pickup" hidden>
                                    <input type="radio" name="handover_method" value="doorstep_pickup" @checked($selectedHandover === 'doorstep_pickup') disabled>
                                    <span class="repair-handover-card__head" aria-hidden="true">
                                        <span class="repair-handover-card__icon">
                                            <svg class="repair-handover-card__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>
                                        </span>
                                        <span class="repair-handover-card__dot"></span>
                                    </span>
                                    <span class="repair-choice-card__title">به پیک شما تحویل میدم</span>
                                    <span class="repair-choice-card__sub">دریافت دستگاه از محل شما توسط پیک نوین‌پی</span>
                                </label>
                            </div>
                            <p class="public-field-error" data-field-error="handover_method" role="alert" @unless($errors->has('handover_method')) hidden @endunless>@error('handover_method'){{ $message }}@enderror</p>
                        </div>

                        {{-- Conditional Details: Self-Ship (Concise 1-line note in Step 3) --}}
                        <div class="repair-handover-note" data-handover-panel="self_ship" hidden>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                            <span>نشانی دقیق ارسال پستی و راهنمای بسته‌بندی بلافاصله پس از ثبت نهایی نمایش داده می‌شود.</span>
                        </div>

                        {{-- Conditional Details: In-Person Drop-Off --}}
                        <x-repair.store-delivery data-handover-panel="in_person" hidden />

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
                                <div class="np-float-field" data-float-field>
                                    <textarea id="pickup_address" name="pickup_address" class="input" rows="2" maxlength="500" placeholder="خیابان، کوچه، پلاک، طبقه و واحد" aria-invalid="{{ $errors->has('pickup_address') ? 'true' : 'false' }}">{{ $pickupAddress }}</textarea>
                                    <label for="pickup_address">نشانی دقیق جهت دریافت دستگاه <span aria-hidden="true">*</span></label>
                                </div>
                                <p class="public-field-error" data-field-error="pickup_address" role="alert" @unless($errors->has('pickup_address')) hidden @endunless>@error('pickup_address'){{ $message }}@enderror</p>
                            </div>
                            <div class="public-field">
                                <div class="np-float-field" data-float-field>
                                    <input id="pickup_notes" name="pickup_notes" type="text" value="{{ $pickupNotes }}" class="input" maxlength="300" placeholder="مثلاً بازهٔ زمانی مناسب، زنگ یا طبقه، توضیحات دسترسی" aria-invalid="{{ $errors->has('pickup_notes') ? 'true' : 'false' }}">
                                    <label for="pickup_notes">توضیحات برای پیک (اختیاری)</label>
                                </div>
                                <p class="public-field-error" data-field-error="pickup_notes" role="alert" @unless($errors->has('pickup_notes')) hidden @endunless>@error('pickup_notes'){{ $message }}@enderror</p>
                            </div>
                            <div class="repair-courier-alt">
                                <label class="repair-choice-card repair-choice-card--accessory repair-courier-alt__toggle" for="other_person_handover">
                                    <input type="checkbox" id="other_person_handover" name="other_person_handover" value="1" data-other-person-toggle @checked($hasOtherPerson)>
                                    <span class="repair-choice-card__check" aria-hidden="true">
                                        <svg class="repair-choice-card__tick" viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.2 8.4 6.5 11.7 12.8 4.6"/></svg>
                                    </span>
                                    <span class="repair-choice-card__title">فرد دیگری دستگاه را به پیک تحویل می‌دهد</span>
                                </label>
                                <div class="public-field repair-courier-alt__field" data-other-person-field @unless($hasOtherPerson) hidden @endunless>
                                    <div class="np-float-field" data-float-field>
                                        <input id="responsive_phone" name="responsive_phone" type="tel" value="{{ $responsivePhone }}" class="input repair-rtl-phone-input" dir="ltr" inputmode="numeric" autocomplete="tel" data-digits-only="11" maxlength="11" placeholder="۰۹۱۲۳۴۵۶۷۸۹" aria-invalid="{{ $errors->has('responsive_phone') ? 'true' : 'false' }}">
                                        <label for="responsive_phone">شماره تماس تحویل‌دهنده</label>
                                    </div>
                                    <p class="public-field-error" data-field-error="responsive_phone" role="alert" @unless($errors->has('responsive_phone')) hidden @endunless>@error('responsive_phone'){{ $message }}@enderror</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="repair-step-actions">
                        <button type="button" class="np-button np-button--primary" data-step-next>بازبینی و مرحلهٔ پایانی <x-home.icon name="chevron-left" /></button>
                        <button type="button" class="np-button np-button--outline" data-step-prev>مرحلهٔ قبل</button>
                    </div>
                </div>

                {{-- Step 4: Review, OTP (for guests) & Final Submit --}}
                <div class="repair-step" data-step="4" @if($initialStep !== 4) hidden @endif>
                    <div class="repair-step__head">
                        <h2>بازبینی و ثبت نهایی</h2>
                    </div>

                    <div class="repair-summary-box" data-repair-summary>
                        <div class="repair-summary-box__item">
                            <span>دستگاه</span>
                            <strong class="np-latin-mixed" data-summary-device>—</strong>
                        </div>
                        <div class="repair-summary-box__item">
                            <span>لوازم همراه</span>
                            <strong data-summary-accessories>بدون لوازم اضافه</strong>
                        </div>
                        <div class="repair-summary-box__item">
                            <span>شرح مشکل</span>
                            <strong data-summary-issue>—</strong>
                        </div>
                        <div class="repair-summary-box__item">
                            <span>نام و موبایل</span>
                            <strong data-summary-contact>—</strong>
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
                                <h3>تأیید پیامکی شماره موبایل</h3>
                                <p>کد ۶ رقمی به شمارهٔ واردشده ارسال می‌شود و بلافاصله پس از وارد کردن، درخواست شما ثبت خواهد شد.</p>
                            </div>

                            <div class="repair-inline-auth__step repair-inline-auth__step--request" data-auth-step="phone" @if(!empty($authChallenge)) hidden @endif>
                                <div class="repair-inline-auth__row repair-inline-auth__row--cta">
                                    <span class="repair-auth-target">کد تأیید به شمارهٔ <strong dir="ltr" data-auth-phone-preview>—</strong> ارسال می‌شود.</span>
                                    <button type="button" class="np-button np-button--primary" data-auth-send-btn>تأیید اطلاعات و دریافت کد پیامکی</button>
                                </div>
                                <p class="public-field-error" data-field-error="repair_auth_phone" role="alert" hidden></p>
                            </div>

                            <div class="repair-inline-auth__step repair-inline-auth__step--otp" data-auth-step="otp" @if(empty($authChallenge)) hidden @endif>
                                <div class="repair-recovery-phone-bar">
                                    <span>کد تأیید به شماره <strong dir="ltr" data-auth-phone-display>{{ $authDisplayPhone ?? '' }}</strong> ارسال شد.</span>
                                    <button type="button" class="repair-recovery-edit-btn" data-auth-change-phone>ویرایش شماره</button>
                                </div>
                                @if (app()->isLocal())
                                    <p class="public-field-hint" data-local-dev-otp @if(empty($localDevOtp)) hidden @endif style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:8px 12px;margin-block-end:10px;">
                                        کد تأیید لوکال (فقط محیط توسعه): <strong class="np-latin" dir="ltr" data-local-dev-otp-code>{{ $localDevOtp ?? '' }}</strong>
                                    </p>
                                @endif
                                <div class="public-field">
                                    <div class="repair-inline-auth__row">
                                        <div class="np-float-field" data-float-field>
                                            <input id="repair_auth_code" type="text" class="input repair-otp-input" dir="ltr" inputmode="numeric" data-digits-only="6" autocomplete="one-time-code" maxlength="6" placeholder="••••••">
                                            <label for="repair_auth_code">کد تأیید شش‌رقمی <span aria-hidden="true">*</span></label>
                                        </div>
                                        <button type="button" class="np-button np-button--primary" data-auth-verify-btn>تأیید و ثبت درخواست</button>
                                    </div>
                                    <p class="public-field-error" data-field-error="repair_auth_code" role="alert" hidden></p>
                                </div>
                                <div class="repair-recovery-resend">
                                    <button type="button" class="repair-recovery-resend__btn" data-auth-resend-btn @if($authRemaining > 0) disabled @endif>ارسال مجدد کد</button>
                                    <span class="repair-recovery-resend__timer" data-auth-timer>@if($authRemaining > 0){{ \App\Support\FaNumber::digits($authRemaining) }} ثانیه تا ارسال مجدد@endif</span>
                                </div>
                            </div>

                            <p class="repair-inline-auth__verified" data-auth-verified hidden>✓ شماره موبایل شما تأیید شد. در حال ثبت نهایی درخواست…</p>
                        </div>
                    @endif

                    <div class="repair-step-actions">
                        <button class="np-button np-button--primary" type="submit" data-repair-submit data-loading-label="در حال ارسال..." @if($isGuest) hidden @endif>ثبت نهایی درخواست تعمیر</button>
                        <button type="button" class="np-button np-button--outline" data-step-prev>مرحلهٔ قبل</button>
                    </div>
                </div>
            </form>
            <script type="application/json" id="repair-iran-locations" data-src="{{ versioned_asset('js/iran-locations.json') }}"></script>
            <script type="application/json" id="repair-courier-zones">@json($courierZones)</script>
        </section>

        {{-- Integrated Left Branding & Guarantees Panel (Static Split-Card Half) --}}
        <aside class="repair-split-trust repair-trust-card" aria-label="مزایا و تضمین خدمات تعمیر نوین‌پی">
            <div class="repair-split-trust__glow" aria-hidden="true"></div>
            <div class="repair-split-trust__top">
                <div class="repair-split-trust__art" aria-hidden="true">
                    <svg class="repair-split-trust__hero-svg" viewBox="0 0 260 176" width="260" height="176" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="npRepairArtSurface" x1="26" y1="12" x2="234" y2="164" gradientUnits="userSpaceOnUse">
                                <stop stop-color="#ffffff" stop-opacity="0.92"/>
                                <stop offset="1" stop-color="#f0f7ff" stop-opacity="0.82"/>
                            </linearGradient>
                            <linearGradient id="npRepairShieldFill" x1="146" y1="26" x2="206" y2="96" gradientUnits="userSpaceOnUse">
                                <stop stop-color="#098cf7"/>
                                <stop offset="1" stop-color="#075da6"/>
                            </linearGradient>
                            <linearGradient id="npRepairScreen" x1="72" y1="40" x2="144" y2="84" gradientUnits="userSpaceOnUse">
                                <stop stop-color="#e0f2fe"/>
                                <stop offset="1" stop-color="#eff6ff"/>
                            </linearGradient>
                        </defs>
                        <rect x="18" y="10" width="224" height="156" rx="24" fill="url(#npRepairArtSurface)" stroke="#c7def6" stroke-width="1.5"/>
                        <circle cx="62" cy="40" r="22" fill="#dbeafe" opacity="0.65"/>
                        <circle cx="204" cy="132" r="26" fill="#e0f2fe" opacity="0.75"/>
                        {{-- POS Terminal Body --}}
                        <rect x="64" y="24" width="92" height="128" rx="16" fill="#ffffff" stroke="#93c5fd" stroke-width="2"/>
                        <rect x="86" y="17" width="48" height="11" rx="4" fill="#dbeafe" stroke="#93c5fd" stroke-width="1.5"/>
                        <rect x="76" y="38" width="68" height="40" rx="8" fill="url(#npRepairScreen)" stroke="#7dd3fc" stroke-width="1.5"/>
                        <path d="M88 59h18M88 67h30" stroke="#075da6" stroke-width="2.4" stroke-linecap="round" opacity="0.55"/>
                        <circle cx="130" cy="53" r="8" fill="#098cf7" opacity="0.16"/>
                        <path d="m126.5 53 2.5 2.5 5-5" stroke="#075da6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        {{-- POS Keypad --}}
                        <rect x="78" y="90" width="18" height="10" rx="3" fill="#dbeafe"/>
                        <rect x="101" y="90" width="18" height="10" rx="3" fill="#dbeafe"/>
                        <rect x="124" y="90" width="18" height="10" rx="3" fill="#dbeafe"/>
                        <rect x="78" y="106" width="18" height="10" rx="3" fill="#dbeafe"/>
                        <rect x="101" y="106" width="18" height="10" rx="3" fill="#dbeafe"/>
                        <rect x="124" y="106" width="18" height="10" rx="3" fill="#93c5fd"/>
                        <rect x="78" y="124" width="64" height="10" rx="5" fill="#075da6"/>
                        {{-- Protective Repair Guarantee Shield --}}
                        <path d="M178 28l28 10.5v21.5c0 19.2-11.9 35.8-28 42-16.1-6.2-28-22.8-28-42V38.5L178 28Z" fill="url(#npRepairShieldFill)" stroke="#ffffff" stroke-width="3"/>
                        <path d="m167.5 63 7 7 14.5-15" stroke="#ffffff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>

                <span class="repair-trust-card__eyebrow">مرکز تخصصی تعمیرات نوین‌پی</span>
                <h2>تعمیر مطمئن، شفاف و با ضمانت</h2>
                <p class="repair-split-trust__lead">تمامی مراحل پذیرش، عیب‌یابی و تحویل کارتخوان با اعلام قبلی هزینه و ثبت در پروندهٔ آنلاین انجام می‌شود.</p>

                <ul class="repair-trust-list">
                    <li>
                        <span class="repair-trust-list__icon" aria-hidden="true"><x-home.icon name="shield-check" /></span>
                        <div>
                            <strong>عیب‌یابی تخصصی و شفاف</strong>
                            <p>بررسی دقیق سخت‌افزار، چاپگر، باتری و ماژول ارتباطی توسط کارشناسان مجرب.</p>
                        </div>
                    </li>
                    <li>
                        <span class="repair-trust-list__icon" aria-hidden="true"><x-home.icon name="tool" /></span>
                        <div>
                            <strong>اعلام هزینه پیش از شروع تعمیر</strong>
                            <p>هیچ هزینه‌ای بدون اطلاع و تأیید قبلی شما انجام نمی‌شود.</p>
                        </div>
                    </li>
                    <li>
                        <span class="repair-trust-list__icon" aria-hidden="true"><x-home.icon name="check" /></span>
                        <div>
                            <strong>قطعات اصلی و تست کامل</strong>
                            <p>تمام دستگاه‌ها پس از تعمیر، زیر بار تراکنش واقعی و تست چاپگر قرار می‌گیرند.</p>
                        </div>
                    </li>
                    <li>
                        <span class="repair-trust-list__icon" aria-hidden="true"><x-home.icon name="phone" /></span>
                        <div>
                            <strong>پیگیری آنلاین و پیامکی در هر مرحله</strong>
                            <p>از لحظه پذیرش تا آماده تحویل شدن، وضعیت پرونده به‌صورت لحظه‌ای اطلاع‌رسانی می‌شود.</p>
                        </div>
                    </li>
                </ul>
            </div>

            <div class="repair-split-trust__footer">
                <span>نیاز به راهنمایی پیش از ثبت دارید؟</span>
                <a href="{{ config('business.phone_uri') }}" dir="ltr">{{ \App\Support\FaNumber::digits(config('business.phone')) }}</a>
            </div>
        </aside>
        </div>
    </div>
</div>
</div>
@endsection
