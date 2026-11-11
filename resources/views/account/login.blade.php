@extends('layouts.app')
@section('title', 'ورود و ثبت‌نام')
@section('quiet-shell', 'true')
@section('content')
@php
    $passwordMode = $phone && request()->query('method') === 'password';
    $recovery = $phone && request()->query('method') === 'recovery';
    $heading = match (true) {
        $passwordMode => 'ورود با رمز عبور',
        $recovery => 'بازیابی رمز عبور',
        (bool) $phone => 'تأیید شماره موبایل',
        default => 'به نوین‌پی خوش آمدید',
    };
@endphp
<div class="np-auth">
    <section class="np-auth__card" aria-labelledby="auth-title">
        <a href="{{ route('home') }}" class="np-auth__logo" aria-label="صفحه اصلی نوین‌پی"><x-home.wordmark /></a>
        <div class="np-auth__progress" aria-hidden="true"><span></span><span @class(['is-current' => $phone])></span></div>
        <div class="np-account-enter">
            <h1 id="auth-title">{{ $heading }}</h1>
            @if($phone)
                <div class="np-auth__number">
                    <bdi dir="ltr">{{ \App\Support\FaNumber::digits(substr($phone, 0, 4).' ••• '.substr($phone, -4)) }}</bdi>
                    <form method="post" action="{{ route('account.restart') }}">@csrf<button class="np-account-link" type="submit">تغییر شماره</button></form>
                </div>
            @else
                <p>برای ورود یا ساخت حساب، شماره موبایل خود را وارد کنید.</p>
            @endif
            <x-admin.errors />
            @if(session('success') && !$passwordMode)<p class="np-account-notice" role="status">{{ session('success') }}</p>@endif
            @if(!$phone)
                <form class="np-account-form" method="post" action="{{ route('account.start') }}">
                    @csrf
                    <label for="account-phone">شماره موبایل</label>
                    <input id="account-phone" type="tel" name="phone" dir="ltr" autocomplete="tel" inputmode="tel" maxlength="40" placeholder="۰۹۱۲۳۴۵۶۷۸۹" required>
                    <button class="np-account-primary">ادامه</button>
                </form>
                <p class="np-auth__legal">با ادامه، <a href="{{ route('terms') }}">شرایط استفاده</a> و <a href="{{ route('privacy') }}">حریم خصوصی</a> را می‌پذیرید.</p>
            @elseif($passwordMode)
                <form class="np-account-form" method="post" action="{{ route('account.password.login') }}">
                    @csrf
                    <label for="account-password">رمز عبور</label>
                    <x-account.password id="account-password" name="password" autocomplete="current-password" />
                    <button class="np-account-primary">ورود</button>
                </form>
                <div class="np-auth__alternatives">
                    <a class="np-account-link" href="{{ route('account.login') }}">ورود با کد تأیید</a>
                    <a class="np-account-link" href="{{ route('account.login', ['method' => 'recovery']) }}">رمز عبور را فراموش کرده‌ام</a>
                </div>
            @else
                @if($challenge)
                    <form class="np-account-form" method="post" action="{{ route('account.verify') }}" data-account-otp>
                        @csrf
                        @if($recovery)<input type="hidden" name="recover" value="1">@endif
                        <label for="account-code">کد تأیید شش‌رقمی</label>
                        <div class="np-otp" dir="ltr" @if($errors->has('code')) data-otp-error @endif>
                            <input id="account-code" class="np-auth__code" name="code" dir="ltr" inputmode="numeric" autocomplete="one-time-code" minlength="6" maxlength="6" aria-describedby="code-expiry account-otp-error" @if($errors->has('code')) aria-invalid="true" @endif required>
                            <div class="np-otp__slots" aria-hidden="true" hidden>
                                @for($slot = 0; $slot < 6; $slot++)<span></span>@endfor
                            </div>
                        </div>
                        <small id="code-expiry">کد تا دو دقیقه معتبر است.</small>
                        <p id="account-otp-error" class="np-account-otp-error" role="alert" hidden></p>
                        <button class="np-account-primary">{{ $recovery ? 'تأیید و انتخاب رمز' : 'تأیید و ورود' }}</button>
                        <small role="status" data-otp-pending hidden>در حال بررسی کد…</small>
                    </form>
                @else
                    <p>درخواست کد تأیید بدهید یا با رمز عبور وارد شوید.</p>
                @endif
                <div class="np-auth__alternatives">
                    <form method="post" action="{{ route('account.otp') }}">
                        @csrf
                        @if($recovery)<input type="hidden" name="recover" value="1">@endif
                        <button type="submit" class="np-account-link" data-account-resend="{{ max(0, $resendAt - now()->timestamp) }}">{{ $challenge ? 'درخواست کد جدید' : 'درخواست کد تأیید' }}</button>
                    </form>
                    <a class="np-account-link" href="{{ route('account.login', ['method' => 'password']) }}">ورود با رمز عبور</a>
                </div>
            @endif
        </div>
    </section>
    <aside class="np-auth__aside" aria-label="امکانات حساب کاربری">
        <div class="np-auth__signal" aria-hidden="true">
            <svg viewBox="0 0 320 208" fill="none">
                <path d="M22 143h60l18-33h38l20-43h52l20-32h68" stroke="#90c4ff" stroke-width="2" stroke-linecap="round" stroke-dasharray="4 8"/>
                <rect x="84" y="38" width="152" height="130" rx="23" fill="#174482" stroke="#79aeff"/>
                <rect x="105" y="61" width="110" height="48" rx="10" fill="#102f66" stroke="#547fbc"/>
                <path d="M124 85h19l9-12 11 21 10-9h23" stroke="#9fd6ff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M118 132h27m11 0h10m11 0h25" stroke="#b0d5ff" stroke-width="4" stroke-linecap="round"/>
                <path d="M22 143h40m196-108h40" stroke="#a1d6ff" stroke-width="2" stroke-linecap="round"/>
                <circle cx="22" cy="143" r="5" fill="#b8e4ff"/><circle cx="298" cy="35" r="5" fill="#b8e4ff"/>
            </svg>
        </div>
        <div>
            <h2>از خرید تا تحویل، همراه شما</h2>
            <p>وضعیت تعمیر، سفارش‌ها و رسیدهای خود را در یک حساب پیگیری کنید.</p>
        </div>
    </aside>
</div>
@if($phone && !$passwordMode)
    <script src="{{ asset('js/customer-otp.js?v=2') }}" defer></script>
@endif
@endsection
