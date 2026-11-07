<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderEvent;
use App\Models\SalesOrderLine;
use App\Support\OrderMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreCheckout
{
    public function offer(Product $product, bool $lock = false): ?InventoryItem
    {
        if (! $product->is_published || $product->is_sample || ! $product->price || $product->price <= 0 || $product->stock_status->value === 'out_of_stock') {
            return null;
        }
        $query = InventoryItem::query()->where('product_id', $product->id)->where('condition', $product->condition->value)->orderBy('id');
        $items = ($lock ? $query->lockForUpdate() : $query)->get();

        // Ambiguous catalogue/SKU mapping is not an invitation to choose arbitrary stock.
        return $items->count() === 1 && $items->first()->available > 0 ? $items->first() : null;
    }

    public function create(CustomerAccount $account, array $cart, string $key): SalesOrder
    {
        abort_unless(Str::isUuid($key) && count($cart) > 0 && count($cart) <= 30, 422);
        ksort($cart);

        return DB::transaction(function () use ($account, $cart, $key): SalesOrder {
            $digest = hash_hmac('sha256', json_encode($cart, JSON_THROW_ON_ERROR), config('app.key'));
            $fresh = CustomerAccount::query()->lockForUpdate()->findOrFail($account->id);
            abort_unless($fresh->is_active && $fresh->session_version === $account->session_version && $fresh->customer_id, 403, 'ابتدا پروفایل و اتصال حساب خود را تکمیل کنید.');
            $customer = Customer::query()->lockForUpdate()->findOrFail($fresh->customer_id);
            abort_unless($customer->is_active && ! $customer->merged_into_id, 409);
            $previous = SalesOrder::query()->where('checkout_key', $key)->first();
            if ($previous) {
                abort_unless($previous->customer_account_id === $fresh->id && hash_equals($previous->checkout_digest, $digest), 409);

                return $previous;
            }
            $products = Product::query()->whereIn('slug', array_keys($cart))->orderBy('id')->lockForUpdate()->get();
            abort_unless($products->count() === count($cart), 409);
            $order = new SalesOrder;
            foreach (['public_id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'customer_account_id' => $fresh->id, 'checkout_key' => $key, 'checkout_digest' => $digest, 'status' => 'draft', 'currency' => 'TOMAN', 'total' => '0.00', 'version' => 1] as $field => $value) {
                $order->$field = $value;
            }
            $order->save();
            $total = 0;
            foreach ($products as $product) {
                $quantity = $cart[$product->slug];
                abort_unless(is_int($quantity) && $quantity >= 1 && $quantity <= 10, 422);
                $item = $this->offer($product, true);
                abort_unless($item && $item->available >= $quantity, 409, 'موجودی کالا تغییر کرده است؛ سبد را بررسی کنید.');
                $unit = OrderMoney::minor((string) $product->price);
                $sum = $unit * $quantity;
                $total += $sum;
                $line = new SalesOrderLine;
                foreach (['sales_order_id' => $order->id, 'inventory_item_id' => $item->id, 'sku' => $item->sku, 'description' => $product->name, 'condition' => $item->condition, 'quantity' => $quantity, 'revision' => 1, 'unit_price' => OrderMoney::decimal($unit), 'line_total' => OrderMoney::decimal($sum)] as $field => $value) {
                    $line->$field = $value;
                }
                $line->save();
            }
            $order->total = OrderMoney::decimal($total);
            $order->save();
            $event = new SalesOrderEvent;
            foreach (['sales_order_id' => $order->id, 'customer_account_id' => $fresh->id, 'action' => 'save', 'to_status' => 'draft', 'reason' => 'ثبت سفارش توسط مشتری؛ در انتظار تأیید موجودی', 'line_snapshot' => $order->lines()->get(['sku', 'description', 'condition', 'quantity', 'unit_price', 'line_total'])->toArray(), 'created_at' => now()] as $field => $value) {
                $event->$field = $value;
            }
            $event->save();

            return $order;
        }, 3);
    }
}
