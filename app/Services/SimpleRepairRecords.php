<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Enums\RepairStage;
use App\Models\AdminAccount;
use App\Models\FinancialDocument;
use App\Models\RepairCase;
use App\Models\RepairDelivery;
use App\Models\RepairEvent;
use App\Models\RepairRecordRevision;
use App\Models\User;
use App\Support\AdminHistory;
use App\Support\AdminRepairMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SimpleRepairRecords
{
    public function save(User $actor, RepairCase $target, array $data): RepairCase
    {
        return DB::transaction(function () use ($actor, $target, $data): RepairCase {
            AdminAccount::query()->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            foreach (['repairs.view', 'repairs.customer_data', 'repairs.transition'] as $permission) {
                Gate::forUser($actor)->authorize($permission);
            }
            $case = RepairCase::query()->lockForUpdate()->findOrFail($target->id);
            abort_unless($case->version === (int) $data['version'], 409, 'پرونده تغییر کرده است؛ صفحه را تازه کنید.');
            abort_if($case->status->closed(), 409, 'پرونده تحویل شده است؛ اصلاحات مالی را در سند مالی ثبت کنید.');
            $before = $case->status;
            $beforeRecord = $case->only(['repair_price_toman', 'repair_notes', 'delivery_method', 'delivery_carrier', 'settlement_method']);
            $beforeRecord['status'] = $before->value;
            $next = RepairStage::from($data['status']);
            $price = AdminRepairMoney::toman($data['repair_cost'], (int) $data['money_scale']);
            if ($price !== $case->repair_price_toman) {
                Gate::forUser($actor)->authorize('repairs.estimate');
            }
            $document = FinancialDocument::query()->where('repair_case_id', $case->id)->lockForUpdate()->first();
            if ($document) {
                // A source edit must never silently rewrite an issued ledger or discard adjustments.
                $sourcePrice = $document->source_amount_minor - (int) $case->inbound_shipping_cost * 100;
                abort_unless($price * 100 === $sourcePrice, 409, 'برای تغییر مبلغ سند صادرشده، اصلاح مالی ثبت کنید.');
                app(FinancialOperations::class)->reconcile($document);
            }
            if (isset($data['settled'])) {
                Gate::forUser($actor)->authorize('finance.view');
                abort_unless($document && $document->charged_minor > 0 && $document->charged_minor === $document->paid_minor, 409, 'ابتدا پرداخت واقعی را در سند مالی تأیید کنید.');
                $method = $data['settlement_method'];
                $allowed = $method === 'online' ? ['gateway', 'card_transfer', 'transfer'] : ['in_store', 'cash', 'terminal'];
                abort_unless($document->entries()->where('receipt_delta', '>', 0)->whereIn('method', $allowed)->exists(), 409, 'روش انتخاب‌شده با پرداخت‌های ثبت‌شده مطابقت ندارد.');
                $case->settlement_method = $method;
            } elseif (Gate::forUser($actor)->allows('finance.view')) {
                // Unchecking the UI must not reverse a real payment.
                abort_if($document && $document->charged_minor > 0 && $document->paid_minor === $document->charged_minor, 409, 'پرداخت ثبت‌شده فقط با اصلاح مالی قابل تغییر است.');
                $case->settlement_method = null;
            }
            if ($next === RepairStage::Repairing && $before !== $next) {
                Gate::forUser($actor)->authorize('repairs.work');
            }
            if ($next === RepairStage::Delivered) {
                Gate::forUser($actor)->authorize('repairs.deliver');
                abort_unless(in_array($before, [RepairStage::Ready, RepairStage::ReturnPending], true), 409, 'ابتدا دستگاه را آماده تحویل کنید.');
                abort_unless(($price + (int) $case->inbound_shipping_cost === 0 && ! $document) || ($document && $document->charged_minor === $document->paid_minor), 409, 'پیش از تحویل، هزینه را تسویه کنید.');
                if ($case->customer_account_id && $document) {
                    app(DeliveryManagement::class)->completePlan($document, $actor);
                }
                $handover = new RepairDelivery;
                foreach (['repair_case_id' => $case->id, 'actor_id' => $actor->id, 'outcome' => $before === RepairStage::ReturnPending ? 'returned_without_repair' : 'repaired', 'recipient_name' => $case->customer_name, 'outcome_notes' => $data['repair_notes'] ?? '', 'delivered_at' => now()->utc(), 'created_at' => now()->utc(), 'service_cost' => $price, 'parts_cost' => 0] as $key => $value) {
                    $handover->$key = $value;
                }
                $handover->save();
                $case->active_serial_digest = null;
                $next = $before === RepairStage::ReturnPending ? RepairStage::Returned : RepairStage::Delivered;
            }
            $revision = new RepairRecordRevision;
            $revision->repair_case_id = $case->id;
            $revision->actor_id = $actor->id;
            $revision->version = $case->version + 1;
            $revision->payload = ['before' => $beforeRecord, 'after' => ['status' => $next->value, 'repair_price_toman' => $price, 'repair_notes' => $data['repair_notes'] ?? null, 'delivery_method' => $data['delivery_method'], 'delivery_carrier' => $data['delivery_method'] === 'shipping' ? $data['delivery_carrier'] : null, 'settlement_method' => $case->settlement_method]];
            $revision->created_at = now()->utc();
            $revision->save();
            $case->simple_workflow = true;
            $case->repair_price_toman = $price;
            $case->repair_notes = $data['repair_notes'] ?? null;
            $case->delivery_method = $data['delivery_method'];
            $case->delivery_carrier = $data['delivery_method'] === 'shipping' ? $data['delivery_carrier'] : null;
            $case->status = $next;
            $case->version++;
            $case->save();
            $event = new RepairEvent;
            foreach (['repair_case_id' => $case->id, 'actor_id' => $actor->id, 'action' => AdminAction::RepairTransition->value, 'from_status' => $before->value, 'to_status' => $next->value, 'subject_type' => 'repair_revision', 'subject_id' => $revision->id, 'created_at' => now()->utc()] as $key => $value) {
                $event->$key = $value;
            }
            $event->save();
            AdminHistory::record(AdminAction::RepairTransition, 'repair_case', $case->id, $actor->id);

            return $case;
        }, 3);
    }
}
