@extends('layouts.app')
@section('title', 'حساب کاربری')
@section('content')
@php
    $nav = ['overview' => 'نمای کلی', 'repairs' => 'تعمیرات', 'orders' => 'سفارش‌ها', 'payments' => 'اسناد و پرداخت‌ها', 'delivery' => 'ارسال و تحویل', 'profile' => 'اطلاعات شخصی', 'addresses' => 'آدرس‌ها', 'security' => 'امنیت حساب'];
@endphp
<div class="np-account np-container">
    <header class="np-account-banner">
        <div>
            <p>حساب کاربری نوین‌پی</p>
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
                    @foreach(['requests' => 'درخواست تعمیر', 'active' => 'تعمیر فعال', 'orders' => 'سفارش'] as $key => $label)
                        <a href="{{ route('account.index', ['section' => $key === 'orders' ? 'orders' : 'repairs']) }}">
                            <strong>{{ \App\Support\FaNumber::digits($counts[$key]) }}</strong><span>{{ $label }}</span>
                        </a>
                    @endforeach
                </div>
                <section class="np-account-card">
                    <h2>دسترسی سریع</h2>
                    <div class="np-account-shortcuts">
                        <a href="{{ route('repair.track') }}">پیگیری تعمیر</a>
                        <a href="{{ route('products.index') }}">مشاهده فروشگاه</a>
                        <a href="{{ route('account.index', ['section' => 'profile']) }}">{{ $complete ? 'ویرایش پروفایل' : 'تکمیل اطلاعات پروفایل' }}</a>
                    </div>
                </section>
            @endif

            @if(in_array($section, ['overview', 'repairs'], true))
                <section class="np-account-card">
                    <div class="np-account-card__heading">
                        <h2>{{ $section === 'overview' ? 'تعمیرات اخیر' : 'درخواست‌ها و پرونده‌ها' }}</h2>
                        <a class="np-account-link" href="{{ route('repair') }}">ثبت درخواست تعمیر</a>
                    </div>
                    @foreach($repairs as $repair)
                        <a class="np-account-row" href="{{ route('account.repairs.show', $repair->public_id) }}">
                            <span><strong>{{ $repair->device_model }}</strong><small>کد پیگیری <bdi dir="ltr">{{ \App\Support\FaNumber::digits($repair->tracking_reference) }}</bdi></small></span>
                            <span class="np-account-tag">{{ \App\Support\CustomerTimeline::repairStatus($repair) }}</span>
                        </a>
                    @endforeach
                    @foreach($requests as $repair)
                        <a class="np-account-row" href="{{ route('account.repairs.request', $repair->tracking_reference) }}">
                            <span><strong>{{ $repair->device_model }}</strong><small>کد پیگیری <bdi dir="ltr">{{ \App\Support\FaNumber::digits($repair->tracking_reference) }}</bdi></small></span>
                            <span class="np-account-tag">درخواست ثبت شد</span>
                        </a>
                    @endforeach
                    @if($repairs->isEmpty() && $requests->isEmpty())<p>هنوز درخواست تعمیری در این حساب ثبت نشده است.</p>@endif
                    @if($section === 'repairs'){{ $repairs->links() }}{{ $requests->links() }}@endif
                </section>
            @endif

            @if(in_array($section, ['overview', 'orders'], true))
                <section class="np-account-card">
                    <div class="np-account-card__heading">
                        <h2>{{ $section === 'overview' ? 'سفارش‌های اخیر' : 'سابقه سفارش‌ها' }}</h2>
                        <a class="np-account-link" href="{{ route('products.index') }}">فروشگاه</a>
                    </div>
                    @forelse($orders as $order)
                        <a class="np-account-row" href="{{ route('account.orders.show', $order->public_id) }}">
                            <span><strong>{{ \App\Support\CustomerTimeline::orderStatus($order) }}</strong><small><x-finance-time :value="$order->created_at" /></small></span>
                            <span class="np-account-tag">{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display(\App\Support\OrderMoney::minor($order->total))) }} تومان</span>
                        </a>
                    @empty
                        <p>هنوز سفارشی ثبت نکرده‌اید. می‌توانید محصولات را در فروشگاه ببینید.</p>
                    @endforelse
                    @if($section === 'orders'){{ $orders->links() }}@endif
                </section>
            @endif

            @if(in_array($section, ['payments', 'delivery'], true))
                <section class="np-account-card">
                    <h2>{{ $nav[$section] }}</h2>
                    @forelse($documents as $document)
                        <a class="np-account-row" href="{{ route($section === 'payments' ? 'payments.document' : 'delivery.show', $document->public_id) }}">
                            <span><strong>{{ $document->sales_order_id ? 'سند سفارش' : 'سند تعمیر' }}</strong><small><x-finance-time :value="$document->created_at" /></small></span>
                            <span class="np-account-tag">{{ $section === 'payments' ? 'مشاهده سند و رسیدها' : 'پیگیری تحویل' }}</span>
                        </a>
                    @empty
                        <p>هنوز سندی برای نمایش ثبت نشده است.</p>
                    @endforelse
                    {{ $documents->links() }}
                </section>
            @endif

            @if($section === 'overview')
                <section class="np-account-card" aria-labelledby="account-support-title">
                    <h2 id="account-support-title">پشتیبانی</h2>
                    <details class="np-account-address">
                        <summary>شناسه حساب</summary>
                        <p>اگر پشتیبانی از شما شناسه حساب خواست، می‌توانید آن را کپی و ارسال کنید.</p>
                        <p class="np-account-reference"><bdi id="account-support-id" dir="ltr">{{ $account->public_id }}</bdi></p>
                        <button class="np-account-link" type="button" data-copy-account="account-support-id" aria-describedby="account-copy-status" hidden>کپی شناسه</button>
                        <p id="account-copy-status" role="status"></p>
                    </details>
                </section>
            @endif

            @if($section === 'profile')
                <section class="np-account-card">
                    <h2>اطلاعات شخصی</h2>
                    <form method="post" class="np-account-form" action="{{ route('account.profile') }}">
                        @csrf
                        <label for="profile-name">نام و نام خانوادگی</label>
                        <input id="profile-name" name="name" value="{{ $account->name }}" autocomplete="name" minlength="2" maxlength="100" required>
                        <label for="profile-mobile">شماره موبایل</label>
                        <input id="profile-mobile" type="tel" value="{{ \App\Support\FaNumber::digits($account->phone) }}" dir="ltr" readonly>
                        <small>تغییر شماره به تأیید دوباره مالکیت آن نیاز دارد.</small>
                        <button class="np-account-primary">ذخیره اطلاعات</button>
                    </form>
                </section>
            @endif

            @if($section === 'addresses')
                <section class="np-account-card">
                    <h2>آدرس‌های ذخیره‌شده</h2>
                    @forelse($addresses as $address)
                        <details class="np-account-address">
                            <summary><strong>{{ $address->recipient }}</strong> · {{ $address->province }}، {{ $address->city }}</summary>
                            <x-account.address-form :address="$address" />
                            <form class="mt-4" method="post" action="{{ route('account.address.remove', $address->public_id) }}" data-confirm="این آدرس از فهرست شما حذف شود؟ نشانی ارسال‌های قبلی باقی می‌ماند.">
                                @csrf<button class="np-account-link" type="submit">حذف آدرس</button>
                            </form>
                        </details>
                    @empty
                        <p>هنوز آدرسی ذخیره نکرده‌اید.</p>
                    @endforelse
                    <details class="np-account-address" @if($addresses->isEmpty() || $errors->any()) open @endif>
                        <summary>افزودن آدرس</summary>
                        <x-account.address-form />
                    </details>
                </section>
            @endif

            @if($section === 'security')
                <section class="np-account-card">
                    <h2>{{ $account->password ? 'تغییر رمز عبور' : 'انتخاب رمز عبور' }}</h2>
                    @if($recentOtp || $account->password)
                        <form method="post" class="np-account-form" action="{{ route('account.password.update') }}">
                            @csrf
                            @if(!$recentOtp)
                                <label for="current-password">رمز عبور فعلی</label>
                                <x-account.password id="current-password" name="current_password" autocomplete="current-password" />
                            @endif
                            <label for="new-password">رمز عبور جدید</label>
                            <x-account.password id="new-password" name="password" autocomplete="new-password" minlength="10" aria-describedby="password-guidance" />
                            <small id="password-guidance">حداقل ۱۰ کاراکتر وارد کنید. یک عبارت طولانی که به‌راحتی به خاطر می‌سپارید انتخاب کنید.</small>
                            <label for="password-confirmation">تکرار رمز عبور جدید</label>
                            <x-account.password id="password-confirmation" name="password_confirmation" autocomplete="new-password" minlength="10" />
                            <button class="np-account-primary">ذخیره رمز عبور</button>
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
