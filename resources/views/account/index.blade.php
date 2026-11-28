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
    $urgentItems = $urgentActions ?? [];
    $devicesList = $savedDevices ?? collect();
    $settledOrders = $settledOrderIds ?? [];
    $settledRepairs = $settledRepairIds ?? [];
@endphp
<div class="np-account np-account-dashboard np-container">
    <header class="np-account-banner">
        <div>
            <p class="np-account-banner__eyebrow">حساب کاربری نوین‌پی</p>
            <h1>{{ $section === 'overview' ? ($account->name ? 'سلام، '.$account->name : 'به حساب خود خوش آمدید') : $nav[$section] }}</h1>
        </div>
        <form method="post" action="{{ route('account.logout') }}">@csrf<button class="np-account-logout">خروج از حساب</button></form>
    </header>
    <div class="np-account-layout">
        <nav class="np-account-nav" aria-label="بخش‌های حساب کاربری" data-account-tabs>
            @foreach($nav as $key => $label)
                <a href="{{ route('account.index', ['section' => $key]) }}" @if($key === $section) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="np-account-content">
            @unless($account->verified_at)<p class="np-account-notice" role="status">این حساب آزمایشی است.</p>@endunless
            <x-admin.errors />
            @if(session('success'))<p class="np-account-notice" role="status">{{ session('success') }}</p>@endif
            <p id="account-copy-status" class="sr-only" role="status" aria-live="polite"></p>

            @if(! empty($urgentItems) && in_array($section, ['overview', 'orders', 'repairs'], true))
                <section class="np-urgent-banner" data-urgent-action-banner aria-label="اقدامات فوری حساب کاربری">
                    @foreach($urgentItems as $urgent)
                        <div class="np-urgent-banner__item np-urgent-banner__item--{{ $urgent['type'] === 'repair_payment' ? 'payment' : 'docs' }}">
                            <div class="np-urgent-banner__body">
                                <span class="np-urgent-banner__icon" aria-hidden="true">
                                    @if($urgent['type'] === 'repair_payment')
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                                    @else
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" x2="12" y1="18" y2="12"/><line x1="9" x2="15" y1="15" y2="15"/></svg>
                                    @endif
                                </span>
                                <div class="np-urgent-banner__text">
                                    @if($urgent['type'] === 'repair_payment')
                                        <strong>هزینه تعمیر دستگاه آماده پرداخت است</strong>
                                        <p>پرونده <bdi dir="ltr">{{ \App\Support\FaNumber::digits($urgent['code']) }}</bdi> ({{ $urgent['device'] }}) · مبلغ قابل پرداخت: <strong>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman($urgent['amount_minor'])) }} تومان</strong></p>
                                    @elseif($urgent['type'] === 'repair_activation_documents')
                                        <strong>{{ $urgent['needs_revision'] ? 'نیاز به اصلاح مدارک فعال‌سازی تعمیر' : 'تکمیل مدارک فعال‌سازی دستگاه تعمیری' }}</strong>
                                        <p>پرونده تعمیر <bdi dir="ltr">{{ \App\Support\FaNumber::digits($urgent['code']) }}</bdi> ({{ $urgent['device'] }}){{ $urgent['needs_revision'] && ! empty($urgent['reason']) ? ' — '.$urgent['reason'] : ' — برای اتصال مجدد و فعال‌سازی دستگاه، مدارک را بارگذاری کنید.' }}</p>
                                    @else
                                        <strong>{{ $urgent['needs_revision'] ? 'نیاز به اصلاح مدارک فعال‌سازی سفارش' : 'ارسال مدارک فعال‌سازی دستگاه کارتخوان' }}</strong>
                                        <p>سفارش <bdi dir="ltr">{{ \App\Support\FaNumber::digits($urgent['code']) }}</bdi>{{ $urgent['needs_revision'] && ! empty($urgent['reason']) ? ' — '.$urgent['reason'] : ' — جهت ثبت پرونده مالیاتی و شاپرکی، مدارک را بارگذاری کنید.' }}</p>
                                    @endif
                                </div>
                            </div>
                            <div class="np-urgent-banner__actions">
                                @if($urgent['type'] === 'repair_payment')
                                    <form method="post" action="{{ $urgent['pay_url'] }}" data-prevent-double-submit>
                                        @csrf
                                        <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        <button type="submit" class="np-button np-button--primary np-urgent-banner__cta" @disabled(! $zibalReady)>پرداخت هزینه تعمیر</button>
                                    </form>
                                    <a href="{{ $urgent['detail_url'] }}" class="np-account-link">جزئیات</a>
                                @else
                                    <a href="{{ $urgent['cta_url'] }}" class="np-button np-button--primary np-urgent-banner__cta">{{ $urgent['needs_revision'] ? 'اصلاح و ارسال مجدد مدارک' : 'تکمیل مدارک فعال‌سازی' }}</a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </section>
            @endif

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

            @if($devicesList->isNotEmpty() && in_array($section, ['overview', 'repairs'], true))
                <section class="np-account-card np-devices-card" id="my-devices" data-my-devices aria-labelledby="my-devices-heading">
                    <div class="np-account-card__heading">
                        <div>
                            <h2 id="my-devices-heading">دستگاه‌های من</h2>
                            <p class="np-account-card__sub">انتخاب سریع از دستگاه‌های خریداری‌شده یا تعمیرشدهٔ قبلی بدون نیاز به تایپ مجدد</p>
                        </div>
                        <a class="np-account-link" href="{{ route('repair') }}">درخواست تعمیر جدید</a>
                    </div>
                    <div class="np-devices-grid">
                        @foreach($devicesList as $dev)
                            <div class="np-device-item" data-device-card>
                                <div class="np-device-item__info">
                                    <span class="np-device-item__title">
                                        <bdi class="np-latin-code" dir="ltr">{{ \App\Support\RepairIdentity::deviceLabel($dev->device_brand, $dev->device_model) }}</bdi>
                                    </span>
                                    <span class="np-device-item__meta">
                                        @if(! empty($dev->device_serial))
                                            <span>سریال: <bdi class="np-latin-code" dir="ltr">{{ $dev->device_serial }}</bdi></span>
                                        @else
                                            <span>{{ $dev->source_type === 'order' ? 'ثبت‌شده از خرید قبلی' : 'ثبت‌شده از سابقه تعمیر' }}</span>
                                        @endif
                                    </span>
                                </div>
                                <div class="np-device-item__actions">
                                    <a class="np-device-action np-device-action--primary" href="{{ route('repair', ['device' => $dev->public_id]) }}">ثبت تعمیر سریع</a>
                                    <a class="np-device-action" href="{{ route('products.index', ['category' => 'peripheral', 'q' => $dev->device_brand !== 'سایر' ? $dev->device_brand : $dev->device_model]) }}">لوازم جانبی</a>
                                </div>
                            </div>
                        @endforeach
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
                        @elseif($orders->isNotEmpty())
                            <a class="np-account-link" href="{{ route('products.index') }}">مشاهده فروشگاه</a>
                        @endif
                    </div>
                    @forelse($orders as $order)
                        @php
                            $orderCodeRaw = strtoupper(substr($order->public_id, 0, 8));
                            $isOrderSettled = in_array((int) $order->id, $settledOrders, true);
                        @endphp
                        <div class="np-account-row-group">
                            <div class="np-account-row-wrap">
                                <a class="np-account-row np-account-row--order" href="{{ route('account.orders.show', $order->public_id) }}">
                                    <span class="np-account-row__main">
                                        <span class="np-account-row__title">
                                            کد سفارش <bdi dir="ltr">{{ \App\Support\FaNumber::digits($orderCodeRaw) }}</bdi>
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
                                    <span class="np-account-row__end">
                                        <span class="np-account-price">{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman(\App\Support\OrderMoney::minor($order->total))) }} <small>تومان</small></span>
                                        <svg class="np-account-row__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                                    </span>
                                </a>
                                <div class="np-account-row-tools">
                                    <button type="button" class="np-copy-chip" data-copy-code="{{ $orderCodeRaw }}" aria-label="کپی کد سفارش {{ $orderCodeRaw }}" title="کپی کد سفارش">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                                        <span data-copy-code-label>کپی کد</span>
                                    </button>
                                    @if($isOrderSettled)
                                        <a class="np-receipt-chip" href="{{ route('account.orders.receipt', $order->public_id) }}">مشاهده و چاپ رسید</a>
                                    @endif
                                </div>
                            </div>
                        </div>
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
                            $isRepairSettled = in_array((int) $repair->id, $settledRepairs, true);
                            $repairTone = match ($repair->status) {
                                \App\Enums\RepairStage::Ready, \App\Enums\RepairStage::Repaired, \App\Enums\RepairStage::Delivered => 'success',
                                \App\Enums\RepairStage::WaitingApproval => 'warning',
                                \App\Enums\RepairStage::ReturnPending, \App\Enums\RepairStage::Returned => 'danger',
                                default => 'info',
                            };
                        @endphp
                        <div class="np-account-row-group">
                            <div class="np-account-row-wrap">
                                <a class="np-account-row" href="{{ route('account.repairs.show', $repair->public_id) }}">
                                    <span class="np-account-row__main">
                                        <span class="np-account-row__title">@if(preg_match('/[A-Za-z]/', $deviceLabel))<bdi class="np-latin-code" dir="ltr">{{ $deviceLabel }}</bdi>@else{{ $deviceLabel }}@endif</span>
                                        <span class="np-account-row__meta">
                                            <span>کد پیگیری <bdi dir="ltr">{{ \App\Support\FaNumber::digits($repair->tracking_reference) }}</bdi></span>
                                            <span class="np-account-dot" aria-hidden="true">|</span>
                                            <span><x-finance-time :value="$repair->received_at ?? $repair->created_at" :with-time="false" /></span>
                                        </span>
                                    </span>
                                    <span class="np-account-row__end">
                                        <span class="np-account-pill np-account-pill--{{ $repairTone }}">{{ $repairStatusLabel }}</span>
                                        <svg class="np-account-row__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                                    </span>
                                </a>
                                <div class="np-account-row-tools">
                                    <button type="button" class="np-copy-chip" data-copy-code="{{ $repair->tracking_reference }}" aria-label="کپی کد پیگیری {{ $repair->tracking_reference }}" title="کپی کد پیگیری">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                                        <span data-copy-code-label>کپی کد</span>
                                    </button>
                                    @if($isRepairSettled)
                                        <a class="np-receipt-chip" href="{{ route('account.repairs.receipt', $repair->public_id) }}">مشاهده و چاپ رسید</a>
                                    @endif
                                </div>
                            </div>
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
                        @php
                            $deviceLabel = \App\Support\RepairIdentity::deviceLabel($repair->device_brand, $repair->device_model);
                        @endphp
                        <div class="np-account-row-group">
                            <div class="np-account-row-wrap">
                                <a class="np-account-row" href="{{ route('account.repairs.request', $repair->tracking_reference) }}">
                                    <span class="np-account-row__main">
                                        <span class="np-account-row__title">@if(preg_match('/[A-Za-z]/', $deviceLabel))<bdi class="np-latin-code" dir="ltr">{{ $deviceLabel }}</bdi>@else{{ $deviceLabel }}@endif</span>
                                        <span class="np-account-row__meta">
                                            <span>کد پیگیری <bdi dir="ltr">{{ \App\Support\FaNumber::digits($repair->tracking_reference) }}</bdi></span>
                                            <span class="np-account-dot" aria-hidden="true">|</span>
                                            <span><x-finance-time :value="$repair->created_at" :with-time="false" /></span>
                                        </span>
                                    </span>
                                    <span class="np-account-row__end">
                                        <span class="np-account-pill np-account-pill--info">درخواست ثبت شد</span>
                                        <svg class="np-account-row__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                                    </span>
                                </a>
                                <div class="np-account-row-tools">
                                    <button type="button" class="np-copy-chip" data-copy-code="{{ $repair->tracking_reference }}" aria-label="کپی کد پیگیری {{ $repair->tracking_reference }}" title="کپی کد پیگیری">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                                        <span data-copy-code-label>کپی کد</span>
                                    </button>
                                </div>
                            </div>
                        </div>
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
                        @php
                            $reviewTone = match ($feedback->status) {
                                'approved' => 'success',
                                'rejected' => 'danger',
                                default => 'warning',
                            };
                        @endphp
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
                                    <span class="np-account-pill np-account-pill--{{ $reviewTone }}">{{ $feedbackStatuses[$feedback->status] ?? $feedback->status }}</span>
                                </div>
                            </div>
                            <p class="np-account-review__body">{{ $feedback->body }}</p>
                            @if($feedback->staff_reply)
                                <div class="np-account-review__reply">
                                    <div class="np-account-review__reply-head">
                                        <img src="{{ versioned_asset('brand/novinpay-np-blue.svg') }}" alt="" width="20" height="20" class="np-account-review__reply-logo" aria-hidden="true">
                                        <strong>پشتیبانی نوین‌پی</strong>
                                    </div>
                                    <p>{{ $feedback->staff_reply }}</p>
                                </div>
                            @endif
                            @if($feedback->product)
                                <div class="np-account-review__actions">
                                    <a class="np-device-action" href="{{ route('products.show', $feedback->product->slug) }}#product-review">مشاهده صفحه محصول</a>
                                    @if($feedback->kind === 'review')
                                        <a class="np-device-action np-device-action--primary" href="{{ route('products.show', $feedback->product->slug) }}?edit_review=1#product-review">ویرایش دیدگاه</a>
                                    @endif
                                </div>
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
                <section class="np-account-card np-account-profile-card">
                    <h2>اطلاعات شخصی و تنظیمات اطلاع‌رسانی</h2>
                    <form method="post" class="np-account-form" data-prevent-double-submit action="{{ route('account.profile') }}">
                        @csrf
                        <input type="hidden" name="email_notifications_present" value="1">
                        <div class="np-account-form__field np-account-profile-half">
                            <div class="np-float-field" data-float-field>
                                <input id="profile-name" name="name" placeholder=" " value="{{ old('name', $account->name) }}" autocomplete="name" minlength="2" maxlength="100" @class(['is-invalid' => $errors->has('name')]) @error('name') aria-invalid="true" @enderror required>
                                <label for="profile-name">نام و نام خانوادگی</label>
                            </div>
                            @error('name')<p class="np-field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="np-account-form__field np-account-profile-half">
                            <div class="np-float-field" data-float-field>
                                <input id="profile-email" name="email" type="email" placeholder=" " value="{{ old('email', $account->email) }}" autocomplete="email" maxlength="254" dir="ltr" @class(['is-invalid' => $errors->has('email')]) @error('email') aria-invalid="true" @enderror>
                                <label for="profile-email">نشانی ایمیل (اختیاری)</label>
                            </div>
                            @error('email')<p class="np-field-error" role="alert">{{ $message }}</p>@enderror
                            <div class="np-account-field-help">
                                @if($account->email)
                                    @if($account->email_verified_at)
                                        <span class="np-account-pill np-account-pill--success">ایمیل تأییدشده</span>
                                    @else
                                        <span class="np-account-pill np-account-pill--warning">در انتظار تأیید ایمیل</span>
                                    @endif
                                @else
                                    <small>ثبت ایمیل برای دریافت نسخه‌ای از رسیدها و وضعیت سفارش و تعمیر اختیاری است.</small>
                                @endif
                            </div>
                        </div>

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

                        <div class="np-notify-pref-box">
                            <label class="np-notify-switch" for="email-notifications-toggle">
                                <span class="np-notify-switch__copy">
                                    <strong>دریافت اطلاع‌رسانی از طریق ایمیل (در کنار پیامک)</strong>
                                    <small>ارسال وضعیت سفارش‌ها، پرونده‌های تعمیر و رسیدهای پرداخت به نشانی ایمیل ثبت‌شده</small>
                                </span>
                                <input
                                    type="checkbox"
                                    role="switch"
                                    id="email-notifications-toggle"
                                    name="email_notifications_enabled"
                                    value="1"
                                    class="np-switch-input"
                                    @checked((bool) old('email_notifications_enabled', $account->email_notifications_enabled ?? true))
                                >
                            </label>
                        </div>

                        <button class="np-account-primary" type="submit">ذخیره اطلاعات</button>
                    </form>

                    @if($account->email && ! $account->email_verified_at)
                        <form method="post" action="{{ route('account.profile.email.send') }}" class="mt-3" data-prevent-double-submit>
                            @csrf
                            <button type="submit" class="np-button np-button--outline">ارسال لینک تأیید به ایمیل ({{ $account->email }})</button>
                        </form>
                    @endif
                </section>
            @endif

            @if($section === 'addresses')
                @php
                    $activeAddressFormKey = $errors->any() ? (string) old('address_form_key', '') : '';
                    $openNewAddress = $addresses->isEmpty() || $activeAddressFormKey === 'new';
                @endphp
                <section class="np-account-card np-account-addresses-card" data-addresses-accordion>
                    <div class="np-account-card__heading">
                        <h2>آدرس‌های ذخیره‌شده</h2>
                    </div>
                    <script type="application/json" id="iran-locations-data">@json(config('iran_locations', []))</script>
                    <div class="np-address-list">
                        @forelse($addresses as $address)
                            @php
                                $panelId = 'address-edit-panel-'.$address->public_id;
                                $isOpen = $activeAddressFormKey === $address->public_id;
                            @endphp
                            <article class="np-address-card" data-address-card>
                                <div class="np-address-card__summary">
                                    <div class="np-address-card__info">
                                        <div class="np-address-card__top">
                                            <strong class="np-address-card__recipient">{{ $address->recipient }}</strong>
                                            <span class="np-address-card__location">{{ $address->province }}، {{ $address->city }}</span>
                                        </div>
                                        <p class="np-address-card__text">{{ $address->address }}</p>
                                        <div class="np-address-card__meta">
                                            <span>تلفن: <bdi dir="ltr">{{ \App\Support\FaNumber::digits($address->phone) }}</bdi></span>
                                            <span class="np-account-dot" aria-hidden="true">·</span>
                                            <span>کد پستی: <bdi dir="ltr">{{ \App\Support\FaNumber::digits($address->postal_code) }}</bdi></span>
                                        </div>
                                    </div>
                                    <div class="np-address-card__actions">
                                        <button
                                            type="button"
                                            class="np-device-action"
                                            data-address-accordion-toggle="{{ $panelId }}"
                                            aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                                            aria-controls="{{ $panelId }}"
                                        >
                                            <span>ویرایش</span>
                                            <svg class="np-account-address__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                                        </button>
                                        <form method="post" action="{{ route('account.address.remove', $address->public_id) }}" data-confirm="این آدرس از فهرست شما حذف شود؟ نشانی ارسال‌های قبلی باقی می‌ماند.">
                                            @csrf
                                            <button class="np-device-action np-device-action--danger" type="submit">حذف</button>
                                        </form>
                                    </div>
                                </div>
                                <div id="{{ $panelId }}" class="np-account-address-panel" data-address-accordion-panel {{ $isOpen ? '' : 'hidden' }}>
                                    <x-account.address-form :address="$address" />
                                </div>
                            </article>
                        @empty
                            <div class="np-account-empty">
                                <p>هنوز آدرسی ذخیره نکرده‌اید.</p>
                            </div>
                        @endforelse
                    </div>

                    <div class="np-account-address np-account-address--new">
                        @if($addresses->isNotEmpty())
                        <button
                            type="button"
                            class="np-account-add-btn"
                            data-address-accordion-toggle="account-new-address-panel"
                            aria-expanded="{{ $openNewAddress ? 'true' : 'false' }}"
                            aria-controls="account-new-address-panel"
                        >
                            <svg class="np-account-address__plus" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                            <span>افزودن آدرس جدید</span>
                        </button>
                        @else
                            <h3 class="np-account-address-subtitle">آدرس تحویل سفارش‌ها را ثبت کنید.</h3>
                        @endif
                        <div id="account-new-address-panel" class="np-account-address-panel" data-address-accordion-panel {{ $openNewAddress ? '' : 'hidden' }}>
                            <x-account.address-form />
                        </div>
                    </div>
                </section>
            @endif

            @if($section === 'security')
                <section class="np-account-card">
                    <h2>{{ $account->password ? 'تغییر رمز عبور' : 'انتخاب رمز عبور' }}</h2>
                    @php($showPasswordForm = ($canSetInitialPassword ?? false) || $recentOtp || $account->password)
                    @if($showPasswordForm)
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
                        <div class="np-account-security-info" role="status">
                            <p>برای انتخاب رمز، از حساب خارج شوید و دوباره با کد تأیید وارد شوید.</p>
                        </div>
                    @endif
                    @if($showPasswordForm && session('customer_password_offer'))
                        <form method="post" class="mt-5" action="{{ route('account.password.skip') }}">@csrf<button class="np-account-link">فعلاً نمی‌خواهم</button></form>
                    @endif
                </section>
            @endif
        </div>
    </div>
</div>
@endsection
