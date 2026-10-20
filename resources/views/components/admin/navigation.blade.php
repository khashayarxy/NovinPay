<nav aria-label="ناوبری مدیریت" class="admin-nav">
    @php($pendingPasswordChange = \App\Support\AdminAccess::account(auth()->user())?->must_change_password)
    @if(!$pendingPasswordChange) @can('repairs.view')<a href="{{ route('admin.repair-cases.index') }}" @if(request()->routeIs('admin.repair-cases.*')) aria-current="page" @endif><x-home.icon name="tool"/><span>عملیات تعمیر و پذیرش</span></a>@endcan @endif
    @foreach ([['dashboard.view', 'admin.dashboard', 'admin.dashboard', 'نمای کلی', 'building-bank'], ['products.view', 'admin.products.index', 'admin.products.*', 'محصولات', 'shopping-bag'], ['repairs.view', 'admin.repairs.index', 'admin.repairs.*', 'درخواست‌های آنلاین تعمیر', 'tool'], ['staff.manage', 'admin.staff.index', 'admin.staff.*', 'همکاران و دسترسی‌ها', 'user'], ['activity.view', 'admin.activities.index', 'admin.activities.*', 'تاریخچهٔ فعالیت', 'clock']] as [$ability, $route, $active, $label, $icon])
        @if(!$pendingPasswordChange) @can($ability)
            <a href="{{ route($route) }}" @if(request()->routeIs($active)) aria-current="page" @endif><x-home.icon :name="$icon" /><span>{{ $label }}</span></a>
        @endcan @endif
    @endforeach
    <a href="{{ route('admin.password.edit') }}" @if(request()->routeIs('admin.password.*')) aria-current="page" @endif><x-home.icon name="shield-check" /><span>امنیت حساب من</span></a>
    <a href="{{ route('home') }}"><x-home.icon name="arrow-left" /><span>مشاهدهٔ وب‌سایت</span></a>
</nav>
