<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderEvent;
use App\Models\SalesOrderLine;
use App\Services\Notifications\UnifiedNotifier;
use App\Support\OrderMoney;
use App\Support\PaymentTestProduct;
use App\Support\ProductSale;
use App\Support\RepairIdentity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreCheckout
{
    public function offer(Product $product, bool $lock = false): ?InventoryItem
    {
        if (! ProductSale::permitsInventoryCheck($product)) {
            return null;
        }

        return $this->availableStock($product, $lock);
    }

    public function availableStock(Product $product, bool $lock = false): ?InventoryItem
    {
        $item = $this->confirmedInventory($product, $lock);

        return $item && $item->available > 0 ? $item : null;
    }

    public function confirmedInventory(Product $product, bool $lock = false): ?InventoryItem
    {
        if (! $product->stock_status->permitsCheckout()) {
            return null;
        }
        $query = $product->inventoryItems()->where('condition', $product->condition->value)->orderBy('id');
        $items = ! $lock && $product->relationLoaded('inventoryItems')
            ? $product->inventoryItems->where('condition', $product->condition->value)
            : ($lock ? $query->lockForUpdate() : $query)->get();

        // Ambiguous catalogue/SKU mapping is not an invitation to choose arbitrary stock.
        return $items->count() === 1 ? $items->first() : null;
    }

    public function quote(Collection $products): string
    {
        $prices = $products->mapWithKeys(fn (Product $product) => [$product->slug => (string) $product->price])->sortKeys();

        return hash_hmac('sha256', $prices->toJson(JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function create(CustomerAccount $account, array $cart, string $key, ?string $quote = null): SalesOrder
    {
        abort_unless(Str::isUuid($key) && count($cart) <= 30, 422);
        ksort($cart);

        $created = false;

        $order = DB::transaction(function () use ($account, $cart, $key, $quote, &$created): SalesOrder {
            $digest = hash_hmac('sha256', json_encode($cart, JSON_THROW_ON_ERROR), config('app.key'));
            $fresh = CustomerAccount::query()->lockForUpdate()->findOrFail($account->id);
            abort_unless($fresh->is_active && $fresh->session_version === $account->session_version, 403);
            $customer = $this->resolveCustomerForAccount($fresh);
            abort_unless($customer->is_active && ! $customer->merged_into_id, 409);
            $previous = SalesOrder::query()->where('checkout_key', $key)->first();
            if ($previous) {
                abort_unless($previous->customer_account_id === $fresh->id
                    && ($cart === [] || hash_equals($previous->checkout_digest, $digest)), 409);

                return $previous;
            }
            abort_if($cart === [], 422);
            $products = Product::query()->whereIn('slug', array_keys($cart))->orderBy('id')->lockForUpdate()->get();
            abort_unless($products->count() === count($cart), 409);
            foreach ($products as $product) {
                abort_unless(PaymentTestProduct::canSeeProduct($product, $fresh), 403, 'دسترسی به این محصول مجاز نیست.');
            }
            abort_if($quote !== null && ! hash_equals($this->quote($products), $quote), 409, 'قیمت کالا تغییر کرده است؛ سبد را دوباره بررسی کنید.');
            $order = new SalesOrder;
            foreach (['public_id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'customer_account_id' => $fresh->id, 'checkout_key' => $key, 'checkout_digest' => $digest, 'status' => 'draft', 'currency' => 'TOMAN', 'total' => '0.00', 'version' => 1] as $field => $value) {
                $order->$field = $value;
            }
            $order->save();
            $total = 0;
            foreach ($products as $product) {
                $quantity = $cart[$product->slug];
                abort_unless(is_int($quantity) && $quantity >= 1 && $quantity <= ShoppingCart::MAX_ITEM_QUANTITY, 422);
                $item = $this->offer($product, true);
                abort_unless($item && $quantity <= ShoppingCart::maxQuantityForOffer($item), 409, 'موجودی کالا تغییر کرده است؛ سبد را بررسی کنید.');
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
            $created = true;

            return $order;
        }, 3);

        if ($created) {
            $orderId = $order->id;
            DB::afterCommit(static function () use ($orderId): void {
                dispatch(static function () use ($orderId): void {
                    $freshOrder = SalesOrder::query()->find($orderId);
                    if ($freshOrder) {
                        app(UnifiedNotifier::class)->orderPlaced($freshOrder);
                    }
                })->afterResponse();
            });
        }

        return $order;
    }

    private function resolveCustomerForAccount(CustomerAccount $account): Customer
    {
        if ($account->customer_id) {
            return Customer::query()->lockForUpdate()->findOrFail($account->customer_id);
        }

        $phone = RepairIdentity::phone((string) ($account->phone ?? ''));
        $phoneDigest = $phone !== '' ? RepairIdentity::digest('phone', $phone) : hash_hmac('sha256', 'account:'.$account->public_id, config('app.key'));

        if ($phone !== '') {
            $existing = Customer::query()
                ->where('phone_digest', $phoneDigest)
                ->where('is_active', true)
                ->whereNull('merged_into_id')
                ->whereNotIn('id', CustomerAccount::query()->whereNotNull('customer_id')->select('customer_id'))
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $account->customer_id = $existing->id;
                $account->save();

                return $existing;
            }
        }

        $name = trim((string) ($account->name ?: ($account->email ?: 'مشتری نوین‌پی')));
        $customer = new Customer;
        foreach ([
            'public_id' => (string) Str::uuid(),
            'name' => $name,
            'contact_phone' => $phone !== '' ? $phone : '09000000000',
            'name_digest' => hash_hmac('sha256', mb_strtolower($name), config('app.key')),
            'phone_digest' => $phoneDigest,
            'identity_digest' => hash_hmac('sha256', $account->public_id.'|'.$phoneDigest, config('app.key')),
            'is_active' => true,
        ] as $field => $value) {
            $customer->$field = $value;
        }
        $customer->save();

        $account->customer_id = $customer->id;
        $account->save();

        return $customer;
    }
}
