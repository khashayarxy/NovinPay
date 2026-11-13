<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Enums\RepairStage;
use App\Models\AdminAccount;
use App\Models\DeliveryPlan;
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
            $intent = $data['intent'] ?? 'save';
            foreach (['repairs.view', 'repairs.customer_data', $intent === 'handover' ? 'repairs.deliver' : 'repairs.transition'] as $permission) {
                Gate::forUser($actor)->authorize($permission);
            }
            $case = RepairCase::query()->lockForUpdate()->findOrFail($target->id);
            abort_unless($case->version === (int) $data['version'], 409, 'پرونده تغییر کرده است؛ صفحه را تازه کنید.');
            abort_unless($case->simple_workflow, 409, 'این پرونده در گردش کار کامل است؛ از عملیات کامل تعمیر استفاده کنید.');
            abort_if($case->status->closed(), 409, 'پرونده تحویل شده است؛ اصلاحات مالی را در سند مالی ثبت کنید.');
            $before = $case->status;
            $beforeRecord = $case->only(['repair_price_toman', 'repair_notes', 'delivery_method', 'delivery_carrier', 'settlement_method']);
            $beforeRecord['status'] = $before->value;
            $action = AdminAction::RepairTransition;
            if ($intent === 'settlement') {
                Gate::forUser($actor)->authorize('finance.view');
                $document = $this->document($case);
                abort_unless($document && $document->charged_minor > 0 && $document->charged_minor === $document->paid_minor, 409, 'ابتدا پرداخت واقعی را در سند مالی تأیید کنید.');
                app(FinancialOperations::class)->reconcile($document);
                $method = $data['settlement_method'];
                $allowed = $method === 'online' ? ['gateway', 'card_transfer', 'transfer'] : ['in_store', 'cash', 'terminal'];
                abort_unless($document->entries()->where('receipt_delta', '>', 0)->whereIn('method', $allowed)->exists(), 409, 'روش انتخاب‌شده با پرداخت‌های ثبت‌شده مطابقت ندارد.');
                $case->settlement_method = $method;
            } elseif ($intent === 'handover') {
                $this->handover($actor, $case, $data);
                $action = AdminAction::RepairDelivery;
            } else {
                $this->updateCase($actor, $case, $data);
            }
            $revision = new RepairRecordRevision;
            $revision->repair_case_id = $case->id;
            $revision->actor_id = $actor->id;
            $revision->version = $case->version + 1;
            $revision->payload = ['before' => $beforeRecord, 'after' => ['status' => $case->status->value] + $case->only(['repair_price_toman', 'repair_notes', 'delivery_method', 'delivery_carrier', 'settlement_method'])];
            $revision->created_at = now()->utc();
            $revision->save();
            $case->version++;
            $case->save();
            $event = new RepairEvent;
            foreach (['repair_case_id' => $case->id, 'actor_id' => $actor->id, 'action' => $action->value, 'from_status' => $before->value, 'to_status' => $case->status->value, 'subject_type' => 'repair_revision', 'subject_id' => $revision->id, 'created_at' => now()->utc()] as $key => $value) {
                $event->$key = $value;
            }
            $event->save();
            AdminHistory::record($action, 'repair_case', $case->id, $actor->id);

            return $case;
        }, 3);
    }

    private function document(RepairCase $case): ?FinancialDocument
    {
        return FinancialDocument::query()->where('repair_case_id', $case->id)->lockForUpdate()->first();
    }

    private function updateCase(User $actor, RepairCase $case, array $data): void
    {
        $next = RepairStage::from($data['status']);
        abort_if($case->status !== $next && ! in_array($next, [RepairStage::Received, RepairStage::Repairing, RepairStage::Ready, RepairStage::ReturnPending], true),
            422, 'وضعیت تعمیر را از گزینه‌های فرم انتخاب کنید.');
        if ($next === RepairStage::Repairing && $case->status !== $next) {
            Gate::forUser($actor)->authorize('repairs.work');
        }
        if ($next === RepairStage::Ready && isset($data['repair_cost'])) {
            $price = AdminRepairMoney::toman($data['repair_cost'], 1);
            if ($price !== $case->repair_price_toman) {
                Gate::forUser($actor)->authorize('repairs.estimate');
                $document = $this->document($case);
                abort_if($document && ($price + (int) $case->inbound_shipping_cost) * 100 !== $document->source_amount_minor,
                    409, 'برای تغییر مبلغ سند صادرشده، اصلاح مالی ثبت کنید.');
                $case->repair_price_toman = $price;
            }
        }
        if (array_key_exists('repair_notes', $data)) {
            $case->repair_notes = $data['repair_notes'];
        }
        if (isset($data['delivery_method'])) {
            $case->delivery_method = $data['delivery_method'];
            if ($case->delivery_method === 'shipping') {
                $case->delivery_carrier = isset($data['clear_delivery_carrier']) ? null : ($data['delivery_carrier'] ?? $case->delivery_carrier);
            }
        }
        $case->status = $next;
    }

    private function handover(User $actor, RepairCase $case, array $data): void
    {
        abort_unless(! empty($data['handover_confirmed']), 422, 'تحویل واقعی دستگاه را تأیید کنید.');
        abort_unless(in_array($case->status, [RepairStage::Ready, RepairStage::ReturnPending], true), 409, 'ابتدا دستگاه را آماده تحویل کنید.');
        if (isset($data['confirmed_delivery_method'])) {
            $case->delivery_method = $data['confirmed_delivery_method'] === 'pickup' ? 'in_person' : 'shipping';
            if ($case->delivery_method === 'shipping') {
                $case->delivery_carrier = $data['confirmed_delivery_method'];
            }
        }
        $shipping = $case->delivery_method === 'shipping';
        $tracking = $data['shipment_tracking'] ?? null;
        abort_if($shipping && (! $case->delivery_carrier || ! $tracking), 422, 'برای ثبت ارسال، شرکت ارسال و کد پیگیری را وارد کنید.');
        $document = $this->document($case);
        $charge = $case->billableToman();
        abort_unless($document || $charge !== null || ($case->status === RepairStage::ReturnPending && $case->estimates()->doesntExist()),
            409, 'ابتدا هزینه نهایی تعمیر را مشخص کنید.');
        abort_unless($document ? $document->charged_minor === $document->paid_minor : ($charge ?? (int) $case->inbound_shipping_cost) === 0,
            409, 'پیش از تحویل، هزینه را تسویه کنید.');
        if ($document) {
            app(FinancialOperations::class)->reconcile($document);
        }
        if ($case->customer_account_id && $document) {
            if ($shipping && ! isset($data['confirmed_delivery_method'])) {
                app(PaymentReview::class)->actor($actor, $data['current_password'] ?? '');
            }
            $plan = DeliveryPlan::query()->where('financial_document_id', $document->id)->lockForUpdate()->first();
            abort_unless($plan && $plan->method === ($shipping ? $case->delivery_carrier : 'pickup')
                && $plan->state === 'planned' && $plan->requested_method === null, 409, 'روش تحویل پرونده باید با برنامه تأییدشده یکسان باشد.');
            if ($shipping) {
                $plan->tracking = $tracking;
                $plan->save();
            }
            app(DeliveryManagement::class)->completePlan($document, $actor);
        }
        $handover = new RepairDelivery;
        $returned = $case->status === RepairStage::ReturnPending;
        $handover->forceFill([
            'repair_case_id' => $case->id, 'actor_id' => $actor->id, 'outcome' => $returned ? 'returned_without_repair' : 'repaired',
            'recipient_name' => $data['recipient_name'] ?? $case->customer_name, 'outcome_notes' => $case->repair_notes ?? '',
            'confirmation_reference' => $shipping ? $tracking : null, 'delivered_at' => now()->utc(), 'created_at' => now()->utc(),
            'service_cost' => $case->repair_price_toman ?? max(0, ($charge ?? 0) - (int) $case->inbound_shipping_cost), 'parts_cost' => 0,
        ])->save();
        $case->active_serial_digest = null;
        $case->delivery_method ??= 'in_person';
        $case->status = $returned ? RepairStage::Returned : RepairStage::Delivered;
    }
}
