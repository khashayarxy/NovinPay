<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Events\OrderPaid;
use App\Events\RepairPaid;
use App\Models\AdminAccount;
use App\Models\CustomerAccount;
use App\Models\FinancialDocument;
use App\Models\FinancialEntry;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\PaymentTransaction;
use App\Models\RepairCase;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Observability\ObservabilityEvents;
use App\Support\AdminHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

class PaymentSettlement
{
    /** Must run inside the caller's transaction, with provider/receipt evidence verified. */
    public function record(FinancialDocument $document, int $amount, string $method, string $reference, string $operation, ?int $actor, ?int $attempt = null, ?int $receipt = null, ?string $paidAt = null): PaymentTransaction
    {
        abort_unless(DB::transactionLevel() > 0 && $amount > 0 && $amount <= 999999999999999, 422);
        $reference = strtoupper(trim($reference));
        $digest = hash_hmac('sha256', $reference, config('app.key'));
        $prior = PaymentTransaction::query()->where('operation_key', $operation)->first();
        if ($prior) {
            abort_unless((int) $prior->financial_document_id === $document->id && $prior->amount_minor === $amount && $prior->method === $method && hash_equals($prior->reference_digest, $digest) && $prior->actor_id === $actor && $prior->payment_attempt_id === $attempt && $prior->payment_receipt_id === $receipt, 409);

            return $prior;
        }
        app(FinancialOperations::class)->reconcile($document);
        abort_if(FinancialEntry::query()->where('reference_digest', $digest)->exists(), 409, 'این مرجع قبلاً ثبت شده است.');
        $allocation = min($amount, $document->charged_minor - $document->paid_minor);
        $transaction = new PaymentTransaction;
        foreach (['public_id' => (string) Str::uuid(), 'financial_document_id' => $document->id, 'payment_attempt_id' => $attempt, 'payment_receipt_id' => $receipt, 'actor_id' => $actor, 'operation_key' => $operation, 'method' => $method, 'amount_minor' => $amount, 'allocated_minor' => $allocation, 'reference_digest' => $digest, 'reference' => $reference, 'created_at' => now()] as $key => $value) {
            $transaction->$key = $value;
        }
        $transaction->provider_paid_at = is_string($paidAt) && strlen($paidAt) <= 80 ? $paidAt : null;
        $transaction->save();
        if ($allocation > 0) {
            $op = DB::table('financial_operations')->insertGetId(['operation_key' => $operation, 'actor_id' => $actor, 'action' => $actor === null ? 'gateway.receipt' : 'payment.receipt', 'payload_digest' => hash_hmac('sha256', json_encode([$document->id, $amount, $method, $digest], JSON_THROW_ON_ERROR), config('app.key')), 'created_at' => now()]);
            $entry = new FinancialEntry;
            // Preserve the legacy ledger's 12-character method column and its
            // established transfer classification. The linked transaction retains
            // the precise card_transfer origin and reviewed receipt evidence.
            $ledgerMethod = $method === 'card_transfer' ? 'transfer' : $method;
            foreach (['public_id' => (string) Str::uuid(), 'financial_operation_id' => $op, 'financial_document_id' => $document->id, 'payment_transaction_id' => $transaction->id, 'actor_id' => $actor, 'kind' => 'receipt', 'category' => $document->sales_order_id ? 'sales' : 'repair', 'charge_delta' => 0, 'receipt_delta' => $allocation, 'expense_delta' => 0, 'method' => $ledgerMethod, 'reference' => $reference, 'reference_digest' => $digest, 'note' => $actor === null ? 'تأیید و ثبت خودکار درگاه' : 'دریافت وجه تأییدشده توسط همکار', 'created_at' => now()] as $key => $value) {
                $entry->$key = $value;
            }
            $entry->save();
            DB::table('financial_operations')->where('id', $op)->update(['result_id' => $entry->id]);
            $document->paid_minor += $allocation;
            $document->version++;
            $document->save();
        }
        if ($amount > $allocation) {
            DB::table('payment_credit_events')->insert(['operation_key' => (string) Str::uuid(), 'financial_document_id' => $document->id, 'payment_transaction_id' => $transaction->id, 'actor_id' => $actor, 'amount_delta' => $amount - $allocation, 'reason' => encrypt('وجه اضافه؛ نیازمند تعیین تکلیف یا بازپرداخت'), 'created_at' => now()]);
        }
        if ($actor !== null) {
            AdminHistory::record(AdminAction::FinancePosted, 'payment_transaction', $transaction->id, $actor);
        }
        $account = CustomerAccount::query()->where('customer_id', $document->customer_id)->value('id');
        app(CustomerNotifications::class)->record($account, 'payment_confirmed', $transaction->public_id, '/account/documents/'.$document->public_id);
        // Same transaction-bound observation convention as finance.after-write.
        Event::dispatch('payment.after-write', [$transaction]);

        if ($allocation > 0 && $document->charged_minor > 0 && $document->paid_minor >= $document->charged_minor && $document->sales_order_id) {
            $order = SalesOrder::query()->lockForUpdate()->find($document->sales_order_id);
            if ($order && in_array($order->status, ['draft', 'reserved'], true)) {
                $previousStatus = (string) $order->status;
                $this->reserveStockForPaidOrder($order, $actor);
                $order->status = 'confirmed';
                $order->version++;
                $order->save();
                $orderSnapshot = clone $order;
                DB::afterCommit(static fn () => app(ObservabilityEvents::class)->orderStatusChanged($orderSnapshot, $previousStatus, 'confirmed', fullConfirmation: true));
            }
        }

        app(ShoppingCart::class)->consume($document);

        if ($allocation > 0 && $document->charged_minor > 0 && $document->paid_minor >= $document->charged_minor) {
            DB::afterCommit(function () use ($document, $transaction): void {
                if ($document->sales_order_id && ($order = SalesOrder::query()->find($document->sales_order_id))) {
                    OrderPaid::dispatch($order, $document, $transaction);
                } elseif ($document->repair_case_id && ($repairCase = RepairCase::query()->find($document->repair_case_id))) {
                    RepairPaid::dispatch($repairCase, $document, $transaction);
                }
            });
        }

        return $transaction;
    }

