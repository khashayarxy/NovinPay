@extends('layouts.app')
@section('title', 'بازیابی کد پیگیری')
@section('content')
<div class="np-container public-section repair-track-page">
    <div class="repair-track-shell">
        <section class="public-panel repair-recovery-panel" aria-labelledby="recovery-title">
            <header class="store-page-intro repair-recovery-intro">
                <h1 id="recovery-title">بازیابی کد پیگیری تعمیر</h1>
                <p>
                    @if ($verified)
                        کدهای پیگیری ثبت‌شده برای شماره موبایل شما به ترتیب جدیدترین درخواست نمایش داده شده است.
                    @elseif ($challenge)
                        کد شش‌رقمی ارسال‌شده به شماره موبایل خود را وارد کنید.
                    @else
                        شماره موبایل ثبت‌شده را وارد کنید تا کد تأیید پیامک شود.
                    @endif
                </p>
            </header>
            <x-form-errors />
            @if ($verified)
                <div class="repair-recovery-results">
                    <h2 class="repair-recovery-results__title">کدهای پیگیری شما</h2>
                    <ul class="repair-recovery-list">
                        @forelse ($recoveredItems as $item)
                            <li>
                                <a class="repair-recovery-item" href="{{ route('repair.track.recovered', ['reference' => $item['code']]) }}">
                                    <span class="repair-recovery-item__main">
                                        <span class="repair-recovery-item__code"><bdi dir="ltr">{{ \App\Support\FaNumber::digits($item['code']) }}</bdi></span>
                                        <span class="repair-recovery-item__meta">
                                            <span class="repair-recovery-item__device">
                                                @if ($item['isLatinDevice'])<bdi class="np-latin-code" dir="ltr">{{ $item['device'] }}</bdi>@else{{ $item['device'] }}@endif
                                            </span>
                                            <span aria-hidden="true">·</span>
                                            <span class="repair-recovery-item__date">{{ $item['date'] }}</span>
                                        </span>
                                    </span>
                                    <span class="repair-recovery-item__cta">مشاهده وضعیت <x-home.icon name="chevron-left" /></span>
                                </a>
                            </li>
                        @empty
                            <li class="repair-recovery-empty">درخواستی برای این شماره موبایل ثبت نشده است.</li>
                        @endforelse
                    </ul>
                    <form method="post" action="{{ route('repair.track.recovery.reset') }}" style="margin-top: 16px;">
                        @csrf
                        <button type="submit" class="np-button np-button--outline">بازیابی با شماره دیگر</button>
                    </form>
                </div>
            @elseif ($challenge)
                <div class="repair-recovery-phone-bar">
                    <span>ارسال کد به شماره <bdi dir="ltr">{{ $maskedPhone ?: 'ثبت‌شده' }}</bdi></span>
                    <form method="post" action="{{ route('repair.track.recovery.reset') }}">
                        @csrf
                        <button type="submit" class="public-inline-link repair-recovery-edit-btn">اصلاح شماره موبایل</button>
                    </form>
                </div>
                @if (app()->isLocal() && ! empty($localDevOtp))
                    <p class="public-field-hint" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:8px 12px;margin-block:10px;" data-local-dev-otp>
                        کد تأیید لوکال (فقط محیط توسعه): <strong class="np-latin" dir="ltr">{{ $localDevOtp }}</strong>
                    </p>
                @endif
                <form class="public-form" method="post" action="{{ route('repair.track.recovery.verify') }}" data-public-submit-loading>
                    @csrf
                    <x-repair-field name="code" label="کد تأیید پیامکی">
                        <input class="input public-code-input repair-recovery-code-input" id="code" name="code" dir="ltr" inputmode="numeric" data-digits-only="6" autocomplete="one-time-code" placeholder="••••••" minlength="6" maxlength="6" autofocus required>
                    </x-repair-field>
                    <div class="repair-entry-actions repair-track-actions repair-recovery-actions">
                        <button type="submit" class="np-button np-button--primary repair-recovery-submit" data-loading-label="در حال ارسال...">تأیید و نمایش کدهای پیگیری</button>
                    </div>
                </form>
                <form class="repair-recovery-resend" method="post" action="{{ route('repair.track.recovery.send') }}">
                    @csrf
                    <button type="submit" class="public-inline-link repair-recovery-resend__btn" data-recovery-resend-btn data-resend-at="{{ $resendAt ?? 0 }}">ارسال مجدد کد</button>
                    <span class="repair-recovery-resend__timer" data-recovery-resend-timer data-resend-at="{{ $resendAt ?? 0 }}" aria-live="polite"></span>
                </form>
            @else
                <form class="public-form" method="post" action="{{ route('repair.track.recovery.send') }}" data-public-submit-loading>
                    @csrf
                    <x-repair-field name="contact_phone" label="شماره موبایل">
                        <input class="input" id="contact_phone" name="contact_phone" type="tel" dir="ltr" inputmode="numeric" data-digits-only="11" maxlength="11" autocomplete="tel" placeholder="۰۹۱۲۳۴۵۶۷۸۹" value="{{ old('contact_phone', '') }}" required>
                    </x-repair-field>
                    <div class="repair-entry-actions repair-track-actions repair-recovery-actions">
                        <button type="submit" class="np-button np-button--primary repair-recovery-submit" data-loading-label="در حال ارسال...">دریافت کد تأیید</button>
                    </div>
                </form>
            @endif
            <p class="repair-recovery-back"><a class="public-inline-link" href="{{ route('repair.track') }}">بازگشت به پیگیری تعمیر</a></p>
        </section>
    </div>
</div>
@endsection
