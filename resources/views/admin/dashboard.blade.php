@extends('layouts.admin')
@section('title', 'نمای کلی')
@section('content')
    <header class="admin-page-heading admin-overview-heading">
        <div><h1>نمای کلی کسب‌وکار</h1><p>سلام {{ auth()->user()->name }}، کارهای جاری را از اینجا دنبال کنید.</p></div>
        <div class="admin-actions">
            @can('repairs.intake') @can('repairs.customer_data') @can('repairs.view')
                <a class="btn btn-primary" href="{{ route('admin.repair-cases.create') }}">پذیرش دستگاه</a>
            @endcan @endcan @endcan
            @can('orders.view')<a class="btn btn-secondary" href="{{ route('admin.orders.index') }}">پیگیری سفارش‌ها</a>@endcan
        </div>
    </header>
    <div class="admin-stats">
        @foreach ([
            [$repairCount, 'درخواست آنلاین تعمیر', 'tool', 'admin.repairs.index', [], 'در انتظار دریافت دستگاه'],
            [$openRepairCount, 'پروندهٔ تعمیر باز', 'tool', 'admin.repair-cases.index', [], $readyRepairCount === null ? '' : \App\Support\FaNumber::digits($readyRepairCount).' آمادهٔ تحویل'],
            [$pendingOrderCount, 'سفارش باز', 'shopping-bag', 'admin.orders.index', [], 'پیش‌نویس، رزرو یا تأییدشده'],
            [$lowStockCount, 'کالای کم‌موجود', 'tag', 'admin.inventory.index', ['low' => '1'], 'بر اساس موجودی آزاد'],
        ] as [$count, $label, $icon, $route, $parameters, $note])
            @if ($count !== null)
                <a class="admin-stat" href="{{ route($route, $parameters) }}">
                    <span class="admin-icon"><x-home.icon :name="$icon" /></span>
                    <p>{{ $label }}</p><strong>{{ \App\Support\FaNumber::digits(number_format($count)) }}</strong><span>{{ $note }}</span>
                </a>
            @endif
        @endforeach
    </div>
    <div class="admin-dashboard-grid">
        <section class="admin-panel" aria-labelledby="recent-requests"><div class="admin-panel-heading"><div><p class="admin-eyebrow">دید عملیاتی</p><h2 id="recent-requests">درخواست‌های آنلاین اخیر</h2></div>@can('repairs.view')<a href="{{ route('admin.repairs.index') }}" class="admin-text-link">همه درخواست‌ها ←</a>@endcan</div>
            @can('repairs.view')
                @forelse($recentRepairs as $repair)
                    <a class="admin-request-row" href="{{ route('admin.repairs.show', $repair) }}"><span class="admin-icon"><x-home.icon name="tool" /></span><div><h3>درخواست {{ \App\Support\FaNumber::digits($repair->id) }} · <bdi>{{ $repair->device_brand->value }}</bdi></h3><p>{{ $repair->physical_case_exists ? 'پذیرش فیزیکی در پروندهٔ جداگانه ثبت شده است' : 'ثبت آنلاین؛ دریافت دستگاه تأیید نشده است' }}</p></div><x-home.icon name="arrow-left" /></a>
                @empty
                    <div class="admin-empty"><x-home.icon name="tool" /><h3>هنوز درخواستی ثبت نشده است</h3><p>درخواست‌های فرم عمومی پس از ثبت در اینجا قرار می‌گیرند.</p></div>
                @endforelse
            @else
                <div class="admin-empty"><x-home.icon name="shield-check" /><h3>دسترسی به درخواست‌ها ندارید</h3><p>برای تغییر مجوز با مدیر کل هماهنگ کنید.</p></div>
            @endcan
            <p class="admin-footnote">این بخش پذیرش فیزیکی، هزینه، تعمیر یا تحویل دستگاه را تأیید نمی‌کند.</p>
        </section>
        <section class="admin-panel" aria-labelledby="recent-activities"><div class="admin-panel-heading"><div><p class="admin-eyebrow">آخرین رویدادها</p><h2 id="recent-activities">تاریخچهٔ فعالیت</h2></div>@can('activity.view')<a href="{{ route('admin.activities.index') }}" class="admin-text-link">مشاهده همه ←</a>@endcan</div>
            @can('activity.view')<ul class="admin-activities"><x-admin.activity-list :activities="$recentActivities" /></ul>@else<div class="admin-empty"><x-home.icon name="shield-check" /><h3>دسترسی به تاریخچه ندارید</h3><p>این بخش به مجوز جداگانه نیاز دارد.</p></div>@endcan
        </section>
    </div>
    <section class="admin-shortcuts" aria-labelledby="shortcuts-title">
        <div><h2 id="shortcuts-title">دسترسی سریع</h2>
            @if($productCount !== null)<p>{{ \App\Support\FaNumber::digits($productCount) }} محصول، {{ \App\Support\FaNumber::digits($publishedCount) }} منتشرشده</p>@endif
            @if($staffCount !== null)<p>{{ \App\Support\FaNumber::digits($staffCount) }} همکار فعال</p>@endif
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
