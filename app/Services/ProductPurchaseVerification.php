<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\Product;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ProductPurchaseVerification
{
    public function verified(CustomerAccount $account, Product $product): bool
    {
        if (! $account->is_active || $account->verified_at === null || ! $product->exists) {
            return false;
        }

        return DB::table('sales_orders')
            ->join('sales_order_lines', 'sales_order_lines.sales_order_id', '=', 'sales_orders.id')
            ->join('inventory_items', 'inventory_items.id', '=', 'sales_order_lines.inventory_item_id')
            ->where('sales_orders.status', 'fulfilled')->whereNotNull('sales_orders.fulfilled_at')
            ->where('sales_order_lines.is_current', true)
            ->where('sales_order_lines.quantity', '>', 0)
            ->where('inventory_items.product_id', $product->id)
            ->where(function (Builder $query) use ($account): void {
                $query->where('sales_orders.customer_account_id', $account->id);
                if ($account->customer_id !== null) {
                    $query->orWhere(fn (Builder $linked) => $linked->whereNull('sales_orders.customer_account_id')
                        ->where('sales_orders.customer_id', $account->customer_id));
                }
            })->exists();
    }
}