    /** Reserves unreserved order lines inside the settlement transaction so stock availability decrements upon payment. */
    private function reserveStockForPaidOrder(SalesOrder $order, ?int $actor): void
    {
        $lines = $order->lines()->where('is_current', true)->orderBy('inventory_item_id')->get();
        if ($lines->isEmpty()) {
            return;
        }

        $items = InventoryItem::query()
            ->whereIn('id', $lines->pluck('inventory_item_id')->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $opActorId = $actor
            ?? AdminAccount::query()->where('is_active', true)->orderBy('id')->value('user_id')
            ?? User::query()->orderBy('id')->value('id');
        $operationId = null;

        foreach ($lines as $line) {
            $item = $items->get($line->inventory_item_id);
            if (! $item) {
                continue;
            }

            $alreadyReserved = DB::table('stock_reservations')
                ->where('sales_order_line_id', $line->id)
                ->where('status', 'active')
                ->exists();
            if ($alreadyReserved) {
                continue;
            }

            $free = max(0, (int) $item->on_hand - (int) $item->reserved);
            $reserveQty = min((int) $line->quantity, $free);
            if ($reserveQty <= 0) {
                continue;
            }

            $unitIds = [];
            if ($item->serialized) {
                $units = $item->units()
                    ->where('state', 'on_hand')
                    ->orderBy('id')
                    ->limit($reserveQty)
                    ->lockForUpdate()
                    ->get();
                $unitIds = $units->modelKeys();
                if ($unitIds === []) {
                    continue;
                }
                $reserveQty = count($unitIds);
                InventoryUnit::query()->whereIn('id', $unitIds)->update(['state' => 'reserved']);
            }

            $item->reserved += $reserveQty;
            $item->is_low_stock = $item->available <= $item->low_stock_threshold;
            $item->version++;
            $item->save();
            $itemSnapshot = clone $item;
            DB::afterCommit(static fn () => app(ObservabilityEvents::class)->inventoryChanged($itemSnapshot, 0, $reserveQty, 'reserve', 'رزرو پس از پرداخت سفارش'));

            if ($opActorId === null) {
                continue;
            }

            if ($operationId === null) {
                $operationKey = (string) Str::uuid();
                $digest = hash_hmac('sha256', json_encode(['settlement.reserve', $order->id, $operationKey], JSON_THROW_ON_ERROR), config('app.key'));
                $operationId = (int) DB::table('commerce_operations')->insertGetId([
                    'operation_key' => $operationKey,
                    'actor_id' => $opActorId,
                    'action' => 'order.reserve',
                    'payload_digest' => $digest,
                    'result_id' => $order->id,
                    'created_at' => now(),
                ]);
            }

            foreach ($unitIds ?: [null] as $unitId) {
                DB::table('stock_reservations')->insert([
                    'sales_order_line_id' => $line->id,
                    'inventory_unit_id' => $unitId,
                    'quantity' => $unitId === null ? $reserveQty : 1,
                    'status' => 'active',
                    'commerce_operation_id' => $operationId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $movement = new StockMovement;
            foreach ([
                'inventory_item_id' => $item->id,
                'sales_order_id' => $order->id,
                'commerce_operation_id' => $operationId,
                'actor_id' => $opActorId,
                'kind' => 'reserve',
                'on_hand_delta' => 0,
                'reserved_delta' => $reserveQty,
                'dispatched_delta' => 0,
                'on_hand_after' => $item->on_hand,
                'reserved_after' => $item->reserved,
                'dispatched_after' => $item->dispatched,
                'reason' => 'رزرو خودکار موجودی پس از پرداخت موفق سفارش',
                'unit_ids' => $unitIds,
                'created_at' => now(),
            ] as $key => $value) {
                $movement->$key = $value;
            }
            $movement->save();
        }
    }
}
