@extends('layouts.app')
@section('title', 'بازیابی کد پیگیری')
@section('content')
<div class="np-container public-section repair-track-page">
    <div class="repair-track-shell">
        <header class="store-page-intro">
            <h1>بازیابی کد پیگیری تعمیر</h1>
            <p>
                @if ($verified)
                    کدهای پیگیری ثبت‌شده برای شماره موبایل شما به ترتیب جدیدترین درخواست نمایش داده شده است.
                @elseif ($challenge)
                    کد شش‌رقمی ارسال‌شده به شماره موبایل خود را وارد کنید.
                @else
                    شماره موبایلی که با آن درخواست تعمیر ثبت کرده‌اید را وارد کنید تا کد تأیید پیامکی ارسال شود.
                @endif
            </p>
        </header>
        <section class="public-panel">
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
                                    <span class="repair-recovery-item__cta">مشاهده وضعیت <x-home.icon name="arrow-left" /></span>
                                </a>
                            </li>
                        @empty
                            <li class="repair-recovery-empty">درخواستی برای این شماره موبایل ثبت نشده است.</li>
                        @endforelse
                    </ul>
                </div>
            @elseif ($challenge)
                <div class="repair-recovery-phone-bar">
                    <span>ارسال کد به شماره <bdi dir="ltr">{{ $maskedPhone ?: 'ثبت‌شده' }}</bdi></span>
                    <form method="post" action="{{ route('repair.track.recovery.reset') }}">
                        @csrf
                        <button type="submit" class="public-inline-link repair-recovery-edit-btn">اصلاح شماره موبایل</button>
                    </form>
                </div>
                <form class="public-form" method="post" action="{{ route('repair.track.recovery.verify') }}">
                    @csrf
                    <x-repair-field name="code" label="کد تأیید پیامکی">
                        <input class="input public-code-input repair-recovery-code-input" id="code" name="code" dir="ltr" inputmode="numeric" autocomplete="one-time-code" placeholder="••••••" minlength="6" maxlength="6" autofocus required>
                    </x-repair-field>
                    <div class="repair-entry-actions repair-track-actions">
                        <button type="submit" class="np-button np-button--primary">تأیید و نمایش کدهای پیگیری <x-home.icon name="arrow-left" /></button>
                    </div>
                </form>
                <form class="repair-recovery-resend" method="post" action="{{ route('repair.track.recovery.send') }}">
                    @csrf
                    <button type="submit" class="public-inline-link repair-recovery-resend__btn" data-recovery-resend-btn data-resend-at="{{ $resendAt ?? 0 }}">ارسال مجدد کد</button>
                    <span class="repair-recovery-resend__timer" data-recovery-resend-timer data-resend-at="{{ $resendAt ?? 0 }}" aria-live="polite"></span>
                </form>
            @else
                <form class="public-form" method="post" action="{{ route('repair.track.recovery.send') }}">
                    @csrf
                    <x-repair-field name="contact_phone" label="شماره موبایل">
                        <input class="input" id="contact_phone" name="contact_phone" type="tel" dir="ltr" inputmode="tel" maxlength="40" autocomplete="tel" placeholder="۰۹۱۲۳۴۵۶۷۸۹" value="{{ old('contact_phone', '') }}" required>
                    </x-repair-field>
                    <div class="repair-entry-actions repair-track-actions">
                        <button type="submit" class="np-button np-button--primary">دریافت کد تأیید <x-home.icon name="arrow-left" /></button>
                    </div>
                </form>
            @endif
            <p class="repair-recovery-back"><a class="public-inline-link" href="{{ route('repair.track') }}">بازگشت به پیگیری تعمیر</a></p>
        </section>
    </div>
</div>
@endsection
