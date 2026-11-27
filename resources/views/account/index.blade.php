@extends('layouts.app')
@section('title', 'حساب کاربری')
@section('content')
@php
    $nav = [
        'overview' => 'نمای کلی',
        'orders' => 'سفارش‌ها',
        'repairs' => 'تعمیرات',
        'reviews' => 'دیدگاه‌ها',
        'addresses' => 'آدرس‌ها',
        'profile' => 'اطلاعات شخصی',
        'security' => 'امنیت حساب',
    ];
    $feedbackStatuses = ['approved' => 'منتشرشده', 'pending' => 'در انتظار بررسی', 'rejected' => 'تأییدنشده'];
    $zibalReady = app(\App\Services\ZibalGateway::class)->available();
@endphp
<div class="np-account np-container">
    <header class="np-account-banner">
        <div>
            <p class="np-account-banner__eyebrow">حساب کاربری نوین‌پی</p>
            <h1>{{ $section === 'overview' ? ($account->name ? 'سلام، '.$account->name : 'به حساب خود خوش آمدید') : $nav[$section] }}</h1>
        </div>
        <form method="post" action="{{ route('account.logout') }}">@csrf<button class="np-account-logout">خروج از حساب</button></form>
    </header>
    <div class="np-account-layout">
        <nav class="np-account-nav" aria-label="بخش‌های حساب کاربری">
            @foreach($nav as $key => $label)
                <a href="{{ route('account.index', ['section' => $key]) }}" @if($key === $section) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="np-account-content">
            @unless($account->verified_at)<p class="np-account-notice" role="status">این حساب آزمایشی است.</p>@endunless
            <x-admin.errors />
            @if(session('success'))<p class="np-account-notice" role="status">{{ session('success') }}</p>@endif
            @if($section === 'overview')
                <div class="np-account-stats">
                    @foreach(['orders' => 'سفارش', 'active' => 'تعمیر فعال', 'requests' => 'درخواست تعمیر'] as $key => $label)
                        <a href="{{ route('account.index', ['section' => $key === 'orders' ? 'orders' : 'repairs']) }}">
                            <strong>{{ \App\Support\FaNumber::digits($counts[$key]) }}</strong><span>{{ $label }}</span>
                        </a>
                    @endforeach
                </div>
                <section class="np-account-card">
                    <div class="np-account-card__heading">
                        <h2>دسترسی سریع</h2>
                    </div>
                    <div class="np-account-shortcuts">
                        <a href="{{ route('repair') }}">ثبت درخواست تعمیر</a>
                        <a href="{{ route('repair.track') }}">پیگیری تعمیر</a>
                        <a href="{{ route('products.index') }}">مشاهده فروشگاه</a>
                        <a href="{{ route('account.index', ['section' => 'profile']) }}">{{ $complete ? 'ویرایش پروفایل' : 'تکمیل اطلاعات پروفایل' }}</a>
                    </div>
                </section>
            @endif

            @if($section === 'overview')
                <div class="np-account-overview-grid">
            @endif
            @if(in_array($section, ['overview', 'orders'], true))
                <section class="np-account-card">
                    <div class="np-account-card__heading">
                        <h2>{{ $section === 'overview' ? 'سفارش‌های اخیر' : 'سابقه سفارش‌ها' }}</h2>
                        @if($section === 'overview')
                            <a class="np-account-link" href="{{ route('account.index', ['section' => 'orders']) }}">همه سفارش‌ها</a>
                        @else
                            <a class="np-account-link" href="{{ route('products.index') }}">مشاهده فروشگاه</a>
                        @endif
                    </div>
                    @forelse($orders as $order)
                        <a class="np-account-row np-account-row--order" href="{{ route('account.orders.show', $order->public_id) }}">
                            <span class="np-account-row__main">
                                <span class="np-account-row__title">
                                    کد سفارش <bdi dir="ltr">{{ \App\Support\FaNumber::digits(strtoupper(substr($order->public_id, 0, 8))) }}</bdi>
                                </span>
                                <span class="np-account-row__meta">
                                    <span class="np-account-pill">{{ \App\Support\CustomerTimeline::orderStatus($order) }}</span>
                                    <span class="np-account-dot" aria-hidden="true">·</span>
                                    <span><x-finance-time :value="$order->created_at" /></span>
                                    @if($order->lines->isNotEmpty())
                                        <span class="np-account-dot" aria-hidden="true">·</span>
                                        <span>{{ \App\Support\FaNumber::digits($order->lines->sum('quantity')) }} کالا</span>
                                    @endif
                                </span>
                            </span>
                            <span class="np-account-price">{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman(\App\Support\OrderMoney::minor($order->total))) }} <small>تومان</small></span>
                        </a>
                    @empty
                        <div class="np-account-empty">
                            <p>هنوز سفارش پرداخت‌شده‌ای ثبت نکرده‌اید. می‌توانید محصولات را در فروشگاه ببینید.</p>
                            <a class="np-account-link" href="{{ route('products.index') }}">رفتن به فروشگاه</a>
                        </div>
                    @endforelse
                    @if($section === 'orders'){{ $orders->links() }}@endif
                </section>
            @endif

            @if(in_array($section, ['overview', 'repairs'], true))
                <section class="np-account-card">
                    <div class="np-account-card__heading">
                        <h2>{{ $section === 'overview' ? 'تعمیرات اخیر' : 'درخواست‌ها و پرونده‌ها' }}</h2>
                        @if($section === 'overview')
                            <a class="np-account-link" href="{{ route('account.index', ['section' => 'repairs']) }}">همه تعمیرات</a>
                        @else
                            <a class="np-account-link" href="{{ route('repair') }}">ثبت درخواست تعمیر</a>
                        @endif
                    </div>
                    @foreach($repairs as $repair)
                        @php
                            $deviceLabel = \App\Support\RepairIdentity::deviceLabel($repair->device_brand, $repair->device_model);
                            $repairStatusLabel = \App\Support\CustomerTimeline::repairStatus($repair);
                            $repairBalanceMinor = (int) ($repairPayableMap[$repair->id] ?? 0);
                            $repairTone = match ($repair->status) {
                                \App\Enums\RepairStage::Ready, \App\Enums\RepairStage::Repaired, \App\Enums\RepairStage::Delivered => 'success',
                                \App\Enums\RepairStage::WaitingApproval => 'warning',
                                \App\Enums\RepairStage::ReturnPending, \App\Enums\RepairStage::Returned => 'danger',
                                default => 'info',
                            };
                        @endphp
                        <div class="np-account-row-group">
                            <a class="np-account-row" href="{{ route('account.repairs.show', $repair->public_id) }}">
                                <span class="np-account-row__main">
                                    <span class="np-account-row__title">@if(preg_match('/[A-Za-z]/', $deviceLabel))<bdi class="np-latin-code" dir="ltr">{{ $deviceLabel }}</bdi>@else{{ $deviceLabel }}@endif</span>
                                    <span class="np-account-row__meta">
                                        <span>کد پیگیری <bdi dir="ltr">{{ \App\Support\FaNumber::digits($repair->tracking_reference) }}</bdi></span>
                                        <span class="np-account-dot" aria-hidden="true">·</span>
                                        <span><x-finance-time :value="$repair->received_at ?? $repair->created_at" :with-time="false" /></span>
                                    </span>
                                </span>
                                <span class="np-account-row__end">
                                    <span class="np-account-pill np-account-pill--{{ $repairTone }}">{{ $repairStatusLabel }}</span>
                                    <svg class="np-account-row__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                                </span>
                            </a>
                            @if($repairBalanceMinor > 0)
                                <div class="np-account-repair-pay-bar">
                                    <span class="np-account-repair-pay-bar__amount">مانده هزینه تعمیر: <strong>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman($repairBalanceMinor)) }} تومان</strong></span>
                                    <form method="post" action="{{ route('account.repairs.pay', $repair->public_id) }}" data-prevent-double-submit>
                                        @csrf
                                        <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        <button type="submit" class="np-button np-button--primary np-account-repair-pay-bar__btn" @disabled(! $zibalReady)>پرداخت آنلاین با زیبال</button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @endforeach
                    @foreach($requests as $repair)
                        @php($deviceLabel = \App\Support\RepairIdentity::deviceLabel($repair->device_brand, $repair->device_model))
                        <a class="np-account-row" href="{{ route('account.repairs.request', $repair->tracking_reference) }}">
                            <span class="np-account-row__main">
                                <span class="np-account-row__title">@if(preg_match('/[A-Za-z]/', $deviceLabel))<bdi class="np-latin-code" dir="ltr">{{ $deviceLabel }}</bdi>@else{{ $deviceLabel }}@endif</span>
                                <span class="np-account-row__meta">
                                    <span>کد پیگیری <bdi dir="ltr">{{ \App\Support\FaNumber::digits($repair->tracking_reference) }}</bdi></span>
                                    <span class="np-account-dot" aria-hidden="true">·</span>
                                    <span><x-finance-time :value="$repair->created_at" :with-time="false" /></span>
                                </span>
                            </span>
                            <span class="np-account-row__end">
                                <span class="np-account-pill np-account-pill--info">درخواست ثبت شد</span>
                                <svg class="np-account-row__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                            </span>
                        </a>
                    @endforeach
                    @if($repairs->isEmpty() && $requests->isEmpty())
                        <div class="np-account-empty">
                            <p>هنوز درخواست تعمیری در این حساب ثبت نشده است.</p>
                            <a class="np-account-link" href="{{ route('repair') }}">ثبت اولین درخواست تعمیر</a>
                        </div>
                    @endif
                    @if($section === 'repairs'){{ $repairs->links() }}{{ $requests->links() }}@endif
                </section>
            @endif
            @if($section === 'overview')
                </div>
            @endif

            @if($section === 'reviews')
                <section class="np-account-card">
                    <div class="np-account-card__heading">
                        <h2>دیدگاه‌ها و امتیازهای شما</h2>
                        @if($reviews->isNotEmpty())
                            <a class="np-account-link" href="{{ route('products.index') }}">فروشگاه</a>
                        @endif
                    </div>
                    @forelse($reviews as $feedback)
                        <article class="np-account-review">
                            <div class="np-account-review__head">
                                <div>
                                    @if($feedback->product)
                                        <a class="np-account-review__product" href="{{ route('products.show', $feedback->product->slug) }}">{{ $feedback->product->name }}</a>
                                    @else
                                        <span class="np-account-review__product">کالا</span>
                                    @endif
                                    <span class="np-account-row__meta">
                                        <span>{{ $feedback->kind === 'question' ? 'پرسش' : 'دیدگاه' }}</span>
                                        <span class="np-account-dot" aria-hidden="true">·</span>
                                        <span><x-finance-time :value="$feedback->created_at" /></span>
                                    </span>
                                </div>
                                <div class="np-account-review__badges">
                                    @if($feedback->rating)
                                        <span class="np-account-rating" aria-label="امتیاز {{ \App\Support\FaNumber::digits($feedback->rating) }} از ۵">★ {{ \App\Support\FaNumber::digits($feedback->rating) }}</span>
                                    @endif
                                    <span class="np-account-pill">{{ $feedbackStatuses[$feedback->status] ?? $feedback->status }}</span>
                                </div>
                            </div>
                            <p class="np-account-review__body">{{ $feedback->body }}</p>
                            @if($feedback->staff_reply)
                                <p class="np-account-review__reply"><strong>پاسخ نوین‌پی:</strong> {{ $feedback->staff_reply }}</p>
                            @endif
                        </article>
                    @empty
                        <div class="np-account-empty">
                            <p>هنوز دیدگاه یا پرسشی روی محصولات ثبت نکرده‌اید.</p>
                            <a class="np-account-link" href="{{ route('products.index') }}">مشاهده محصولات</a>
                        </div>
                    @endforelse
                    @if($reviews instanceof \Illuminate\Contracts\Pagination\Paginator){{ $reviews->links() }}@endif
                </section>
            @endif

            @if($section === 'profile')
                <section class="np-account-card">
                    <h2>اطلاعات شخصی</h2>
                    <form method="post" class="np-account-form" data-prevent-double-submit action="{{ route('account.profile') }}">
                        @csrf
                        <div class="np-account-form__field">
                            <div class="np-float-field" data-float-field>
                                <input id="profile-name" name="name" placeholder=" " value="{{ old('name', $account->name) }}" autocomplete="name" minlength="2" maxlength="100" @class(['is-invalid' => $errors->has('name')]) @error('name') aria-invalid="true" @enderror required>
                                <label for="profile-name">نام و نام خانوادگی</label>
                            </div>
                            @error('name')<p class="np-field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        @if($account->email)
                            <div class="np-account-form__field">
                                <div class="np-float-field" data-float-field>
                                    <input id="profile-email" type="email" placeholder=" " value="{{ $account->email }}" dir="ltr" readonly>
                                    <label for="profile-email">نشانی ایمیل</label>
                                </div>
                            </div>
                        @endif
                        <div class="np-account-form__field">
                            <div class="np-float-field" data-float-field>
                                <input id="profile-mobile" type="tel" value="{{ $account->phone ? \App\Support\FaNumber::digits($account->phone) : '' }}" placeholder="ثبت نشده" dir="ltr" readonly>
                                <label for="profile-mobile">شماره موبایل</label>
                            </div>
                            <div class="np-account-field-help">
                                <small>{{ $account->phone ? 'تغییر شماره به تأیید دوباره مالکیت آن نیاز دارد.' : 'شماره موبایلی برای این حساب ثبت نشده است.' }}</small>
                                <a class="np-account-link np-account-link--inline" href="{{ route('account.index', ['section' => 'security']) }}">{{ $account->phone ? 'تغییر شماره موبایل' : 'تنظیم شماره موبایل' }}</a>
                            </div>
                        </div>
                        <button class="np-account-primary" type="submit">ذخیره اطلاعات</button>
                    </form>
                </section>
            @endif

            @if($section === 'addresses')
                <section class="np-account-card">
                    <h2>آدرس‌های ذخیره‌شده</h2>
                    <script type="application/json" id="iran-locations-data">@json(config('iran_locations', []))</script>
                    @forelse($addresses as $address)
                        <section class="np-account-address">
                            <h3 class="np-account-address__summary">
                                <span class="np-account-address__head">
                                    <span class="np-account-address__top">
                                        <span class="np-account-row__title">{{ $address->recipient }}</span>
                                        <span class="np-account-address__city">{{ $address->province }}، {{ $address->city }}</span>
                                    </span>
                                    <span class="np-account-address__preview">{{ \Illuminate\Support\Str::limit($address->address, 90) }}</span>
                                </span>
                                <svg class="np-account-address__chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                            </h3>
                            <x-account.address-form :address="$address" />
                            <form class="mt-4" method="post" action="{{ route('account.address.remove', $address->public_id) }}" data-confirm="این آدرس از فهرست شما حذف شود؟ نشانی ارسال‌های قبلی باقی می‌ماند.">
                                @csrf<button class="np-account-link" type="submit">حذف آدرس</button>
                            </form>
                        </section>
                    @empty
                        <div class="np-account-empty">
                            <p>هنوز آدرسی ذخیره نکرده‌اید.</p>
                        </div>
                    @endforelse
                    <?php $openNewAddress = $addresses->isEmpty() || ($errors->any() && old('address_form_key', 'new') === 'new'); ?>
                    <div class="np-account-address np-account-address--new">
                        <button type="button" class="np-account-add-btn" data-toggle-panel="account-new-address-panel" aria-expanded="{{ $openNewAddress ? 'true' : 'false' }}" aria-controls="account-new-address-panel">
                            <svg class="np-account-address__plus" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                            <span>افزودن آدرس جدید</span>
                        </button>
                        <div id="account-new-address-panel" class="np-account-address-panel" {{ $openNewAddress ? '' : 'hidden' }}>
                            <x-account.address-form />
                        </div>
                    </div>
                </section>
            @endif

            @if($section === 'security')
                <section class="np-account-card">
                    <h2>{{ $account->password ? 'تغییر رمز عبور' : 'انتخاب رمز عبور' }}</h2>
                    @if(($canSetInitialPassword ?? false) || $recentOtp || $account->password)
                        <form method="post" class="np-account-form" data-prevent-double-submit action="{{ route('account.password.update') }}">
                            @csrf
                            @if($account->password && !$recentOtp)
                                <div class="np-account-form__field">
                                    <div class="np-float-field" data-float-field>
                                        <x-account.password id="current-password" name="current_password" autocomplete="current-password" placeholder=" " />
                                        <label for="current-password">رمز عبور فعلی</label>
                                    </div>
                                    @error('current_password')<p class="np-field-error" role="alert">{{ $message }}</p>@enderror
                                </div>
                            @endif
                            <div class="np-account-form__field">
                                <div class="np-float-field" data-float-field>
                                    <x-account.password id="new-password" name="password" autocomplete="new-password" minlength="10" placeholder=" " aria-describedby="password-guidance" data-password-strength-input />
                                    <label for="new-password">رمز عبور جدید</label>
                                </div>
                                <div class="np-password-meter" data-password-strength hidden aria-live="polite">
                                    <div class="np-password-meter__bars" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                                    <small class="np-password-meter__label" data-password-strength-label></small>
                                </div>
                                <small id="password-guidance">حداقل ۱۰ کاراکتر وارد کنید. یک عبارت طولانی که به‌راحتی به خاطر می‌سپارید انتخاب کنید.</small>
                                @error('password')<p class="np-field-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                            <div class="np-account-form__field">
                                <div class="np-float-field" data-float-field>
                                    <x-account.password id="password-confirmation" name="password_confirmation" autocomplete="new-password" minlength="10" placeholder=" " />
                                    <label for="password-confirmation">تکرار رمز عبور جدید</label>
                                </div>
                                @error('password_confirmation')<p class="np-field-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                            <button class="np-account-primary" type="submit">ذخیره رمز عبور</button>
                        </form>
                    @else
                        <p>برای انتخاب رمز، از حساب خارج شوید و دوباره با کد تأیید وارد شوید.</p>
                    @endif
                    @if(session('customer_password_offer'))
                        <form method="post" class="mt-5" action="{{ route('account.password.skip') }}">@csrf<button class="np-account-link">فعلاً نمی‌خواهم</button></form>
                    @endif
                </section>
            @endif
        </div>
    </div>
</div>
@endsection
