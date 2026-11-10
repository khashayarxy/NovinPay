@extends('layouts.admin')
@section('title', 'نمای کلی')
@section('content')
    <header class="admin-page-heading admin-dashboard-heading">
        <div>
            <h1>نمای کلی</h1>
            <p>{{ \App\Support\FinanceTime::display(now(), withTime: false, withZone: false) }}</p>
        </div>
        <div class="admin-actions">
            @can('repairs.intake') @can('repairs.customer_data') @can('repairs.view')
                <a class="btn btn-primary" href="{{ route('admin.repair-cases.create') }}">پذیرش دستگاه</a>
            @endcan @endcan @endcan
        </div>
    </header>
    @if($repairCount !== null || $openRepairCount !== null || $pendingOrderCount !== null || $lowStockCount !== null)
        <section class="admin-panel admin-workbench" aria-labelledby="pending-work">
            <div class="admin-panel-heading"><h2 id="pending-work">کارهای جاری</h2></div>
            <div class="admin-task-list">
                @foreach ([
                    [$openRepairCount, 'پروندهٔ تعمیر باز', 'tool', 'admin.repair-cases.index', []],
                    [$repairCount, 'درخواست آنلاین تعمیر', 'clock', 'admin.repairs.index', []],
                    [$pendingOrderCount, 'سفارش باز', 'shopping-bag', 'admin.orders.index', []],
                    [$lowStockCount, 'کالای کم‌موجود', 'tag', 'admin.inventory.index', ['low' => '1']],
                ] as [$count, $label, $icon, $route, $parameters])
                    @if ($count !== null)
                        <a href="{{ route($route, $parameters) }}" @class(['admin-task', 'admin-task--pending' => $count > 0])>
                            <span class="admin-task-icon"><x-home.icon :name="$icon" /></span>
                            <strong>{{ \App\Support\FaNumber::digits(number_format($count)) }}</strong>
                            <span class="admin-task-label">{{ $label }}</span>
                            <x-home.icon class="admin-task-arrow" name="arrow-left" />
                        </a>
                    @endif
                @endforeach
            </div>
        </section>
    @endif
    <div class="admin-dashboard-grid">
        <div class="admin-dashboard-primary">
            @can('repairs.view')
            <section class="admin-panel" aria-labelledby="recent-requests"><div class="admin-panel-heading"><h2 id="recent-requests">درخواست‌های آنلاین اخیر</h2><a href="{{ route('admin.repairs.index') }}" class="admin-text-link">همهٔ درخواست‌ها <x-home.icon name="arrow-left" /></a></div>
                    @forelse($recentRepairs as $repair)
                        <a class="admin-request-row" href="{{ route('admin.repairs.show', $repair) }}"><span class="admin-icon"><x-home.icon name="tool" /></span><div><h3>درخواست {{ \App\Support\FaNumber::digits($repair->id) }} · <bdi>{{ $repair->device_brand->value }}</bdi></h3><p>{{ $repair->physical_case_exists ? 'پذیرش فیزیکی در پروندهٔ جداگانه ثبت شده است' : 'ثبت آنلاین؛ دریافت دستگاه تأیید نشده است' }}</p></div><x-home.icon name="arrow-left" /></a>
                    @empty
                        <x-admin.empty-state title="درخواست آنلاینی ثبت نشده است" icon="tool">درخواست‌های تازهٔ وب‌سایت اینجا نمایش داده می‌شوند.</x-admin.empty-state>
                    @endforelse
            </section>
            @else
                <section class="admin-panel">
                    <x-admin.empty-state title="دسترسی به درخواست‌ها ندارید" icon="shield-check">برای تغییر مجوز با مدیر کل هماهنگ کنید.</x-admin.empty-state>
                </section>
            @endcan
            <section class="admin-panel admin-shortcuts" aria-labelledby="shortcuts-title">
                <h2 id="shortcuts-title">دسترسی سریع</h2>
                <div class="admin-actions">
                    @can('products.manage')<a class="btn btn-secondary" href="{{ route('admin.products.create') }}">افزودن محصول</a>@endcan
                    @can('customers.view')<a class="btn btn-secondary" href="{{ route('admin.customers.index') }}">مشتریان</a>@endcan
                    @can('staff.manage')<a class="btn btn-secondary" href="{{ route('admin.staff.create') }}">تعریف همکار</a>@endcan
                    @if (config('support_chat.enabled'))
                        @can('support.view')
                            <a class="btn btn-secondary" href="{{ route('support.panel') }}" target="_blank" rel="nofollow noopener noreferrer">ورود به پشتیبانی آنلاین</a>
                        @endcan
                    @endif
                    <a class="btn btn-secondary" href="{{ route('admin.password.edit') }}">امنیت حساب من</a>
                </div>
            </section>
        </div>
        @can('activity.view')
        <section class="admin-panel" aria-labelledby="recent-activities"><div class="admin-panel-heading"><h2 id="recent-activities">آخرین فعالیت‌ها</h2><a href="{{ route('admin.activities.index') }}" class="admin-text-link">همهٔ سوابق <x-home.icon name="arrow-left" /></a></div>
                @if($recentActivities->isEmpty())
                    <x-admin.empty-state title="فعالیت تازه‌ای ثبت نشده است" icon="clock" />
                @else
                    <ul class="admin-activities"><x-admin.activity-list :activities="$recentActivities" /></ul>
                @endif
        </section>
        @endcan
    </div>
@endsection
