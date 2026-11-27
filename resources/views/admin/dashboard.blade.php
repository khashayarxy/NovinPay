@extends('layouts.admin')

@section('title', 'داشبورد مدیریت')

@section('content')
    <header class="admin-page-heading admin-dashboard-heading">
        <div>
            <span class="admin-eyebrow"><x-finance-time :value="now()" :with-time="false" /></span>
            <h1>داشبورد مدیریت</h1>
        </div>
        <div class="admin-actions">
            @can('repairs.intake') @can('repairs.customer_data') @can('repairs.view')
                <a class="btn btn-primary" href="{{ route('admin.repair-cases.create') }}">پذیرش دستگاه</a>
            @endcan @endcan @endcan
            @if (config('support_chat.enabled'))
                @can('support.view')
                    <a class="btn btn-secondary" href="{{ route('support.panel') }}" target="_blank" rel="nofollow noopener noreferrer">ورود به پشتیبانی آنلاین</a>
                @endcan
            @endif
        </div>
    </header>

    @if($repairCount !== null || $openRepairCount !== null || $pendingOrderCount !== null || $lowStockCount !== null)
        <section aria-labelledby="pending-work">
            <h2 id="pending-work" class="sr-only">کارهای جاری</h2>
            <div class="admin-stat-grid">
                @foreach ([
                    [$openRepairCount, 'پروندهٔ تعمیر باز', 'tool', 'admin.repair-cases.index', [], 'در جریان بررسی یا تعمیر'],
                    [$repairCount, 'درخواست آنلاین تعمیر', 'clock', 'admin.repairs.index', [], 'در انتظار پذیرش'],
                    [$pendingOrderCount, 'سفارش باز', 'shopping-bag', 'admin.orders.index', [], 'پیش‌نویس، رزرو یا تأییدشده'],
                    [$lowStockCount, 'کالای کم‌موجود', 'tag', 'admin.inventory.index', ['low' => '1'], 'نیازمند بررسی موجودی'],
                ] as [$count, $label, $icon, $route, $parameters, $sub])
                    @if ($count !== null)
                        <a href="{{ route($route, $parameters) }}" @class(['admin-stat-card', 'admin-task', 'admin-task--pending' => $count > 0])>
                            <div class="admin-stat-card__top">
                                <span class="admin-stat-card__label admin-task-label">{{ $label }}</span>
                                <span class="admin-stat-card__icon admin-task-icon"><x-home.icon :name="$icon" /></span>
                            </div>
                            <strong class="admin-stat-card__value">{{ \App\Support\FaNumber::digits(number_format($count)) }}</strong>
                            <span class="admin-stat-card__sub">{{ $sub }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
        </section>
    @endif

    <section class="admin-quick-bar" aria-labelledby="shortcuts-title">
        <h2 id="shortcuts-title" class="admin-quick-bar__label">دسترسی سریع:</h2>
        @can('orders.view')
            <a class="btn btn-secondary btn-sm" href="{{ route('admin.orders.index') }}">سفارش‌ها</a>
        @endcan
        @can('products.manage')
            <a class="btn btn-secondary btn-sm" href="{{ route('admin.products.create') }}">افزودن محصول</a>
        @endcan
        @can('customers.view')
            <a class="btn btn-secondary btn-sm" href="{{ route('admin.customers.index') }}">مشتریان</a>
        @endcan
        @can('finance.view')
            <a class="btn btn-secondary btn-sm" href="{{ route('admin.payments.index') }}">پرداختی‌ها</a>
        @endcan
        @can('staff.manage')
            <a class="btn btn-secondary btn-sm" href="{{ route('admin.staff.create') }}">تعریف همکار</a>
        @endcan
        <a class="btn btn-secondary btn-sm" href="{{ route('admin.password.edit') }}">امنیت حساب من</a>
    </section>

    <div class="admin-dashboard-grid admin-dashboard-columns">
        <div class="admin-dashboard-primary">
            @can('repairs.view')
                <section class="admin-panel" aria-labelledby="recent-requests">
                    <div class="admin-panel-heading">
                        <h2 id="recent-requests">درخواست‌های آنلاین اخیر</h2>
                        <a href="{{ route('admin.repairs.index') }}" class="admin-text-link">همهٔ درخواست‌ها <x-home.icon name="arrow-left" /></a>
                    </div>
                    @forelse($recentRepairs as $repair)
                        @php
                            $deviceTitle = \App\Support\RepairIdentity::deviceLabel(
                                $repair->device_brand,
                                ($canReadRepairCustomer ?? false) ? $repair->device_model : null
                            );
                            $handover = ($canReadRepairCustomer ?? false) ? $repair->handoverBadge() : null;
                        @endphp
                        <a class="admin-request-row" href="{{ route('admin.repairs.show', $repair) }}">
                            <span class="admin-icon"><x-home.icon name="tool" /></span>
                            <div class="min-w-0 flex-1">
                                <h3>
                                    @if(($canReadRepairCustomer ?? false) && $repair->customer_name)
                                        {{ $repair->customer_name }} · {{ \App\Support\LatinText::html($deviceTitle) }}
                                    @else
                                        درخواست #{{ \App\Support\FaNumber::digits($repair->id) }} · {{ \App\Support\LatinText::html($deviceTitle) }}
                                    @endif
                                </h3>
                                <p class="flex flex-wrap items-center gap-2 mt-1">
                                    @if($canReadRepairCustomer ?? false)
                                        <bdi dir="ltr" class="repair-code-chip">{{ \App\Support\FaNumber::digits($repair->tracking_reference) }}</bdi>
                                    @endif
                                    @if($handover)
                                        <x-admin.status :tone="$handover['tone']">{{ $handover['label'] }}</x-admin.status>
                                    @endif
                                    <x-admin.status :tone="$repair->physical_case_exists ? 'success' : 'warning'">{{ $repair->physical_case_exists ? 'پذیرش‌شده' : 'در انتظار پذیرش' }}</x-admin.status>
                                    <span><x-finance-time :value="$repair->created_at" /></span>
                                </p>
                            </div>
                            <x-home.icon name="arrow-left" />
                        </a>
                    @empty
                        <x-admin.empty-state title="درخواست آنلاینی ثبت نشده است" icon="tool">درخواست‌های تازهٔ وب‌سایت اینجا نمایش داده می‌شوند.</x-admin.empty-state>
                    @endforelse
                </section>
            @else
                <section class="admin-panel">
                    <x-admin.empty-state title="دسترسی به درخواست‌ها ندارید" icon="shield-check">برای تغییر مجوز با مدیر کل هماهنگ کنید.</x-admin.empty-state>
                </section>
            @endcan
        </div>

        @can('activity.view')
            <section class="admin-panel" aria-labelledby="recent-activities">
                <div class="admin-panel-heading">
                    <h2 id="recent-activities">آخرین فعالیت‌ها</h2>
                    <a href="{{ route('admin.activities.index') }}" class="admin-text-link">همهٔ سوابق <x-home.icon name="arrow-left" /></a>
                </div>
                @if($recentActivities->isEmpty())
                    <x-admin.empty-state title="فعالیت تازه‌ای ثبت نشده است" icon="clock" />
                @else
                    <ul class="admin-activities"><x-admin.activity-list :activities="$recentActivities" :meta="$activityMeta" /></ul>
                @endif
            </section>
        @endcan
    </div>
@endsection
