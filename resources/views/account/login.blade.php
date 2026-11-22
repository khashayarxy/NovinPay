@extends('layouts.app')
@section('title', 'ورود و ثبت‌نام')
@section('quiet-shell', 'true')
@section('content')
@php
    $channel = $channel ?? 'phone';
    $loginEmail = $loginEmail ?? null;
    $emailChallenge = $emailChallenge ?? false;
    $emailChallengeId = $emailChallengeId ?? null;
    $emailResendAt = $emailResendAt ?? 0;
    $forgotMode = request()->query('method') === 'forgot';
    $passwordMode = !$forgotMode && request()->query('method') === 'password';
    $recovery = $channel === 'phone' && $phone && request()->query('method') === 'recovery';
    $inEmailOtp = !$forgotMode && !$passwordMode && $channel === 'email' && (bool) $loginEmail;
    $inPhoneOtp = !$forgotMode && !$passwordMode && $channel === 'phone' && (bool) $phone;
    $inStepOne = !$forgotMode && !$passwordMode && !$inEmailOtp && !$inPhoneOtp;
    $wait = fn (string $action, int $also = 0) => max(0, max($retryUntil[$action] ?? 0, $also) - now()->timestamp);
    $heading = match (true) {
        $forgotMode => 'فراموشی رمز عبور',
        $passwordMode => 'ورود با رمز عبور',
        $recovery => 'بازیابی رمز عبور',
        $inEmailOtp => 'تأیید نشانی ایمیل',
        $inPhoneOtp => 'تأیید شماره موبایل',
        default => 'ورود یا ثبت‌نام',
    };
    $subtitle = match (true) {
        $forgotMode => 'برای انتخاب رمز عبور جدید، شماره موبایل یا ایمیل خود را وارد کنید.',
        $recovery => 'کد شش‌رقمی ارسال‌شده را برای انتخاب رمز جدید وارد کنید.',
        $inEmailOtp => 'کد شش‌رقمی ارسال‌شده به نشانی ایمیل زیر را وارد کنید.',
        $inPhoneOtp => 'کد شش‌رقمی ارسال‌شده به شماره موبایل زیر را وارد کنید.',
        default => null,
    };
