<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Models\CustomerAccount;
use App\Models\FinancialDocument;
use App\Models\FinancialEntry;
use App\Models\PaymentTransaction;
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
            foreach (['public_id' => (string) Str::uuid(), 'financial_operation_id' => $op, 'financial_document_id' => $document->id, 'payment_transaction_id' => $transaction->id, 'actor_id' => $actor, 'kind' => 'receipt', 'category' => $document->sales_order_id ? 'sales' : 'repair', 'charge_delta' => 0, 'receipt_delta' => $allocation, 'expense_delta' => 0, 'method' => $method, 'reference' => $reference, 'reference_digest' => $digest, 'note' => $actor === null ? 'تأیید و ثبت خودکار درگاه' : 'دریافت وجه تأییدشده توسط همکار', 'created_at' => now()] as $key => $value) {
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

        return $transaction;
    }
}
