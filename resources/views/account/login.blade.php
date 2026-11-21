@extends('layouts.app')
@section('title', 'ورود و ثبت‌نام')
@section('quiet-shell', 'true')
@section('content')
@php
    $passwordMode = $phone && request()->query('method') === 'password';
    $recovery = $phone && request()->query('method') === 'recovery';
    $wait = fn (string $action, int $also = 0) => max(0, max($retryUntil[$action] ?? 0, $also) - now()->timestamp);
    $heading = match (true) {
        $passwordMode => 'ورود با رمز عبور',
        $recovery => 'بازیابی رمز عبور',
        (bool) $phone => 'تأیید شماره موبایل',
        default => 'ورود یا ثبت‌نام',
    };
@endphp
<div class="np-auth">
    <section class="np-auth__card" aria-labelledby="auth-title">
        <a href="{{ route('home') }}" class="np-auth__logo" aria-label="صفحه اصلی نوین‌پی"><x-home.wordmark /></a>
        <div class="np-account-enter">
            <h1 id="auth-title">{{ $heading }}</h1>
            @if($phone)
                <div class="np-auth__number">
                    <bdi dir="ltr">{{ \App\Support\FaNumber::digits(substr($phone, 0, 4).' ••• '.substr($phone, -4)) }}</bdi>
                    <form method="post" action="{{ route('account.restart') }}">@csrf<input type="hidden" name="flow" value="{{ $flow }}"><button class="np-account-link" type="submit">تغییر شماره</button></form>
                </div>
            @endif
            <x-admin.errors />
            @unless($errors->any())
                @foreach($passwordMode ? ['password'] : ($phone ? ['otp', 'verify'] : ['otp']) as $action)
                    @if(isset($retryReason[$action]) && $wait($action) > 0)
                        <p class="np-account-otp-error" role="status">{{ \App\Support\CustomerAuthThrottle::message($retryReason[$action], $wait($action)) }}</p>
                    @endif
                @endforeach
            @endunless
            @if(session('success') && !$passwordMode)<p class="np-account-notice" role="status">{{ session('success') }}</p>@endif
            @if(!$phone)
                <form class="np-account-form" method="post" action="{{ route('account.start') }}" data-account-submit data-account-send data-login-state="{{ route('account.login.state') }}">
                    @csrf
                    <input type="hidden" name="flow" value="{{ $flow }}">
                    <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <p class="np-account-notice" role="status" data-send-status hidden></p>
                    <label for="account-phone">شماره موبایل</label>
                    <input id="account-phone" type="tel" name="phone" dir="ltr" autocomplete="tel" inputmode="tel" enterkeyhint="go" maxlength="40" placeholder="۰۹۱۲۳۴۵۶۷۸۹" required>
                    <button type="submit" class="np-account-primary" data-account-wait="{{ $wait('otp') }}">ادامه</button>
                </form>
                <p class="np-auth__legal">با ادامه، <a href="{{ route('terms') }}">شرایط استفاده</a> و <a href="{{ route('privacy') }}">حریم خصوصی</a> را می‌پذیرید.</p>
            @elseif($passwordMode)
                <form class="np-account-form" method="post" action="{{ route('account.password.login') }}" data-account-submit>
                    @csrf
                    <input type="hidden" name="flow" value="{{ $flow }}">
                    <label for="account-password">رمز عبور</label>
                    <x-account.password id="account-password" name="password" autocomplete="current-password" enterkeyhint="go" />
                    <button type="submit" class="np-account-primary" data-account-wait="{{ $wait('password') }}">ورود</button>
                </form>
                <div class="np-auth__alternatives">
                    <a class="np-account-link" href="{{ route('account.login', ['flow' => $flow]) }}">ورود با کد تأیید</a>
                    <a class="np-account-link" href="{{ route('account.login', ['flow' => $flow, 'method' => 'recovery']) }}">رمز عبور را فراموش کرده‌ام</a>
                </div>
            @else
                @if($challenge)
                    <form class="np-account-form" method="post" action="{{ route('account.verify') }}" data-account-otp data-login-state="{{ route('account.login.state') }}" data-otp-expires="{{ $expiresAt }}" data-server-now="{{ now()->timestamp }}">
                        @csrf
                        <input type="hidden" name="flow" value="{{ $flow }}">
                        <input type="hidden" name="challenge" value="{{ $challengeId }}">
                        @if($recovery)<input type="hidden" name="recover" value="1">@endif
                        <label for="account-code">کد تأیید شش‌رقمی</label>
                        <div class="np-otp" dir="ltr" @if($errors->has('code')) data-otp-error @endif>
                            <input id="account-code" class="np-auth__code" name="code" dir="ltr" inputmode="numeric" enterkeyhint="go" autocomplete="one-time-code" minlength="6" maxlength="6" aria-describedby="code-expiry account-otp-error" @if($errors->has('code')) aria-invalid="true" @endif required>
                            <div class="np-otp__slots" aria-hidden="true" hidden>
                                @for($slot = 0; $slot < 6; $slot++)<span></span>@endfor
                            </div>
                        </div>
                        <small id="code-expiry">کد تا دو دقیقه معتبر است.</small>
                        <p id="account-otp-error" class="np-account-otp-error" role="alert" hidden></p>
                        <button type="submit" class="np-account-primary" data-verify-wait="{{ $wait('verify') }}">{{ $recovery ? 'تأیید و انتخاب رمز' : 'تأیید و ورود' }}</button>
                        <small role="status" data-otp-pending hidden>در حال بررسی کد…</small>
                    </form>
                @elseif($challengeEnded)
                    <p>کد قبلی دیگر معتبر نیست. کد جدید درخواست کنید.</p>
                @else
                    <p>درخواست کد تأیید بدهید یا با رمز عبور وارد شوید.</p>
                @endif
                <div class="np-auth__alternatives">
                    <form method="post" action="{{ route('account.otp') }}" data-account-send data-login-state="{{ route('account.login.state') }}">
                        @csrf
                        <input type="hidden" name="flow" value="{{ $flow }}">
                        <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <p class="np-account-notice" role="status" data-send-status hidden></p>
                        @if($recovery)<input type="hidden" name="recover" value="1">@endif
                        <button type="submit" class="np-account-link" data-account-wait="{{ $wait('otp', $resendAt) }}">{{ $challenge ? 'درخواست کد جدید' : 'درخواست کد تأیید' }}</button>
                    </form>
                    <a class="np-account-link" href="{{ route('account.login', ['flow' => $flow, 'method' => 'password']) }}">ورود با رمز عبور</a>
                </div>
            @endif
        </div>
    </section>
</div>
<script src="{{ asset('js/customer-otp.js?v=6') }}" defer></script>
@endsection
