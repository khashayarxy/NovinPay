<nav aria-label="ناوبری مدیریت" class="admin-nav">
    @php
        // One persisted snapshot for navigation only. Routes and services reauthorize every action.
        $navigationAccount = \App\Support\AdminAccess::account(auth()->user());
        $pendingPasswordChange = $navigationAccount?->must_change_password;
        $navigationAllows = fn ($ability) => $navigationAccount && ($navigationAccount->isSuperAdmin() || ($ability !== 'staff.manage' && \App\Enums\AdminPermission::tryFrom($ability) !== null && in_array($ability, $navigationAccount->permissions ?? [], true)));
    @endphp
    @if(!$pendingPasswordChange)
        @if($navigationAllows('articles.view'))<a href="{{ route('admin.articles.index') }}" @if(request()->routeIs('admin.articles.*')) aria-current="page" @endif><x-home.icon name="photo"/><span>مقاله‌ها و انتشار</span></a>@endif
        @if($navigationAllows('article_categories.manage'))<a href="{{ route('admin.article-categories.index') }}" @if(request()->routeIs('admin.article-categories.*')) aria-current="page" @endif><x-home.icon name="tag"/><span>دسته‌های مقاله</span></a>@endif
        @if($navigationAllows('finance.view'))<a href="{{ route('admin.finance.index') }}" @if(request()->routeIs('admin.finance.*')) aria-current="page" @endif><x-home.icon name="building-bank"/><span>عملیات و گزارش مالی</span></a>@endif
        @if($navigationAllows('orders.view'))<a href="{{ route('admin.orders.index') }}" @if(request()->routeIs('admin.orders.*')) aria-current="page" @endif><x-home.icon name="shopping-bag"/><span>سفارش‌های داخلی</span></a>@endif
        @if($navigationAllows('inventory.view'))<a href="{{ route('admin.inventory.index') }}" @if(request()->routeIs('admin.inventory.*')) aria-current="page" @endif><x-home.icon name="settings"/><span>موجودی و گردش کالا</span></a>@endif
        @if($navigationAllows('customers.view'))<a href="{{ route('admin.customers.index') }}" @if(request()->routeIs('admin.customers.*')) aria-current="page" @endif><x-home.icon name="user"/><span>دفتر مشتریان</span></a>@endif
        @if($navigationAllows('consultations.view'))<a href="{{ route('admin.consultations.index') }}" @if(request()->routeIs('admin.consultations.*')) aria-current="page" @endif><x-home.icon name="headphones"/><span>صندوق مشاوره</span></a>@endif
    @endif
    @if(!$pendingPasswordChange) @if($navigationAllows('repairs.view'))<a href="{{ route('admin.repair-cases.index') }}" @if(request()->routeIs('admin.repair-cases.*')) aria-current="page" @endif><x-home.icon name="tool"/><span>عملیات تعمیر و پذیرش</span></a>@endif @endif
    @foreach ([['dashboard.view', 'admin.dashboard', 'admin.dashboard', 'نمای کلی', 'building-bank'], ['products.view', 'admin.products.index', 'admin.products.*', 'محصولات', 'shopping-bag'], ['repairs.view', 'admin.repairs.index', 'admin.repairs.*', 'درخواست‌های آنلاین تعمیر', 'tool'], ['staff.manage', 'admin.staff.index', 'admin.staff.*', 'همکاران و دسترسی‌ها', 'user'], ['activity.view', 'admin.activities.index', 'admin.activities.*', 'تاریخچهٔ فعالیت', 'clock']] as [$ability, $route, $active, $label, $icon])
        @if(!$pendingPasswordChange) @if($navigationAllows($ability))
            <a href="{{ route($route) }}" @if(request()->routeIs($active)) aria-current="page" @endif><x-home.icon :name="$icon" /><span>{{ $label }}</span></a>
        @endif @endif
    @endforeach
    <a href="{{ route('admin.password.edit') }}" @if(request()->routeIs('admin.password.*')) aria-current="page" @endif><x-home.icon name="shield-check" /><span>امنیت حساب من</span></a>
    <a href="{{ route('home') }}"><x-home.icon name="arrow-left" /><span>مشاهدهٔ وب‌سایت</span></a>
</nav>
