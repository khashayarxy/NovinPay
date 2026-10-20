@extends('layouts.admin')
@section('title', 'نمای کلی')
@section('content')
    <header class="admin-page-heading"><div><p class="admin-eyebrow">پیشخوان نوین‌پی</p><h1>نمای کلی کسب‌وکار</h1><p>سلام {{ auth()->user()->name }}؛ اطلاعات این صفحه از داده‌های ثبت‌شدهٔ سامانه است.</p></div><span class="admin-status"><span aria-hidden="true">●</span> حساب فعال</span></header>
    <div class="admin-stats">
        @foreach ([['count' => $productCount, 'label' => 'محصول ثبت‌شده', 'icon' => 'shopping-bag', 'route' => 'admin.products.index', 'note' => 'کاتالوگ و پیش‌نویس‌ها'], ['count' => $publishedCount, 'label' => 'محصول منتشرشده', 'icon' => 'check', 'route' => 'admin.products.index', 'note' => 'قابل مشاهده در وب‌سایت'], ['count' => $repairCount, 'label' => 'درخواست آنلاین تعمیر', 'icon' => 'tool', 'route' => 'admin.repairs.index', 'note' => 'دریافت فیزیکی تأیید نشده'], ['count' => $staffCount, 'label' => 'همکار فعال', 'icon' => 'user', 'route' => 'admin.staff.index', 'note' => 'با دسترسی مشخص']] as $stat)
            @if ($stat['count'] !== null)
                <a class="admin-stat" href="{{ route($stat['route']) }}"><span class="admin-icon"><x-home.icon :name="$stat['icon']" /></span><p>{{ $stat['label'] }}</p><strong>{{ \App\Support\FaNumber::digits(number_format($stat['count'])) }}</strong><span>{{ $stat['note'] }}</span></a>
            @endif
        @endforeach
    </div>
    <div class="admin-dashboard-grid">
        <section class="admin-panel" aria-labelledby="recent-requests"><div class="admin-panel-heading"><div><p class="admin-eyebrow">دید عملیاتی</p><h2 id="recent-requests">درخواست‌های آنلاین اخیر</h2></div>@can('repairs.view')<a href="{{ route('admin.repairs.index') }}" class="admin-text-link">همه درخواست‌ها ←</a>@endcan</div>
            @can('repairs.view')
                @forelse($recentRepairs as $repair)
                    <a class="admin-request-row" href="{{ route('admin.repairs.show', $repair) }}"><span class="admin-icon"><x-home.icon name="tool" /></span><div><h3>درخواست {{ \App\Support\FaNumber::digits($repair->id) }} · <bdi>{{ $repair->device_brand->value }}</bdi></h3><p>ثبت آنلاین؛ دریافت دستگاه تأیید نشده است</p></div><x-home.icon name="arrow-left" /></a>
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
    <section class="admin-shortcuts" aria-labelledby="shortcuts-title"><div><h2 id="shortcuts-title">دسترسی سریع</h2><p>عملیات در دسترس حساب شما</p></div><div class="admin-actions">@can('products.manage')<a class="btn btn-primary" href="{{ route('admin.products.create') }}">افزودن محصول</a>@endcan @can('staff.manage')<a class="btn btn-secondary" href="{{ route('admin.staff.create') }}">تعریف همکار</a>@endcan<a class="btn btn-secondary" href="{{ route('admin.password.edit') }}">امنیت حساب من</a></div></section>
@endsection
