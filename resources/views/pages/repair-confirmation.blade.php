@extends('layouts.app')
@section('title', 'تأیید ثبت درخواست تعمیر')
@section('content')
@php
    $handover = session('repair_handover');
    $confirmationSteps = [
        ['label' => 'ثبت درخواست', 'state' => 'complete', 'at' => null],
        ['label' => 'دریافت دستگاه', 'state' => 'current', 'note' => 'دستگاه هنوز تحویل نشده است', 'at' => null],
        ['label' => 'بررسی و اعلام هزینه', 'state' => 'upcoming', 'at' => null],
        ['label' => 'تعمیر و تحویل', 'state' => 'upcoming', 'at' => null],
    ];
@endphp
<div class="np-container public-section repair-confirm-page" data-repair-confirmed>
    <section class="public-panel public-confirmation repair-confirm-card" aria-labelledby="confirmation-title">
        <div class="repair-confirm-stepper">
            <x-account.timeline :steps="$confirmationSteps" orientation="horizontal" />
        </div>
        <h1 id="confirmation-title">درخواست تعمیر شما ثبت شد</h1>
        <p class="repair-confirm-lead">مرحلهٔ اول انجام شد؛ اکنون در انتظار دریافت دستگاه شما هستیم. کد پیگیری زیر را نگه دارید تا در تمام مراحل وضعیت تعمیر را ببینید.</p>
        <div class="repair-confirmation-code">
            <span class="repair-confirmation-code__label">کد پیگیری</span>
            <p class="public-reference" id="repair-reference-code" dir="ltr">{{ $reference }}</p>
            <button type="button" class="repair-confirmation-code__copy" data-copy-account="repair-reference-code" aria-describedby="repair-copy-status" hidden>کپی کد پیگیری</button>
            <p class="repair-confirmation-code__status" id="repair-copy-status" role="status"></p>
        </div>
        <div class="public-notice repair-confirm-notice">
            <h2>گام بعدی برای تحویل دستگاه</h2>
            @if ($handover === 'self_ship')
                <p>لطفاً دستگاه را با بسته‌بندی ایمن به نشانی زیر ارسال کنید و کد پیگیری را داخل بسته قرار دهید:</p>
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
                        <dd dir="ltr">۳۳۵۱۶۱۱۸۲۸</dd>
                    </div>
                </dl>
                <div class="repair-shipping-copy-row">
                    <button type="button"
                            class="btn btn-secondary btn-sm repair-shipping-copy-btn"
                            data-copy-shipping
                            data-copy-text="آدرس گیرنده: {{ config('business.address') }}&#10;شماره موبایل: {{ \App\Support\FaNumber::digits(config('business.phone')) }}&#10;کد پستی: ۳۳۵۱۶۱۱۸۲۸"
                            aria-describedby="confirm-shipping-copy-status">کپی اطلاعات ارسال</button>
                    <span id="confirm-shipping-copy-status" class="repair-shipping-copy-status" role="status" aria-live="polite"></span>
                </div>
                <p class="repair-insurance-alert">هنگام ارسال با پست یا تیپاکس، حتماً گزینهٔ <strong>«بیمهٔ مرسوله»</strong> را فعال کنید.</p>
            @elseif ($handover === 'in_person')
                <p>لطفاً دستگاه را همراه با کد پیگیری و لوازم انتخاب‌شده به فروشگاه نوین‌پی تحویل دهید:</p>
                <p class="repair-office-address">{{ config('business.address') }}</p>
                <p class="repair-office-hours">ساعات کاری: <x-business-hours /></p>
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
            @elseif ($handover === 'doorstep_pickup')
                <p>درخواست دریافت با پیک نوین‌پی ثبت شده است. همکاران ما برای هماهنگی زمان دقیق مراجعهٔ پیک با شماره تماس شما ارتباط می‌گیرند.</p>
            @else
                <ol class="repair-confirm-steps">
                    <li>کد پیگیری را یادداشت یا کپی کنید.</li>
                    <li>دستگاه را به همراه لوازم انتخاب‌شده حضوری تحویل دهید یا پس از هماهنگی ارسال کنید.</li>
                    <li>پس از عیب‌یابی فنی، هزینه و زمان تعمیر با پیامک به شما اعلام می‌شود.</li>
                </ol>
            @endif
        </div>
        <div class="public-actions repair-confirm-actions">
            <a href="{{ route('repair.track') }}" class="np-button np-button--primary">پیگیری وضعیت <x-home.icon name="arrow-left" /></a>
            <a href="{{ route('account.repairs.request', $reference) }}" class="np-button np-button--outline">تغییر نحوهٔ تحویل</a>
            <a href="{{ route('account.index', ['section' => 'repairs']) }}" class="np-button np-button--outline">تعمیرات در حساب کاربری</a>
            <a href="{{ config('business.phone_uri') }}" class="np-button np-button--outline">تماس با نوین‌پی</a>
        </div>
    </section>
</div>
@endsection
