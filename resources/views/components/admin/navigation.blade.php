@props(['account'])
@php
    $allows = fn ($abilities) => collect((array) $abilities)->every(fn ($ability) => $account->isSuperAdmin()
        || (\App\Enums\AdminPermission::tryFrom($ability) !== null && in_array($ability, $account->permissions ?? [], true)));
    $canViewProducts = $allows('products.view');
    $groups = $account->must_change_password ? [] : [
        'تعمیرات' => ['tool', [
            ['repairs.view', 'admin.repair-cases.index', 'admin.repair-cases.*', 'پرونده‌های تعمیر'],
            ['repairs.view', 'admin.repairs.index', 'admin.repairs.*', 'درخواست‌های آنلاین'],
        ]],
        'سفارش‌ها و کالاها' => ['shopping-bag', [
            ['orders.view', 'admin.orders.index', 'admin.orders.*', 'لیست سفارش‌ها'],
            [$canViewProducts ? 'products.view' : 'inventory.view', $canViewProducts ? 'admin.products.index' : 'admin.inventory.index', ['admin.products.*', 'admin.inventory.*'], 'مدیریت محصولات و موجودی'],
        ]],
        'مشتریان' => ['user', [
            ['customers.view', 'admin.customers.index', 'admin.customers.*', 'لیست مشتریان'],
            ['consultations.view', 'admin.consultations.index', 'admin.consultations.*', 'لیست مشاوره‌ها'],
        ]],
        'پرداخت‌ها و مالی' => ['building-bank', [
            ['finance.view', 'admin.payments.index', 'admin.payments.*', 'پرداختی‌ها'],
            ['finance.view', 'admin.finance.index', 'admin.finance.*', 'تراکنش‌ها و گزارش‌ها'],
        ]],
        'ارسال و تحویل' => ['truck', [
            [['finance.view', 'finance.details'], 'admin.deliveries.index', 'admin.deliveries.*', 'مدیریت ارسال و تحویل'],
        ]],
        'مقالات' => ['photo', [
            ['articles.view', 'admin.articles.index', 'admin.articles.*', 'لیست مقالات'],
            ['article_categories.manage', 'admin.article-categories.index', 'admin.article-categories.*', 'دسته‌بندی مقالات'],
        ]],
        'مدیریت سایت' => ['world', [
            ['maintenance.view', 'admin.maintenance.index', 'admin.maintenance.*', 'وضعیت و نگهداری سایت'],
        ]],
        'دسترسی و سوابق' => ['shield-check', [
            ['staff.manage', 'admin.staff.index', 'admin.staff.*', 'دسترسی همکاران'],
            ['staff.manage', 'admin.test-customers.index', 'admin.test-customers.*', 'حساب‌های آزمایشی'],
            ['activity.view', 'admin.activities.index', 'admin.activities.*', 'سوابق فعالیت'],
        ]],
    ];
    $groups['حساب من'] = ['settings', [[null, 'admin.password.edit', 'admin.password.*', 'امنیت حساب من']]];
@endphp
<nav aria-label="ناوبری مدیریت" class="admin-nav">
    @if(!$account->must_change_password && $allows('dashboard.view'))
        <a href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif><x-home.icon name="layout-dashboard" />داشبورد مدیریت</a>
    @endif
    @foreach($groups as $label => [$icon, $links])
        @php($visible = array_values(array_filter($links, fn ($link) => $link[0] === null || $allows($link[0]))))
        @continue(!$visible)
        @php($current = collect($visible)->contains(fn ($link) => request()->routeIs(...(array) $link[2])))
        <div @class(['admin-nav-section', 'admin-nav-current' => $current])>
            <a class="admin-nav-category" href="{{ route($visible[0][1]) }}" data-admin-category><x-home.icon :name="$icon" /><span>{{ $label }}</span></a>
            <details class="admin-nav-group" name="admin-module" @if($current) open @endif>
                <summary aria-label="باز یا بسته کردن {{ $label }}"><span class="admin-nav-chevron" aria-hidden="true"></span></summary>
                <div class="admin-nav-children">
                    @foreach($visible as [$ability, $route, $active, $text])
                        <a href="{{ route($route) }}" @if(request()->routeIs(...(array) $active)) aria-current="page" @endif>{{ $text }}</a>
                    @endforeach
                </div>
            </details>
        </div>
    @endforeach
</nav>
