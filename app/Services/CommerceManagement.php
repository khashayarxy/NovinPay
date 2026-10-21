<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\SalesOrder;
use App\Models\SalesOrderEvent;
use App\Models\SalesOrderLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\AdminHistory;
use App\Support\CommerceAccess;
use App\Support\CommerceInput;
use App\Support\OrderMoney;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class CommerceManagement
{
    public function execute(User $actor, string $action, array $input, InventoryItem|SalesOrder|null $target = null): int
    {
        CommerceAccess::authorize($actor, $action);
        if ($action === 'item.create' && ! empty($input['product_id'])) {
            Gate::forUser($actor)->authorize('products.view');
        }
        $data = CommerceInput::validate($action, $input, $target !== null);
        $canonical = $data;
        $this->sort($canonical);
        $digest = hash_hmac('sha256', json_encode([$action, $target?->id, $canonical], JSON_THROW_ON_ERROR), config('app.key'));
        try {
            return DB::transaction(function () use ($actor, $action, $target, $data, $digest): int {
                DB::table('commerce_operations')->insertOrIgnore(['operation_key' => $data['operation_key'], 'actor_id' => $actor->id, 'action' => $action, 'payload_digest' => $digest, 'created_at' => now()]);
                $operation = DB::table('commerce_operations')->where('operation_key', $data['operation_key'])->lockForUpdate()->first();
                CommerceAccess::authorize($actor, $action);
                if ($action === 'item.create' && ! empty($data['product_id'])) {
                    Gate::forUser($actor)->authorize('products.view');
                }
                abort_unless($operation && $operation->actor_id === $actor->id && $operation->action === $action && hash_equals($operation->payload_digest, $digest), 409, 'شناسهٔ عملیات قبلاً برای درخواست دیگری استفاده شده است.');
                if ($operation->result_id !== null) {
                    return (int) $operation->result_id;
                }
                $id = str_starts_with($action, 'item.') ? $this->inventory($actor, $action, $data, $target, $operation->id) : $this->order($actor, $action, $data, $target, $operation->id);
                event('commerce.after-write', [$action, $id]);
                DB::table('commerce_operations')->where('id', $operation->id)->update(['result_id' => $id]);
                AdminHistory::record(match ($action) {
                    'item.create', 'item.configure' => AdminAction::InventoryConfigured,
                    'item.receive' => AdminAction::InventoryReceived,
                    'item.adjust' => AdminAction::InventoryAdjusted,
                    default => AdminAction::OrderChanged,
                }, str_starts_with($action, 'item.') ? 'inventory_item' : 'sales_order', $id, $actor->id);

                return $id;
            }, 3);
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? '';
            if (in_array($state, ['23000', '23505', '40001'], true) || in_array($exception->errorInfo[1] ?? 0, [1205, 1213], true)) {
                abort(409, 'موجودی، کد کالا یا شمارهٔ سریال با عملیات دیگری تداخل دارد؛ اطلاعات را تازه کنید.');
            }
            throw $exception;
        }
    }

    private function sort(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sort($value);
            }
        }
    }

    private function version(InventoryItem|SalesOrder $model, array $data): void
    {
        abort_if($model->version !== (int) $data['version'], 409, 'رکورد تغییر کرده است؛ صفحه را تازه کنید.');
    }

    private function inventory(User $actor, string $action, array $data, ?InventoryItem $target, int $operation): int
    {
        if ($action === 'item.create') {
            abort_if($target !== null, 422);
            $item = new InventoryItem;
            foreach (['sku', 'name', 'condition', 'serialized', 'low_stock_threshold'] as $field) {
                $item->$field = $data[$field];
            }
            $item->product_id = $data['product_id'] ?? null;
            $item->public_id = (string) Str::uuid();
            $item->save();
            $this->movement($actor, $item, $operation, 'configure', 0, 0, 0, $data['reason']);

            return $item->id;
        }
        abort_unless($target, 404);
        $item = InventoryItem::query()->lockForUpdate()->findOrFail($target->id);
        $this->version($item, $data);
        if ($action === 'item.configure') {
            $previousThreshold = $item->low_stock_threshold;
            $item->low_stock_threshold = $data['low_stock_threshold'];
            $this->movement($actor, $item, $operation, 'configure', 0, 0, 0, '[threshold '.$previousThreshold.' -> '.$item->low_stock_threshold.'] '.$data['reason']);
        } else {
            $quantity = (int) $data['quantity'];
            abort_if($quantity < 0 && -$quantity > $item->available, 409, 'اصلاح موجودی نمی‌تواند کالای رزروشده را کم کند.');
            $unitIds = [];
            if ($item->serialized) {
                $serials = preg_split('/[\r\n]+/', trim($data['serials'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                $serials = array_map(fn ($value) => strtoupper(trim($value)), $serials);
                abort_unless(count($serials) === abs($quantity) && count($serials) <= 100 && count(array_unique($serials)) === count($serials), 422, 'برای هر دستگاه یک سریال یکتا وارد کنید؛ حداکثر ۱۰۰ دستگاه در هر عملیات.');
                foreach ($serials as $serial) {
                    abort_unless(preg_match('/\A[A-Z0-9][A-Z0-9._\/-]{1,99}\z/', $serial), 422, 'قالب شمارهٔ سریال معتبر نیست.');
                }
                if ($quantity > 0) {
                    foreach ($serials as $serial) {
                        $unit = new InventoryUnit;
                        $unit->inventory_item_id = $item->id;
                        $unit->serial = $serial;
                        $unit->state = 'on_hand';
                        $unit->created_at = now();
                        $unit->save();
                        $unitIds[] = $unit->id;
                    }
                } else {
                    $units = InventoryUnit::query()->where('inventory_item_id', $item->id)->whereIn('serial', $serials)->where('state', 'on_hand')->orderBy('id')->lockForUpdate()->get();
                    abort_unless($units->count() === -$quantity, 409, 'یکی از سریال‌ها آزاد نیست یا متعلق به این کالا نیست.');
                    $unitIds = $units->modelKeys();
                    InventoryUnit::query()->whereIn('id', $unitIds)->update(['state' => 'removed']);
                }
            } else {
                abort_if(! empty($data['serials']), 422, 'کالای تعدادی شمارهٔ سریال ندارد.');
            }
            $this->movement($actor, $item, $operation, $action === 'item.receive' ? 'receive' : 'adjust', $quantity, 0, 0, $data['reason'], null, $unitIds);
        }
        $item->version++;
        $item->save();

        return $item->id;
    }

    private function movement(User $actor, InventoryItem $item, int $operation, string $kind, int $onHand, int $reserved, int $dispatched, string $reason, ?int $order = null, array $unitIds = []): void
    {
        $item->on_hand += $onHand;
        $item->reserved += $reserved;
        $item->dispatched += $dispatched;
        $item->is_low_stock = $item->available <= $item->low_stock_threshold;
        abort_if($item->on_hand < 0 || $item->reserved < 0 || $item->reserved > $item->on_hand || $item->on_hand > 1000000000 || $item->dispatched > 1000000000, 409, 'عملیات با موجودی سازگار نیست.');
        $movement = new StockMovement;
        foreach (['inventory_item_id' => $item->id, 'sales_order_id' => $order, 'commerce_operation_id' => $operation, 'actor_id' => $actor->id, 'kind' => $kind, 'on_hand_delta' => $onHand, 'reserved_delta' => $reserved, 'dispatched_delta' => $dispatched, 'on_hand_after' => $item->on_hand, 'reserved_after' => $item->reserved, 'dispatched_after' => $item->dispatched, 'reason' => $reason, 'unit_ids' => $unitIds, 'created_at' => now()] as $key => $value) {
            $movement->$key = $value;
        }
        $movement->save();
    }

    private function order(User $actor, string $action, array $data, ?SalesOrder $target, int $operation): int
    {
        $order = $target ? SalesOrder::query()->lockForUpdate()->findOrFail($target->id) : new SalesOrder;
        $before = $order->exists ? $order->status : null;
        if ($order->exists) {
            $this->version($order, $data);
        }
        abort_if(in_array($before, ['fulfilled', 'cancelled'], true), 409, 'سفارش نهایی‌شده فقط خواندنی است.');
        if ($action === 'order.save') {
            abort_if($before !== null && $before !== 'draft', 409, 'ویرایش فقط در پیش‌نویس مجاز است؛ ابتدا رزرو را آزاد کنید.');
            $customer = Customer::query()->where('public_id', $data['customer'])->lockForUpdate()->firstOrFail();
            abort_unless($customer->is_active && $customer->merged_into_id === null, 409, 'پروفایل مشتری فعال و مستقل انتخاب کنید.');
            $items = InventoryItem::query()->whereIn('public_id', array_column($data['lines'], 'item'))->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
            abort_unless($items->count() === count($data['lines']), 422, 'کالای موجودی معتبر انتخاب کنید.');
            if (! $order->exists) {
                $order->public_id = (string) Str::uuid();
                $order->created_by = $actor->id;
                $order->status = 'draft';
                $order->currency = 'TOMAN';
                $order->version = 0;
            }
            $order->customer_id = $customer->id;
            $order->total = '0.00';
            $order->save();
            // Prior line values are preserved in the immutable event snapshot.
            $order->lines()->update(['is_current' => false]);
            $total = 0;
            foreach ($data['lines'] as $input) {
                $item = $items[$input['item']];
                abort_if($item->serialized && (int) $input['quantity'] > 100, 422, 'در هر ردیف حداکثر ۱۰۰ دستگاه سریالی ثبت کنید.');
                $amount = OrderMoney::minor($input['unit_price']) * (int) $input['quantity'];
                $total += $amount;
                $line = new SalesOrderLine;
                $line->revision = $order->version + 1;
                foreach (['sales_order_id' => $order->id, 'inventory_item_id' => $item->id, 'sku' => $item->sku, 'description' => $item->name, 'condition' => $item->condition, 'quantity' => (int) $input['quantity'], 'unit_price' => OrderMoney::decimal(OrderMoney::minor($input['unit_price'])), 'line_total' => OrderMoney::decimal($amount)] as $key => $value) {
                    $line->$key = $value;
                }
                $line->save();
            }
            $order->total = OrderMoney::decimal($total);
        } else {
            abort_unless($order->exists, 404);
            $allowed = match ($action) {
                'order.reserve' => ['draft'], 'order.confirm' => ['reserved'], 'order.fulfill' => ['confirmed'],
                'order.release' => ['reserved', 'confirmed'], 'order.cancel' => ['draft', 'reserved', 'confirmed'],
                default => [],
            };
            abort_unless(in_array($before, $allowed, true), 409, 'این تغییر مرحله مجاز نیست.');
            $lines = $order->lines()->orderBy('inventory_item_id')->get();
            abort_if($lines->isEmpty(), 409, 'سفارش بدون ردیف قابل پردازش نیست.');
            if ($action !== 'order.confirm' && ! ($action === 'order.cancel' && $before === 'draft')) {
                $items = InventoryItem::query()->whereIn('id', $lines->pluck('inventory_item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($lines as $line) {
                    $item = $items[$line->inventory_item_id];
                    $this->stockForOrder($actor, $action, $order, $line, $item, $data['reason'], $operation);
                    $item->version++;
                    $item->save();
                }
            }
            $order->status = match ($action) {
                'order.reserve' => 'reserved', 'order.confirm' => 'confirmed', 'order.fulfill' => 'fulfilled', 'order.release' => 'draft', 'order.cancel' => 'cancelled'
            };
            if ($action === 'order.fulfill') {
                $order->fulfillment_info = $data['fulfillment_info'];
                $order->fulfilled_at = now();
            }
        }
        $order->version++;
        $order->save();
        $event = new SalesOrderEvent;
        foreach (['sales_order_id' => $order->id, 'actor_id' => $actor->id, 'commerce_operation_id' => $operation, 'action' => substr($action, 6), 'from_status' => $before, 'to_status' => $order->status, 'reason' => $data['reason'], 'line_snapshot' => $order->lines()->get(['sku', 'description', 'condition', 'quantity', 'unit_price', 'line_total'])->toArray(), 'created_at' => now()] as $key => $value) {
            $event->$key = $value;
        }
        $event->save();

        return $order->id;
    }

    private function stockForOrder(User $actor, string $action, SalesOrder $order, SalesOrderLine $line, InventoryItem $item, string $reason, int $operation): void
    {
        $unitIds = [];
        $quantity = $line->quantity;
        if ($action === 'order.reserve') {
            abort_if($item->available < $quantity, 409, 'موجودی آزاد کافی نیست.');
            if ($item->serialized) {
                $units = $item->units()->where('state', 'on_hand')->orderBy('id')->limit($quantity)->lockForUpdate()->get();
                abort_unless($units->count() === $quantity, 409, 'تعداد سریال آزاد با موجودی سازگار نیست.');
                $unitIds = $units->modelKeys();
                InventoryUnit::query()->whereIn('id', $unitIds)->update(['state' => 'reserved']);
            }
            foreach ($unitIds ?: [null] as $unit) {
                DB::table('stock_reservations')->insert(['sales_order_line_id' => $line->id, 'inventory_unit_id' => $unit, 'quantity' => $unit === null ? $quantity : 1, 'status' => 'active', 'commerce_operation_id' => $operation, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->movement($actor, $item, $operation, 'reserve', 0, $quantity, 0, $reason, $order->id, $unitIds);

            return;
        }
        $reservations = DB::table('stock_reservations')->where('sales_order_line_id', $line->id)->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
        abort_unless((int) $reservations->sum('quantity') === $quantity, 409, 'رزرو سفارش نیازمند بررسی است.');
        $unitIds = $reservations->pluck('inventory_unit_id')->filter()->values()->all();
        if ($item->serialized) {
            abort_unless(count($unitIds) === $quantity && InventoryUnit::query()->where('inventory_item_id', $item->id)->whereIn('id', $unitIds)->where('state', 'reserved')->count() === $quantity, 409, 'سریال‌های رزرو نیازمند بررسی است.');
            InventoryUnit::query()->whereIn('id', $unitIds)->update(['state' => $action === 'order.fulfill' ? 'dispatched' : 'on_hand']);
        }
        DB::table('stock_reservations')->whereIn('id', $reservations->pluck('id'))->update(['status' => $action === 'order.fulfill' ? 'fulfilled' : 'released', 'updated_at' => now()]);
        $fulfill = $action === 'order.fulfill';
        $this->movement($actor, $item, $operation, $fulfill ? 'dispatch' : 'release', $fulfill ? -$quantity : 0, -$quantity, $fulfill ? $quantity : 0, $reason, $order->id, $unitIds);
    }
}
