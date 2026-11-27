<header class="admin-page-heading">
    <div>
        <span class="admin-eyebrow">دفتر مالی و تسویه</span>
        <h1>{{ $heading }}</h1>
    </div>
    <nav class="admin-view-switch mb-0" aria-label="بخش‌های مالی">
        <a href="{{ route('admin.finance.index') }}" @if(request()->routeIs('admin.finance.index')) aria-current="page" @endif>تراکنش‌ها</a>
        <a href="{{ route('admin.finance.reports') }}" @if(request()->routeIs('admin.finance.reports')) aria-current="page" @endif>گزارش‌ها</a>
        <a href="{{ route('admin.payments.index') }}" @if(request()->routeIs('admin.payments.*')) aria-current="page" @endif>پرداختی‌ها</a>
    </nav>
</header>
<x-admin.errors/>
