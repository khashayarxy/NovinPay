<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CommerceAccess
{
    public static function permissions(string $action): array
    {
        return match ($action) {
            'item.create', 'item.configure' => ['inventory.view', 'inventory.configure'],
            'item.receive' => ['inventory.view', 'inventory.receive'],
            'item.adjust' => ['inventory.view', 'inventory.adjust'],
            'order.save' => ['orders.view', 'orders.create', 'orders.customer_data', 'customers.view', 'customers.contact', 'inventory.view'],
            'order.reserve', 'order.release', 'order.cancel' => ['orders.view', 'orders.reserve'],
            'order.confirm' => ['orders.view', 'orders.confirm'],
            'order.fulfill' => ['orders.view', 'orders.fulfill', 'orders.customer_data', 'customers.view', 'customers.contact'],
            default => [],
        };
    }

    public static function authorize(User $actor, string $action): void
    {
        $permissions = self::permissions($action);
        abort_if($permissions === [], 403);
        foreach ($permissions as $permission) {
            Gate::forUser($actor)->authorize($permission);
        }
    }

    public static function contact(): bool
    {
        return Gate::allows('orders.customer_data') && Gate::allows('customers.view') && Gate::allows('customers.contact');
    }
}
