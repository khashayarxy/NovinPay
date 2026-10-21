<?php

namespace App\Support;

use App\Enums\AdminPermission;
use App\Models\AdminAccount;
use App\Models\User;

final class AdminAccess
{
    public static function account(User $user): ?AdminAccount
    {
        // Query persisted grants every time; never trust a cached relation or email.
        return $user->exists ? AdminAccount::query()->where('user_id', $user->getKey())
            ->where('is_active', true)->where(function ($query): void {
                $query->where(fn ($staff) => $staff->where('role', 'staff')->whereNull('owner_slot'))
                    ->orWhere(fn ($owner) => $owner->where('role', 'super_admin')->where('owner_slot', 1));
            })->first() : null;
    }

    public static function allows(User $user, AdminPermission $permission): bool
    {
        $account = self::account($user);

        return $account && ($account->isSuperAdmin() || in_array($permission->value, $account->permissions ?? [], true));
    }

    public static function landingUrl(User $user): string
    {
        foreach ([AdminPermission::Dashboard->value => 'admin.dashboard', AdminPermission::ArticlesView->value => 'admin.articles.index', AdminPermission::ArticleCategoriesManage->value => 'admin.article-categories.index', AdminPermission::FinanceView->value => 'admin.finance.index', AdminPermission::OrdersView->value => 'admin.orders.index', AdminPermission::InventoryView->value => 'admin.inventory.index', AdminPermission::CustomersView->value => 'admin.customers.index', AdminPermission::ConsultationsView->value => 'admin.consultations.index', AdminPermission::ProductsView->value => 'admin.products.index', AdminPermission::RepairsView->value => 'admin.repairs.index', AdminPermission::ActivityView->value => 'admin.activities.index'] as $permission => $route) {
            if (self::allows($user, AdminPermission::from($permission))) {
                return route($route);
            }
        }

        return route('admin.password.edit');
    }
}
