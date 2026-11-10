@props(['account'])
@php
    $allows = fn ($abilities) => collect((array) $abilities)->every(fn ($ability) => $account->isSuperAdmin()
        || (\App\Enums\AdminPermission::tryFrom($ability) !== null && in_array($ability, $account->permissions ?? [], true)));
    $groups = [
        'تعمیرات' => [
            ['repairs.view', 'admin.repair-cases.index', 'admin.repair-cases.*', 'تعمیرات'],
            ['repairs.view', 'admin.repairs.index', 'admin.repairs.*', 'درخواست‌های تعمیر'],
        ],
        'فروش و انبار' => [
            ['orders.view', 'admin.orders.index', 'admin.orders.*', 'سفارش‌ها'],
            ['products.view', 'admin.products.index', 'admin.products.*', 'محصولات'],
            ['inventory.view', 'admin.inventory.index', 'admin.inventory.*', 'موجودی کالا'],
        ],
        'مشتریان' => [
            ['customers.view', 'admin.customers.index', 'admin.customers.*', 'مشتریان'],
            ['consultations.view', 'admin.consultations.index', 'admin.consultations.*', 'مشاوره‌ها'],
        ],
        'مالی و ارسال' => [
            ['finance.view', 'admin.payments.index', 'admin.payments.*', 'پرداخت‌ها و رسیدها'],
            ['finance.view', 'admin.finance.index', 'admin.finance.*', 'عملیات و گزارش مالی'],
            [['finance.view', 'finance.details'], 'admin.deliveries.index', 'admin.deliveries.*', 'ارسال و تحویل'],
        ],
        'سایت' => [
            ['articles.view', 'admin.articles.index', 'admin.articles.*', 'مقاله‌ها'],
            ['article_categories.manage', 'admin.article-categories.index', 'admin.article-categories.*', 'دسته‌های مقاله'],
            ['maintenance.view', 'admin.maintenance.index', 'admin.maintenance.*', 'وضعیت سایت'],
        ],
        'دسترسی و سوابق' => [
            ['staff.manage', 'admin.staff.index', 'admin.staff.*', 'دسترسی همکاران'],
            ['staff.manage', 'admin.test-customers.index', 'admin.test-customers.*', 'حساب‌های آزمایشی'],
            ['activity.view', 'admin.activities.index', 'admin.activities.*', 'سوابق فعالیت'],
        ],
    ];
    $icons = ['tool', 'shopping-bag', 'user', 'building-bank', 'photo', 'shield-check'];
@endphp
<nav aria-label="ناوبری مدیریت" class="admin-nav">
    @unless($account->must_change_password)
        @if($allows('dashboard.view'))
            <a href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif><x-home.icon name="building-bank" />نمای کلی</a>
        @endif
        @foreach($groups as $label => $links)
            @php($visible = array_filter($links, fn ($link) => $allows($link[0])))
            @if($visible)
                <details class="admin-nav-group" name="admin-module" @if(collect($visible)->contains(fn ($link) => request()->routeIs($link[2]))) open @endif>
                    <summary><x-home.icon :name="$icons[$loop->index]" /><span>{{ $label }}</span></summary>
                    <div class="admin-nav-children">
                        @foreach($visible as [$ability, $route, $active, $text])
                            <a href="{{ route($route) }}" @if(request()->routeIs($active)) aria-current="page" @endif>{{ $text }}</a>
                        @endforeach
                    </div>
                </details>
            @endif
        @endforeach
    @endunless
    <details class="admin-nav-group" name="admin-module" @if(request()->routeIs('admin.password.*')) open @endif>
        <summary><x-home.icon name="settings" /><span>حساب من</span></summary>
        <div class="admin-nav-children">
        <a href="{{ route('admin.password.edit') }}" @if(request()->routeIs('admin.password.*')) aria-current="page" @endif><x-home.icon name="shield-check" /><span>امنیت حساب من</span></a>
        <a href="{{ route('home') }}"><x-home.icon name="arrow-left" /><span>مشاهدهٔ وب‌سایت</span></a>
        </div>
    </details>
</nav>
