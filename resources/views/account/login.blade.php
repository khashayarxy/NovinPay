@extends('layouts.app')
@section('title', 'ورود و ثبت‌نام')
@section('quiet-shell', 'true')
@section('content')
@php
    $channel = $channel ?? 'phone';
    $emailAuthEnabled = $emailAuthEnabled ?? true;
    $googleEnabled = $googleEnabled ?? false;
    $loginEmail = $loginEmail ?? null;
    $emailChallenge = $emailChallenge ?? false;
    $emailChallengeId = $emailChallengeId ?? null;
    $emailPasswordMode = $channel === 'email' && request()->query('method') === 'password';
    $passwordMode = $channel === 'phone' && $phone && request()->query('method') === 'password';
    $recovery = $channel === 'phone' && $phone && request()->query('method') === 'recovery';
    $wait = fn (string $action, int $also = 0) => max(0, max($retryUntil[$action] ?? 0, $also) - now()->timestamp);
    $heading = match (true) {
        $emailPasswordMode => 'ورود با ایمیل و رمز عبور',
        $channel === 'email' && $loginEmail => 'تأیید نشانی ایمیل',
        $passwordMode => 'ورود با رمز عبور',
        $recovery => 'بازیابی رمز عبور',
        $channel === 'phone' && (bool) $phone => 'تأیید شماره موبایل',
        default => 'ورود یا ثبت‌نام',
    };
@endphp
<div class="np-auth">
    <section class="np-auth__card" aria-labelledby="auth-title">
        <a href="{{ route('home') }}" class="np-auth__logo" aria-label="صفحه اصلی نوین‌پی"><x-home.wordmark /></a>
        <div class="np-account-enter">
            <h1 id="auth-title">{{ $heading }}</h1>

            @if($emailAuthEnabled && !$phone && !$loginEmail)
                <div class="np-auth__tabs" role="tablist" aria-label="روش ورود">
                    <a role="tab" aria-selected="{{ $channel === 'phone' ? 'true' : 'false' }}" @class(['np-auth__tab', 'is-active' => $channel === 'phone']) href="{{ route('account.login') }}">شماره موبایل</a>
                    <a role="tab" aria-selected="{{ $channel === 'email' ? 'true' : 'false' }}" @class(['np-auth__tab', 'is-active' => $channel === 'email']) href="{{ route('account.login', ['channel' => 'email']) }}">ایمیل</a>
                </div>
            @endif

            @if($channel === 'phone' && $phone)
                <div class="np-auth__number">
                    <bdi dir="ltr">{{ \App\Support\FaNumber::digits(substr($phone, 0, 4).' ••• '.substr($phone, -4)) }}</bdi>
                    <form method="post" action="{{ route('account.restart') }}">@csrf<input type="hidden" name="flow" value="{{ $flow }}"><button class="np-account-link" type="submit">تغییر شماره</button></form>
                </div>
            @elseif($channel === 'email' && $loginEmail)
                <div class="np-auth__number">
                    <bdi dir="ltr">{{ $loginEmail }}</bdi>
                    <form method="post" action="{{ route('account.email.restart') }}">@csrf<button class="np-account-link" type="submit">تغییر ایمیل</button></form>
                </div>
            @endif

            <x-admin.errors />
            @unless($errors->any())
                @if($channel === 'phone')
                    @foreach($passwordMode ? ['password'] : ($phone ? ['otp', 'verify'] : ['otp']) as $action)
                        @if(isset($retryReason[$action]) && $wait($action) > 0)
                            <p class="np-account-otp-error" role="status">{{ \App\Support\CustomerAuthThrottle::message($retryReason[$action], $wait($action)) }}</p>
                        @endif
                    @endforeach
                @endif
            @endunless
            @if(session('success') && !$passwordMode && !$emailPasswordMode)<p class="np-account-notice" role="status">{{ session('success') }}</p>@endif

            @if($channel === 'email')
                @if($emailPasswordMode)
                    <form class="np-account-form" method="post" action="{{ route('account.email.password.login') }}">
                        @csrf
                        @unless($loginEmail)
                            <label for="account-email-pw">نشانی ایمیل</label>
                            <input id="account-email-pw" type="email" name="email" dir="ltr" autocomplete="email" inputmode="email" maxlength="254" placeholder="name@example.com" required>
                        @endunless
                        <label for="account-email-password">رمز عبور</label>
                        <x-account.password id="account-email-password" name="password" autocomplete="current-password" enterkeyhint="go" />
                        <button type="submit" class="np-account-primary">ورود</button>
                    </form>
                    <div class="np-auth__alternatives">
                        <a class="np-account-link" href="{{ route('account.login', ['channel' => 'email']) }}">ورود با کد تأیید ایمیلی</a>
                    </div>
                @elseif(!$loginEmail)
                    <form class="np-account-form" method="post" action="{{ route('account.email.start') }}">
                        @csrf
                        <label for="account-email">نشانی ایمیل</label>
                        <input id="account-email" type="email" name="email" dir="ltr" autocomplete="email" inputmode="email" enterkeyhint="go" maxlength="254" placeholder="name@example.com" required>
                        <button type="submit" class="np-account-primary">ارسال کد تأیید</button>
                    </form>
                    <div class="np-auth__alternatives">
                        <a class="np-account-link" href="{{ route('account.login', ['channel' => 'email', 'method' => 'password']) }}">ورود با رمز عبور</a>
                    </div>
                @else
                    @if($emailChallenge)
                        <form class="np-account-form" method="post" action="{{ route('account.email.verify') }}">
                            @csrf
                            <input type="hidden" name="challenge" value="{{ $emailChallengeId }}">
                            <label for="account-email-code">کد تأیید شش‌رقمی</label>
                            <input id="account-email-code" class="np-auth__code" name="code" dir="ltr" inputmode="numeric" enterkeyhint="go" autocomplete="one-time-code" minlength="6" maxlength="6" placeholder="۱۲۳۴۵۶" required>
                            <small>کد ارسال‌شده به ایمیل تا ۵ دقیقه معتبر است.</small>
                            <button type="submit" class="np-account-primary">تأیید و ورود</button>
                        </form>
                    @else
                        <p>کد قبلی منقضی شده است. کد جدید درخواست کنید.</p>
                    @endif
                    <div class="np-auth__alternatives">
                        <form method="post" action="{{ route('account.email.otp') }}">
                            @csrf
                            <button type="submit" class="np-account-link">ارسال مجدد کد</button>
                        </form>
                        <a class="np-account-link" href="{{ route('account.login', ['channel' => 'email', 'method' => 'password']) }}">ورود با رمز عبور</a>
                    </div>
                @endif
            @else
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
            @endif

            @if($googleEnabled && !$phone && !$loginEmail)
                <div class="np-auth__divider" aria-hidden="true"><span>یا</span></div>
                <a class="np-auth__google" href="{{ route('account.google.redirect') }}">
                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z"/><path fill="#FBBC05" d="M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62H1.29C.47 8.24 0 10.06 0 12s.47 3.76 1.29 5.38l3.98-3.09z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09c.95-2.85 3.6-4.96 6.73-4.96z"/></svg>
                    <span>ورود با حساب گوگل</span>
                </a>
            @endif

            @if(!$phone && !$loginEmail)
                <p class="np-auth__legal">با ادامه، <a href="{{ route('terms') }}">شرایط استفاده</a> و <a href="{{ route('privacy') }}">حریم خصوصی</a> را می‌پذیرید.</p>
            @endif
        </div>
    </section>
</div>
<script src="{{ asset('js/customer-otp.js?v=6') }}" defer></script>
@endsection