@endphp
<div class="np-auth-shell" data-auth-shell>
    <div class="np-auth-backdrop" aria-hidden="true"></div>
    <div class="np-auth-split">
        <div class="np-auth-form-pane">
            <div class="np-auth">
                <section class="np-auth__card" aria-labelledby="auth-title">
                    <a href="{{ route('home') }}" class="np-auth__logo" aria-label="صفحه اصلی نوین‌پی"><x-home.wordmark /></a>
                    <div class="np-account-enter">
                        <h1 id="auth-title">{{ $heading }}</h1>
                        @if($subtitle)<p class="np-auth__subtitle">{{ $subtitle }}</p>@endif

                        @if($inPhoneOtp)
                            <div class="np-auth__destination">
                                <bdi dir="ltr">{{ \App\Support\FaNumber::digits(substr($phone, 0, 4).' ••• '.substr($phone, -4)) }}</bdi>
                                <form method="post" action="{{ route('account.restart') }}">
                                    @csrf
                                    <input type="hidden" name="flow" value="{{ $flow }}">
                                    <button class="np-auth__edit" type="submit">ویرایش</button>
                                </form>
                            </div>
                        @elseif($inEmailOtp)
                            <div class="np-auth__destination">
                                <bdi dir="ltr">{{ $loginEmail }}</bdi>
                                <form method="post" action="{{ route('account.email.restart') }}">
                                    @csrf
                                    <button class="np-auth__edit" type="submit">ویرایش</button>
                                </form>
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
                        @if(session('success') && !$passwordMode && !$forgotMode)<p class="np-account-notice" role="status">{{ session('success') }}</p>@endif

                        @if($inStepOne)
                            <form class="np-account-form" method="post" action="{{ route('account.start') }}" data-account-submit data-account-send data-login-state="{{ route('account.login.state') }}">
                                @csrf
                                <input type="hidden" name="flow" value="{{ $flow }}">
                                <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <p class="np-account-notice" role="status" data-send-status hidden></p>
                                <label for="account-phone">شماره موبایل یا ایمیل</label>
                                <input id="account-phone" type="text" name="identifier" dir="ltr" autocomplete="username" inputmode="email" enterkeyhint="go" maxlength="254" placeholder="۰۹۱۲۳۴۵۶۷۸۹ یا name@example.com" value="{{ old('identifier') }}" required>
                                <button type="submit" class="np-account-primary" data-account-wait="{{ $wait('otp') }}">ارسال کد تأیید</button>
                            </form>

                            <div class="np-auth__secondary-center">
                                <a class="np-account-link" href="{{ route('account.login', ['method' => 'password']) }}">ورود با رمز عبور</a>
                            </div>

                            <div class="np-auth__divider" aria-hidden="true"><span>یا</span></div>
                            <a class="np-auth__google" href="{{ route('account.google.redirect') }}">
                                <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z"/><path fill="#FBBC05" d="M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62H1.29C.47 8.24 0 10.06 0 12s.47 3.76 1.29 5.38l3.98-3.09z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09c.95-2.85 3.6-4.96 6.73-4.96z"/></svg>
                                <span>ورود با حساب گوگل</span>
                            </a>

                            <p class="np-auth__legal">با ادامه، <a href="{{ route('terms') }}">شرایط استفاده</a> و <a href="{{ route('privacy') }}">حریم خصوصی</a> را می‌پذیرید.</p>

                        @elseif($forgotMode)
                            <form class="np-account-form" method="post" action="{{ route('account.start') }}" data-account-submit data-account-send data-login-state="{{ route('account.login.state') }}">
                                @csrf
                                <input type="hidden" name="flow" value="{{ $flow }}">
                                <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <input type="hidden" name="recover" value="1">
                                <p class="np-account-notice" role="status" data-send-status hidden></p>
                                <label for="account-forgot-identifier">شماره موبایل یا ایمیل</label>
                                <input id="account-forgot-identifier" type="text" name="identifier" dir="ltr" autocomplete="username" inputmode="email" enterkeyhint="go" maxlength="254" placeholder="۰۹۱۲۳۴۵۶۷۸۹ یا name@example.com" value="{{ old('identifier', $phone ?? $loginEmail ?? '') }}" required>
                                <button type="submit" class="np-account-primary" data-account-wait="{{ $wait('otp') }}">ارسال کد بازیابی</button>
                            </form>
                            <div class="np-auth__back-row">
                                <a class="np-auth__back-link" href="{{ route('account.login') }}">بازگشت به صفحه ورود | ثبت‌نام</a>
                            </div>

                        @elseif($passwordMode)
                            <form class="np-account-form" method="post" action="{{ route('account.password.login') }}" data-account-submit>
                                @csrf
                                @if($phone)
                                    <input type="hidden" name="flow" value="{{ $flow }}">
                                @endif
                                <label for="account-pw-identifier">شماره موبایل یا ایمیل</label>
                                <input id="account-pw-identifier" type="text" name="identifier" dir="ltr" autocomplete="username" inputmode="email" maxlength="254" placeholder="۰۹۱۲۳۴۵۶۷۸۹ یا name@example.com" value="{{ old('identifier', $phone ?? $loginEmail ?? '') }}" required>

                                <div class="np-auth__label-row">
                                    <label for="account-password">رمز عبور</label>
                                    <a class="np-auth__forgot" href="{{ route('account.login', ['method' => 'forgot']) }}">فراموشی رمز عبور</a>
                                </div>
                                <x-account.password id="account-password" name="password" autocomplete="current-password" enterkeyhint="go" />

                                <button type="submit" class="np-account-primary" data-account-wait="{{ $wait('password') }}">ورود به پنل کاربری</button>
                            </form>
                            <div class="np-auth__secondary-center">
                                <a class="np-account-link" href="{{ $phone ? route('account.login', ['flow' => $flow]) : route('account.login') }}">ورود با رمز یکبار مصرف</a>
                            </div>

                        @elseif($inEmailOtp)
                            @if($emailChallenge)
                                <form class="np-account-form" method="post" action="{{ route('account.email.verify') }}" data-otp-box-form>
                                    @csrf
                                    <input type="hidden" name="challenge" value="{{ $emailChallengeId }}">
                                    <label for="account-email-code" class="sr-only">کد تأیید شش‌رقمی</label>
                                    <div class="np-otp-boxes is-enhanced" data-otp-boxes dir="ltr" @if($errors->has('code')) data-otp-error @endif>
                                        <input id="account-email-code" class="np-otp-boxes__hidden" name="code" dir="ltr" inputmode="numeric" enterkeyhint="go" autocomplete="one-time-code" minlength="6" maxlength="6" tabindex="-1" required>
                                        <div class="np-otp-boxes__grid" data-otp-grid>
                                            @for($slot = 0; $slot < 6; $slot++)
                                                <input type="text" inputmode="numeric" maxlength="1" class="np-otp-box" aria-label="رقم {{ \App\Support\FaNumber::digits($slot + 1) }}" autocomplete="{{ $slot === 0 ? 'one-time-code' : 'off' }}">
                                            @endfor
                                        </div>
                                    </div>
                                    <small class="np-auth__hint">کد ارسال‌شده به ایمیل تا ۵ دقیقه معتبر است.</small>
                                    <button type="submit" class="np-account-primary">ورود به پنل کاربری</button>
                                </form>
                            @else
                                <p class="np-auth__hint">کد قبلی منقضی شده است. کد جدید درخواست کنید.</p>
                            @endif
                            <div class="np-auth__footer-links">
                                <form method="post" action="{{ route('account.email.otp') }}">
                                    @csrf
                                    <button type="submit" class="np-account-link" data-account-wait="{{ max(0, $emailResendAt - now()->timestamp) }}">{{ $emailChallenge ? 'ارسال مجدد کد' : 'درخواست کد تأیید' }}</button>
                                </form>
                                <a class="np-account-link" href="{{ route('account.login', ['channel' => 'email', 'method' => 'password']) }}">ورود با رمز عبور</a>
                            </div>
                            <div class="np-auth__back-row">
                                <form method="post" action="{{ route('account.email.restart') }}">
                                    @csrf
                                    <button type="submit" class="np-auth__back-link">بازگشت به صفحه ورود | ثبت‌نام</button>
                                </form>
                            </div>

                        @else
                            @if($challenge)
                                <form class="np-account-form" method="post" action="{{ route('account.verify') }}" data-account-otp data-otp-box-form data-login-state="{{ route('account.login.state') }}" data-otp-expires="{{ $expiresAt }}" data-server-now="{{ now()->timestamp }}">
                                    @csrf
                                    <input type="hidden" name="flow" value="{{ $flow }}">
                                    <input type="hidden" name="challenge" value="{{ $challengeId }}">
                                    @if($recovery)<input type="hidden" name="recover" value="1">@endif
                                    <label for="account-code" class="sr-only">کد تأیید شش‌رقمی</label>
                                    <div class="np-otp-boxes is-enhanced" data-otp-boxes dir="ltr" @if($errors->has('code')) data-otp-error @endif>
                                        <input id="account-code" class="np-otp-boxes__hidden" name="code" dir="ltr" inputmode="numeric" enterkeyhint="go" autocomplete="one-time-code" minlength="6" maxlength="6" tabindex="-1" aria-describedby="code-expiry account-otp-error" @if($errors->has('code')) aria-invalid="true" @endif required>
                                        <div class="np-otp-boxes__grid" data-otp-grid>
                                            @for($slot = 0; $slot < 6; $slot++)
                                                <input type="text" inputmode="numeric" maxlength="1" class="np-otp-box" aria-label="رقم {{ \App\Support\FaNumber::digits($slot + 1) }}" autocomplete="{{ $slot === 0 ? 'one-time-code' : 'off' }}">
                                            @endfor
                                        </div>
                                    </div>
                                    <small id="code-expiry" class="np-auth__hint">کد تا دو دقیقه معتبر است.</small>
                                    <p id="account-otp-error" class="np-account-otp-error" role="alert" hidden></p>
                                    <button type="submit" class="np-account-primary" data-verify-wait="{{ $wait('verify') }}">{{ $recovery ? 'تأیید و انتخاب رمز' : 'ورود به پنل کاربری' }}</button>
                                    <small role="status" data-otp-pending hidden>در حال بررسی کد…</small>
                                </form>
                            @elseif($challengeEnded)
                                <p class="np-auth__hint">کد قبلی دیگر معتبر نیست. کد جدید درخواست کنید.</p>
                            @else
                                <p class="np-auth__hint">درخواست کد تأیید بدهید یا با رمز عبور وارد شوید.</p>
                            @endif
                            <div class="np-auth__footer-links">
                                <form method="post" action="{{ route('account.otp') }}" data-account-send data-login-state="{{ route('account.login.state') }}">
                                    @csrf
                                    <input type="hidden" name="flow" value="{{ $flow }}">
                                    <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                    <p class="np-account-notice" role="status" data-send-status hidden></p>
                                    @if($recovery)<input type="hidden" name="recover" value="1">@endif
                                    <button type="submit" class="np-account-link" data-account-wait="{{ $wait('otp', $resendAt) }}">{{ $challenge ? 'ارسال مجدد کد' : 'درخواست کد تأیید' }}</button>
                                </form>
                                <a class="np-account-link" href="{{ route('account.login', ['flow' => $flow, 'method' => 'password']) }}">ورود با رمز عبور</a>
                            </div>
                            <div class="np-auth__back-row">
                                <form method="post" action="{{ route('account.restart') }}">
                                    @csrf
                                    <input type="hidden" name="flow" value="{{ $flow }}">
                                    <button type="submit" class="np-auth__back-link">بازگشت به صفحه ورود | ثبت‌نام</button>
                                </form>
                            </div>
                        @endif
                    </div>
                </section>
            </div>
        </div>

        <aside class="np-auth-showcase" data-auth-showcase aria-label="امکانات سامانه نوین‌پی">
            <div class="np-auth-showcase__glow" aria-hidden="true"></div>
            <div class="np-auth-showcase__slides">
                <article class="np-auth-slide is-active" data-showcase-slide="0">
                    <div class="np-auth-slide__art" aria-hidden="true">
                        <svg viewBox="0 0 260 220" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="64" y="18" width="132" height="184" rx="22" fill="#0F274A" stroke="#60A5FA" stroke-opacity=".55" stroke-width="2"/>
                            <rect x="80" y="36" width="100" height="66" rx="12" fill="#173A6E" stroke="#93C5FD" stroke-opacity=".4"/>
                            <path d="M114 68L125 79L147 57" stroke="#34D399" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                            <rect x="84" y="118" width="24" height="14" rx="4" fill="#3B82F6" fill-opacity=".35"/>
                            <rect x="118" y="118" width="24" height="14" rx="4" fill="#3B82F6" fill-opacity=".35"/>
                            <rect x="152" y="118" width="24" height="14" rx="4" fill="#3B82F6" fill-opacity=".35"/>
                            <rect x="84" y="142" width="24" height="14" rx="4" fill="#3B82F6" fill-opacity=".28"/>
                            <rect x="118" y="142" width="24" height="14" rx="4" fill="#3B82F6" fill-opacity=".28"/>
                            <rect x="152" y="142" width="24" height="14" rx="4" fill="#3B82F6" fill-opacity=".28"/>
                            <rect x="84" y="166" width="92" height="16" rx="6" fill="#2563EB"/>
                            <path d="M214 78C222 86 222 98 214 106M224 68C238 82 238 102 224 116" stroke="#93C5FD" stroke-width="3" stroke-linecap="round" stroke-opacity=".75"/>
                        </svg>
                    </div>
                    <div class="np-auth-glass">
                        <span class="np-auth-glass__eyebrow">فروشگاه و راه‌اندازی سریع</span>
                        <h2>فروش و راه‌اندازی فوری انواع کارتخوان سیار و ثابت</h2>
                        <p>انتخاب مستقیم مدل‌های معتبر Newland، PAX، NEWPOS و TOPWISE با گارانتی رسمی و ارسال سریع به سراسر کشور.</p>
                    </div>
                </article>

                <article class="np-auth-slide" data-showcase-slide="1" hidden>
                    <div class="np-auth-slide__art" aria-hidden="true">
                        <svg viewBox="0 0 260 220" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="34" y="34" width="192" height="152" rx="22" fill="#0F274A" stroke="#60A5FA" stroke-opacity=".55" stroke-width="2"/>
                            <circle cx="80" cy="86" r="22" fill="#1D4ED8" fill-opacity=".35" stroke="#93C5FD" stroke-width="2"/>
                            <path d="M72 86L78 92L90 80" stroke="#34D399" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <rect x="116" y="72" width="84" height="10" rx="5" fill="#93C5FD" fill-opacity=".6"/>
                            <rect x="116" y="92" width="58" height="8" rx="4" fill="#60A5FA" fill-opacity=".35"/>
                            <rect x="58" y="132" width="144" height="10" rx="5" fill="#1E3A5F"/>
                            <rect x="58" y="132" width="106" height="10" rx="5" fill="#3B82F6"/>
                            <circle cx="164" cy="137" r="7" fill="#93C5FD"/>
                        </svg>
                    </div>
                    <div class="np-auth-glass">
                        <span class="np-auth-glass__eyebrow">تعمیرات تخصصی و شفاف</span>
                        <h2>پیگیری آنلاین و لحظه‌ای وضعیت تعمیر دستگاه</h2>
                        <p>ثبت آنلاین درخواست تعمیر، مشاهده شفاف هزینه، رسیدهای دیجیتال و اطلاع‌رسانی خودکار در هر مرحله تا تحویل نهایی.</p>
                    </div>
                </article>

                <article class="np-auth-slide" data-showcase-slide="2" hidden>
                    <div class="np-auth-slide__art" aria-hidden="true">
                        <svg viewBox="0 0 260 220" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="38" y="42" width="142" height="76" rx="18" fill="#173A6E" stroke="#93C5FD" stroke-opacity=".5" stroke-width="2"/>
                            <rect x="58" y="66" width="96" height="9" rx="4.5" fill="#E0F2FE" fill-opacity=".8"/>
                            <rect x="58" y="85" width="66" height="8" rx="4" fill="#93C5FD" fill-opacity=".45"/>
                            <rect x="86" y="106" width="136" height="72" rx="18" fill="#0F274A" stroke="#34D399" stroke-opacity=".6" stroke-width="2"/>
                            <circle cx="114" cy="142" r="14" fill="#10B981" fill-opacity=".22" stroke="#34D399" stroke-width="2"/>
                            <path d="M108 142L112.5 146.5L120 138" stroke="#34D399" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <rect x="138" y="132" width="66" height="8" rx="4" fill="#E2E8F0" fill-opacity=".8"/>
                            <rect x="138" y="148" width="46" height="7" rx="3.5" fill="#94A3B8" fill-opacity=".5"/>
                        </svg>
                    </div>
                    <div class="np-auth-glass">
                        <span class="np-auth-glass__eyebrow">همراهی و پشتیبانی فنی</span>
                        <h2>پشتیبانی تخصصی و مشاوره مستقیم با کارشناسان</h2>
                        <p>دسترسی سریع به سوابق سفارش‌ها و دستگاه‌ها همراه با گفت‌وگوی آنلاین و راهنمایی تخصصی پیش و پس از خرید.</p>
                    </div>
                </article>
            </div>
            <div class="np-auth-showcase__dots" role="tablist" aria-label="اسلایدهای معرفی نوین‌پی">
                <button type="button" role="tab" aria-selected="true" aria-label="اسلاید ۱" data-showcase-dot="0" class="is-active"></button>
                <button type="button" role="tab" aria-selected="false" aria-label="اسلاید ۲" data-showcase-dot="1"></button>
                <button type="button" role="tab" aria-selected="false" aria-label="اسلاید ۳" data-showcase-dot="2"></button>
            </div>
        </aside>
    </div>

    @if(app()->environment('local'))
        <style>
            @font-face {
                font-family: 'DanaVF';
                src: url('/fonts/DanaVF.woff2') format('woff2-variations'), url('/fonts/DanaVF.woff2') format('woff2');
                font-weight: 100 900;
                font-style: normal;
                font-display: swap;
            }
            @font-face {
                font-family: 'IRANSansXVF';
                src: url('/fonts/IRANSansXVFaNum.woff2') format('woff2-variations'), url('/fonts/IRANSansXFaNum-Regular.woff2') format('woff2');
                font-weight: 100 900;
                font-style: normal;
                font-display: swap;
            }
            @font-face {
                font-family: 'IRANYekanXVF';
                src: url('/fonts/IRANYekanXVFaNumVF.woff2') format('woff2-variations'), url('/fonts/IRANYekanXVFaNumVF.woff2') format('woff2');
                font-weight: 100 900;
                font-style: normal;
                font-display: swap;
            }
            [data-auth-shell][data-local-font="dana"],
            [data-auth-shell][data-local-font="dana"] :is(h1, h2, p, label, input, button, a, span, small) { font-family: 'DanaVF', sans-serif !important; font-feature-settings: "ss02" 1; }
            [data-auth-shell][data-local-font="iransansx"],
            [data-auth-shell][data-local-font="iransansx"] :is(h1, h2, p, label, input, button, a, span, small) { font-family: 'IRANSansXVF', sans-serif !important; }
            [data-auth-shell][data-local-font="iranyekanx"],
            [data-auth-shell][data-local-font="iranyekanx"] :is(h1, h2, p, label, input, button, a, span, small) { font-family: 'IRANYekanXVF', sans-serif !important; }
            .np-local-font-bar {
                position: fixed;
                inset-block-end: 14px;
                inset-inline-start: 14px;
                z-index: 40;
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 6px 8px;
                border-radius: 999px;
                background: rgb(11 25 46 / 88%);
                border: 1px solid rgb(255 255 255 / 16%);
                backdrop-filter: blur(10px);
                box-shadow: 0 10px 28px rgb(0 0 0 / 28%);
            }
            .np-local-font-bar button {
                border: 0;
                border-radius: 999px;
                padding: 5px 11px;
                background: transparent;
                color: #cbd5e1;
                font-size: 12px;
                font-weight: 600;
                cursor: pointer;
                transition: background-color 140ms ease, color 140ms ease;
            }
            .np-local-font-bar button.is-active { background: #1d6bf3; color: #fff; }
        </style>
        <div class="np-local-font-bar" data-local-font-switcher aria-label="تست فونت محلی">
            <button type="button" data-font-choice="default" class="is-active">Vazirmatn</button>
            <button type="button" data-font-choice="dana">Dana VF</button>
            <button type="button" data-font-choice="iransansx">IRANSansX</button>
            <button type="button" data-font-choice="iranyekanx">IRANYekanX VF</button>
        </div>
        <script>
            (function () {
                var shell = document.querySelector('[data-auth-shell]');
                var bar = document.querySelector('[data-local-font-switcher]');
                if (!shell || !bar) return;
                var buttons = bar.querySelectorAll('button[data-font-choice]');
                function apply(choice) {
                    if (choice && choice !== 'default') { shell.setAttribute('data-local-font', choice); }
                    else { shell.removeAttribute('data-local-font'); }
                    buttons.forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-font-choice') === (choice || 'default')); });
                    try { window.localStorage.setItem('novinpay_local_auth_font', choice || 'default'); } catch (e) {}
                }
                try { apply(window.localStorage.getItem('novinpay_local_auth_font') || 'default'); } catch (e) {}
                buttons.forEach(function (b) {
                    b.addEventListener('click', function () { apply(b.getAttribute('data-font-choice')); });
                });
            })();
        </script>
    @endif
</div>
<script src="{{ asset('js/customer-otp.js?v=6') }}" defer></script>
@endsection
