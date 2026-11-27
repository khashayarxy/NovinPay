@props(['account'])
@php
    $allows = fn ($abilities) => collect((array) $abilities)->every(fn ($ability) => $account->hasFullAccess()
        || (\App\Enums\AdminPermission::tryFrom($ability) !== null && in_array($ability, $account->permissions ?? [], true)));
    $canViewProducts = $allows('products.view');

    $dailyGroups = $account->must_change_password ? [] : [
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
        'مالی و ارسال' => ['building-bank', [
            ['finance.view', 'admin.payments.index', 'admin.payments.*', 'پرداختی‌ها'],
            ['finance.view', 'admin.finance.index', 'admin.finance.*', 'تراکنش‌ها و گزارش‌ها'],
            [['finance.view', 'finance.details'], 'admin.deliveries.index', 'admin.deliveries.*', 'ارسال و تحویل'],
        ]],
    ];

    $systemGroups = $account->must_change_password ? [] : [
        'مقالات' => ['photo', [
            ['articles.view', 'admin.articles.index', 'admin.articles.*', 'لیست مقالات'],
            ['article_categories.manage', 'admin.article-categories.index', 'admin.article-categories.*', 'دسته‌بندی مقالات'],
        ]],
        'وضعیت و نگهداری سایت' => ['world', [
            ['maintenance.view', 'admin.maintenance.index', 'admin.maintenance.*', 'وضعیت و نگهداری سایت'],
            ['maintenance.view', 'admin.system-health.index', 'admin.system-health.*', 'سلامت سیستم و لاگ‌ها'],
        ]],
        'دسترسی و سوابق' => ['shield-check', [
            ['staff.manage', 'admin.staff.index', 'admin.staff.*', 'دسترسی همکاران'],
            ['staff.manage', 'admin.test-customers.index', 'admin.test-customers.*', 'حساب‌های آزمایشی'],
            ['activity.view', 'admin.activities.index', 'admin.activities.*', 'سوابق فعالیت'],
        ]],
    ];
    $systemGroups['امنیت حساب من'] = ['settings', [[null, 'admin.password.edit', 'admin.password.*', 'امنیت حساب من']]];

    $tiers = [
        ['عملیات روزانه', $dailyGroups, true],
        ['محتوا و تنظیمات', $systemGroups, false],
    ];
    $hasExpandedGroup = false;
@endphp
<nav aria-label="ناوبری مدیریت" class="admin-nav">
    @if(!$account->must_change_password && $allows('dashboard.view'))
        <a class="admin-nav-direct" href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>
            <x-home.icon name="layout-dashboard" /><span>داشبورد مدیریت</span>
        </a>
    @endif

    @foreach($tiers as [$tierLabel, $groups, $isPrimary])
        @php
            $renderedGroups = [];
            foreach ($groups as $label => [$icon, $links]) {
                $visible = array_values(array_filter($links, fn ($link) => $link[0] === null || $allows($link[0])));
                if ($visible) {
                    $renderedGroups[$label] = [$icon, $visible];
                }
            }
        @endphp
        @continue(!$renderedGroups)
        <div @class(['admin-nav-tier', 'admin-nav-tier--secondary' => !$isPrimary])>
            <p class="admin-nav-tier-label">{{ $tierLabel }}</p>
            @foreach($renderedGroups as $label => [$icon, $visible])
                @php
                    $current = collect($visible)->contains(fn ($link) => request()->routeIs(...(array) $link[2]));
                    $isOpen = $current && ! $hasExpandedGroup;
                    if ($isOpen) {
                        $hasExpandedGroup = true;
                    }
                    $subId = 'admin-nav-sub-'.$loop->parent->index.'-'.$loop->index;
                @endphp
                @if(count($visible) === 1)
                    <a class="admin-nav-direct" href="{{ route($visible[0][1]) }}" data-admin-category @if($current) aria-current="page" @endif>
                        <x-home.icon :name="$icon" /><span>{{ $visible[0][3] }}</span>
                    </a>
                @else
                    <div @class(['admin-nav-section', 'admin-nav-current' => $current])>
                        <div class="admin-nav-group" @if($isOpen) open @endif>
                            <button type="button" class="admin-nav-group-trigger" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-controls="{{ $subId }}">
                                <span class="admin-nav-group-label"><x-home.icon :name="$icon" /><span>{{ $label }}</span></span>
                                <svg class="admin-nav-chevron-svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                            <div id="{{ $subId }}" class="admin-nav-children">
                                @foreach($visible as [$ability, $route, $active, $text])
                                    <a href="{{ route($route) }}" @if(request()->routeIs(...(array) $active)) aria-current="page" @endif>{{ $text }}</a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @endforeach
</nav>
