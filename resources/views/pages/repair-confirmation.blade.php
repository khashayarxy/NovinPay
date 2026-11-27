@extends('layouts.app')
@section('title', 'تأیید ثبت درخواست تعمیر')
@section('content')
@php
    $handover = session('repair_handover');
    $confirmationSteps = [
        ['num' => '۱', 'label' => 'ثبت درخواست', 'state' => 'complete'],
        ['num' => '۲', 'label' => 'دریافت دستگاه', 'state' => 'current'],
        ['num' => '۳', 'label' => 'بررسی و اعلام هزینه', 'state' => 'upcoming'],
        ['num' => '۴', 'label' => 'تعمیر و تحویل', 'state' => 'upcoming'],
    ];
@endphp
<div class="repair-entry-bg" data-repair-confirmed>
<div class="np-container public-section repair-entry repair-confirm-page">
    <div class="repair-entry-shell">
        <div class="repair-split-card repair-confirm-split">
            {{-- Right Pane: Main Confirmation Status, Code & Primary Actions --}}
            <section class="repair-split-form repair-confirm-main" aria-labelledby="confirmation-title">
                <div class="repair-confirm-head">
                    <span class="repair-confirm-badge" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </span>
                    <h1 id="confirmation-title">درخواست تعمیر شما ثبت شد</h1>
                    <p class="repair-confirm-lead">مرحلهٔ اول انجام شد؛ اکنون در انتظار دریافت دستگاه شما هستیم. کد پیگیری زیر را نگه دارید تا در تمام مراحل وضعیت تعمیر را ببینید.</p>
                </div>

                <div class="repair-confirm-stepper">
                    <ol class="repair-progress repair-progress--4 repair-confirm-progress" aria-label="مراحل پیگیری تعمیر">
                        @foreach ($confirmationSteps as $step)
                            <li data-stage="{{ $step['state'] }}">
                                <div class="repair-progress__item"
                                     @if ($step['state'] === 'complete') data-step-done="true" @endif
                                     @if ($step['state'] === 'current') aria-current="step" @endif>
                                    <span class="repair-progress__num" aria-hidden="true">
                                        @if ($step['state'] === 'complete')
                                            <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="m3 8 3 3 7-7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        @else
                                            {{ $step['num'] }}
                                        @endif
                                    </span>
                                    <span class="repair-progress__label">{{ $step['label'] }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                    <p class="repair-confirm-stepper__note">وضعیت فعلی: <strong>دستگاه هنوز تحویل نشده است</strong></p>
                </div>

                <div class="repair-confirmation-code">
                    <span class="repair-confirmation-code__label">کد پیگیری</span>
                    <p class="public-reference" id="repair-reference-code" dir="ltr">{{ $reference }}</p>
                    <button type="button" class="repair-confirmation-code__copy" data-copy-account="repair-reference-code" aria-describedby="repair-copy-status" hidden>کپی کد پیگیری</button>
                    <p class="repair-confirmation-code__status" id="repair-copy-status" role="status"></p>
                </div>

                <div class="public-actions repair-confirm-actions">
                    <a href="{{ route('account.repairs.request', $reference) }}" class="np-button np-button--primary">پیگیری وضعیت تعمیر</a>
                    <a href="{{ config('business.phone_uri') }}" class="np-button np-button--outline">تماس با نوین‌پی</a>
                </div>
            </section>

            {{-- Left Pane: Integrated Brand-Tinted Next-Step Handover Panel --}}
            <aside class="repair-split-trust repair-confirm-aside" aria-label="گام بعدی برای تحویل دستگاه">
                <div class="repair-split-trust__glow" aria-hidden="true"></div>
                <div class="repair-split-trust__top">
                    <span class="repair-trust-card__eyebrow">راهنمای تحویل دستگاه</span>
                    <h2>گام بعدی برای تحویل دستگاه</h2>

                    <div class="repair-confirm-next-body">
                        @if ($handover === 'self_ship')
                            <p class="repair-confirm-next-lead">لطفاً دستگاه را با بسته‌بندی ایمن به نشانی زیر ارسال کنید و کد پیگیری را داخل بسته قرار دهید:</p>
                            <div class="repair-shipping-card">
                                <div class="repair-shipping-card__head">
                                    <span class="repair-shipping-card__title">اطلاعات گیرنده جهت ارسال</span>
                                    <button type="button"
                                            class="repair-shipping-copy-btn"
                                            data-copy-shipping
                                            data-copy-text="آدرس گیرنده: {{ config('business.address') }}&#10;شماره موبایل: {{ \App\Support\FaNumber::digits(config('business.phone')) }}&#10;کد پستی: ۳۳۵۱۶۱۱۸۲۸"
                                            data-copied-label="اطلاعات کپی شد">کپی اطلاعات ارسال</button>
                                </div>
                                <dl class="repair-shipping-details">
                                    <div>
                                        <dt>آدرس گیرنده:</dt>
                                        <dd>{{ config('business.address') }}</dd>
                                    </div>
                                    <div>
                                        <dt>شماره موبایل:</dt>
                                        <dd><a href="{{ config('business.phone_uri') }}" dir="ltr">{{ \App\Support\FaNumber::digits(config('business.phone')) }}</a></dd>
                                    </div>
                                    <div>
                                        <dt>کد پستی:</dt>
                                        <dd class="np-fact-rtl-num"><bdi dir="ltr">۳۳۵۱۶۱۱۸۲۸</bdi></dd>
                                    </div>
                                </dl>
                            </div>
                            <p class="repair-insurance-alert">هنگام ارسال با پست یا تیپاکس، حتماً گزینهٔ <strong>«بیمهٔ مرسوله»</strong> را فعال کنید.</p>
                        @elseif ($handover === 'in_person')
                            <p class="repair-confirm-next-lead">لطفاً دستگاه را همراه با کد پیگیری و لوازم انتخاب‌شده به فروشگاه نوین‌پی تحویل دهید:</p>
                            <div class="repair-confirm-info-card">
                                <p class="repair-office-address">{{ config('business.address') }}</p>
                                <p class="repair-office-hours">ساعات کاری: <x-business-hours /></p>
                                <div class="repair-inperson-routing">
                                    <span class="repair-inperson-routing__label">مسیریابی با:</span>
                                    <nav class="repair-inperson-routing__links" aria-label="مسیریابی مراجعه حضوری">
                                        @foreach (['نشان' => ['neshan.svg', 16, 16], 'بلد' => ['balad.svg', 16, 16], 'Google Maps' => ['google-maps-2026.png', 13, 17]] as $mapLabel => [$iconFile, $iconW, $iconH])
                                            <a href="{{ config('business.maps')[$mapLabel] }}" target="_blank" rel="noopener noreferrer">
                                                @if ($iconFile === 'google-maps-2026.png' && is_file(public_path('brand/maps/google-maps-2026-28.webp')))
                                                    <picture class="np-map-brand-picture">
                                                        <source type="image/webp" srcset="{{ asset('brand/maps/google-maps-2026-28.webp') }} 28w, {{ asset('brand/maps/google-maps-2026-56.webp') }} 56w" sizes="14px">
                                                        <source type="image/avif" srcset="{{ asset('brand/maps/google-maps-2026-28.avif') }} 28w, {{ asset('brand/maps/google-maps-2026-56.avif') }} 56w" sizes="14px">
                                                        <img src="{{ asset('brand/maps/'.$iconFile) }}" width="{{ $iconW }}" height="{{ $iconH }}" alt="" aria-hidden="true" loading="lazy" decoding="async">
                                                    </picture>
                                                @else
                                                    <img src="{{ asset('brand/maps/'.$iconFile) }}" width="{{ $iconW }}" height="{{ $iconH }}" alt="" aria-hidden="true" loading="lazy" decoding="async">
                                                @endif
                                                <span>{{ $mapLabel === 'Google Maps' ? 'گوگل مپ' : $mapLabel }}</span>
                                            </a>
                                        @endforeach
                                    </nav>
                                </div>
                            </div>
                        @elseif ($handover === 'doorstep_pickup')
                            <div class="repair-confirm-info-card">
                                <p class="repair-confirm-next-lead" style="margin:0;">درخواست دریافت با پیک نوین‌پی ثبت شده است. همکاران ما برای هماهنگی زمان دقیق مراجعهٔ پیک با شماره تماس شما ارتباط می‌گیرند.</p>
                            </div>
                            <ul class="repair-trust-list" style="margin-top:14px;">
                                <li>
                                    <span class="repair-trust-list__icon" aria-hidden="true"><x-home.icon name="shield-check" /></span>
                                    <div>
                                        <strong>آماده‌سازی دستگاه</strong>
                                        <p>سیم‌کارت شخصی را خارج کنید و فقط لوازم ثبت‌شده را همراه دستگاه تحویل پیک دهید.</p>
                                    </div>
                                </li>
                                <li>
                                    <span class="repair-trust-list__icon" aria-hidden="true"><x-home.icon name="phone" /></span>
                                    <div>
                                        <strong>هماهنگی تلفنی پیش از اعزام</strong>
                                        <p>پیک نوین‌پی پیش از مراجعه با شماره ثبت‌شده هماهنگ می‌کند.</p>
                                    </div>
                                </li>
                            </ul>
                        @else
                            <div class="repair-confirm-info-card">
                                <ol class="repair-confirm-steps" style="margin-top:0;">
                                    <li>کد پیگیری را یادداشت یا کپی کنید.</li>
                                    <li>دستگاه را به همراه لوازم انتخاب‌شده حضوری تحویل دهید یا پس از هماهنگی ارسال کنید.</li>
                                    <li>پس از عیب‌یابی فنی، هزینه و زمان تعمیر با پیامک به شما اعلام می‌شود.</li>
                                </ol>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="repair-split-trust__footer">
                    <span>سوالی دربارهٔ تحویل دارید؟</span>
                    <a href="{{ config('business.phone_uri') }}" dir="ltr">{{ \App\Support\FaNumber::digits(config('business.phone')) }}</a>
                </div>
            </aside>
        </div>
    </div>
</div>
</div>
@endsection
