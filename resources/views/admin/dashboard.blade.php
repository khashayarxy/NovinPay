@extends('layouts.admin')
@section('title', 'نمای کلی')
@section('content')
    <header class="admin-page-heading">
        <h1>نمای کلی</h1>
        <div class="admin-actions">
            @can('repairs.intake') @can('repairs.customer_data') @can('repairs.view')
                <a class="btn btn-primary" href="{{ route('admin.repair-cases.create') }}">پذیرش دستگاه</a>
            @endcan @endcan @endcan
        </div>
    </header>
    @if($repairCount !== null || $openRepairCount !== null || $pendingOrderCount !== null || $lowStockCount !== null)
        <section class="admin-panel mb-4" aria-labelledby="pending-work">
            <h2 id="pending-work">کارهای جاری</h2>
            <div class="admin-task-list">
                @foreach ([
                    [$repairCount, 'درخواست آنلاین تعمیر', 'admin.repairs.index', []],
                    [$openRepairCount, 'پروندهٔ تعمیر باز', 'admin.repair-cases.index', []],
                    [$pendingOrderCount, 'سفارش باز', 'admin.orders.index', []],
                    [$lowStockCount, 'کالای کم‌موجود', 'admin.inventory.index', ['low' => '1']],
                ] as [$count, $label, $route, $parameters])
                    @if ($count !== null)
                        <a href="{{ route($route, $parameters) }}"><span>{{ $label }}</span><strong>{{ \App\Support\FaNumber::digits(number_format($count)) }}</strong></a>
                    @endif
                @endforeach
            </div>
        </section>
    @endif
    <div class="admin-dashboard-grid">
        <section class="admin-panel" aria-labelledby="recent-requests"><div class="admin-panel-heading"><div><h2 id="recent-requests">درخواست‌های آنلاین اخیر</h2></div>@can('repairs.view')<a href="{{ route('admin.repairs.index') }}" class="admin-text-link">همه درخواست‌ها ←</a>@endcan</div>
            @can('repairs.view')
                @forelse($recentRepairs as $repair)
                    <a class="admin-request-row" href="{{ route('admin.repairs.show', $repair) }}"><span class="admin-icon"><x-home.icon name="tool" /></span><div><h3>درخواست {{ \App\Support\FaNumber::digits($repair->id) }} · <bdi>{{ $repair->device_brand->value }}</bdi></h3><p>{{ $repair->physical_case_exists ? 'پذیرش فیزیکی در پروندهٔ جداگانه ثبت شده است' : 'ثبت آنلاین؛ دریافت دستگاه تأیید نشده است' }}</p></div><x-home.icon name="arrow-left" /></a>
                @empty
                    <p class="admin-hint">درخواست آنلاینی ثبت نشده است.</p>
                @endforelse
            @else
                <div class="admin-empty"><x-home.icon name="shield-check" /><h3>دسترسی به درخواست‌ها ندارید</h3><p>برای تغییر مجوز با مدیر کل هماهنگ کنید.</p></div>
            @endcan
        </section>
        <section class="admin-panel" aria-labelledby="recent-activities"><div class="admin-panel-heading"><div><h2 id="recent-activities">تاریخچهٔ فعالیت</h2></div>@can('activity.view')<a href="{{ route('admin.activities.index') }}" class="admin-text-link">مشاهده همه ←</a>@endcan</div>
            @can('activity.view')
                @if($recentActivities->isEmpty())
                    <p class="admin-hint">فعالیت تازه‌ای ثبت نشده است.</p>
                @else
                    <ul class="admin-activities"><x-admin.activity-list :activities="$recentActivities" /></ul>
                @endif
            @else
                <p class="admin-hint">دسترسی به تاریخچه ندارید.</p>
            @endcan
        </section>
    </div>
    <section class="admin-shortcuts" aria-labelledby="shortcuts-title">
        <div><h2 id="shortcuts-title">دسترسی سریع</h2>
        </div>
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
@endsection
