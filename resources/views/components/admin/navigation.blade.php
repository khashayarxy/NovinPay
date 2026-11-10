@props(['account'])
@php
    $allows = fn ($abilities) => collect((array) $abilities)->every(fn ($ability) => $account->isSuperAdmin()
        || (\App\Enums\AdminPermission::tryFrom($ability) !== null && in_array($ability, $account->permissions ?? [], true)));
    $groups = [
        'کسب‌وکار' => [
            ['dashboard.view', 'admin.dashboard', 'admin.dashboard', 'نمای کلی', 'building-bank'],
            ['repairs.view', 'admin.repair-cases.index', 'admin.repair-cases.*', 'تعمیرات', 'tool'],
            ['repairs.view', 'admin.repairs.index', 'admin.repairs.*', 'درخواست‌های تعمیر', 'tool'],
            ['orders.view', 'admin.orders.index', 'admin.orders.*', 'سفارش‌ها', 'shopping-bag'],
            ['products.view', 'admin.products.index', 'admin.products.*', 'محصولات', 'tag'],
            ['inventory.view', 'admin.inventory.index', 'admin.inventory.*', 'موجودی کالا', 'settings'],
        ],
        'مشتریان و امور مالی' => [
            ['customers.view', 'admin.customers.index', 'admin.customers.*', 'مشتریان', 'user'],
            ['consultations.view', 'admin.consultations.index', 'admin.consultations.*', 'مشاوره‌ها', 'headphones'],
            ['finance.view', 'admin.payments.index', 'admin.payments.*', 'پرداخت‌ها و رسیدها', 'building-bank'],
            ['finance.view', 'admin.finance.index', 'admin.finance.*', 'عملیات و گزارش مالی', 'building-bank'],
            [['finance.view', 'finance.details'], 'admin.deliveries.index', 'admin.deliveries.*', 'ارسال و تحویل', 'shopping-bag'],
        ],
        'مدیریت سایت' => [
            ['articles.view', 'admin.articles.index', 'admin.articles.*', 'مقاله‌ها', 'photo'],
            ['article_categories.manage', 'admin.article-categories.index', 'admin.article-categories.*', 'دسته‌های مقاله', 'tag'],
            ['maintenance.view', 'admin.maintenance.index', 'admin.maintenance.*', 'وضعیت سایت', 'settings'],
            ['staff.manage', 'admin.staff.index', 'admin.staff.*', 'دسترسی همکاران', 'user'],
            ['staff.manage', 'admin.test-customers.index', 'admin.test-customers.*', 'حساب‌های آزمایشی', 'user'],
            ['activity.view', 'admin.activities.index', 'admin.activities.*', 'سوابق فعالیت', 'clock'],
        ],
    ];
@endphp
<nav aria-label="ناوبری مدیریت" class="admin-nav">
    @unless($account->must_change_password)
        @foreach($groups as $label => $links)
            @php($visible = array_filter($links, fn ($link) => $allows($link[0])))
            @if($visible)
                <div class="admin-nav-group">
                    <p class="admin-sidebar-label">{{ $label }}</p>
                    @foreach($visible as [$ability, $route, $active, $text, $icon])
                        <a href="{{ route($route) }}" @if(request()->routeIs($active)) aria-current="page" @endif><x-home.icon :name="$icon" /><span>{{ $text }}</span></a>
                    @endforeach
                </div>
            @endif
        @endforeach
    @endunless
    <div class="admin-nav-group">
        <p class="admin-sidebar-label">حساب من</p>
        <a href="{{ route('admin.password.edit') }}" @if(request()->routeIs('admin.password.*')) aria-current="page" @endif><x-home.icon name="shield-check" /><span>امنیت حساب من</span></a>
        <a href="{{ route('home') }}"><x-home.icon name="arrow-left" /><span>مشاهدهٔ وب‌سایت</span></a>
    </div>
</nav>
