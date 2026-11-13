<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Models\AdminAccount;
use App\Models\FinancialDocument;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Support\AdminHistory;
use App\Support\FinanceAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PaymentReview
{
    /** Record independently established non-payment; never synthesize a receipt. */
    public function resolveUnpaid(User $actor, string $password, string $reference, string $note): void
    {
        DB::transaction(function () use ($actor, $password, $reference, $note): void {
            $actor = $this->actor($actor, $password, 'reverse');
            abort_unless(mb_strlen(trim($note)) >= 10 && mb_strlen($note) <= 1000, 422);
            $attempt = PaymentAttempt::query()->where('public_id', $reference)->lockForUpdate()->firstOrFail();
            $document = FinancialDocument::query()->lockForUpdate()->findOrFail($attempt->financial_document_id);
            FinanceAccess::source($actor, $document->sales_order_id ? 'order' : 'repair');
            abort_unless(in_array($attempt->state, ['review', 'uncertain'], true)
                && (! $attempt->lease_until || $attempt->lease_until->isPast()) && $attempt->resolved_at === null, 409);
            abort_if(PaymentTransaction::query()->where('payment_attempt_id', $attempt->id)->exists(), 409);
            $attempt->state = 'failed';
            $attempt->resolved_by = $actor->id;
            $attempt->resolved_at = now();
            $attempt->resolution_note = trim($note);
            $attempt->retry_at = null;
            $attempt->lease_until = null;
            $attempt->attempts++;
            $attempt->save();
            DB::table('payment_events')->insert(['payment_attempt_id' => $attempt->id, 'event' => 'manual_unpaid_resolution', 'created_at' => now()]);
            AdminHistory::record(AdminAction::FinancePosted, 'payment_attempt', $attempt->id, $actor->id);
        }, 3);
    }

    public function actor(User $actor, string $password, string $action = 'receipt'): User
    {
        $grant = AdminAccount::query()->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
        $persisted = User::query()->lockForUpdate()->findOrFail($actor->id);
        FinanceAccess::authorize($persisted, $action);
        abort_unless(! $grant->must_change_password && Hash::check($password, $persisted->password), 403);

        return $persisted;
    }

    public function inStore(User $actor, string $password, string $documentId, int $amount, string $operation): PaymentTransaction
    {
        return DB::transaction(function () use ($actor, $password, $documentId, $amount, $operation): PaymentTransaction {
            $actor = $this->actor($actor, $password);
            $document = FinancialDocument::query()->where('public_id', $documentId)->lockForUpdate()->firstOrFail();
            FinanceAccess::source($actor, $document->sales_order_id ? 'order' : 'repair');

            return app(PaymentSettlement::class)->record($document, $amount, 'in_store', 'SHOP-'.$operation, $operation, $actor->id);
        }, 3);
    }

    public function review(User $actor, string $password, string $receiptId, bool $approved, string $note, ?int $amount = null, ?string $bankReference = null): void
    {
        DB::transaction(function () use ($actor, $password, $receiptId, $approved, $note, $amount, $bankReference): void {
            $actor = $this->actor($actor, $password);
            $receipt = PaymentReceipt::query()->where('public_id', $receiptId)->lockForUpdate()->firstOrFail();
            $document = FinancialDocument::query()->lockForUpdate()->findOrFail($receipt->financial_document_id);
            FinanceAccess::source($actor, $document->sales_order_id ? 'order' : 'repair');
            abort_unless($receipt->state === 'pending', 409);
            if ($approved) {
                abort_unless($amount !== null && $bankReference !== null && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]{5,79}\z/D', $bankReference), 422);
                $reference = 'BANK-'.preg_replace('/\ABANK-/', '', strtoupper($bankReference));
                app(PaymentSettlement::class)->record($document, $amount, 'card_transfer', $reference, $receipt->public_id, $actor->id, null, $receipt->id);
            }
            $receipt->state = $approved ? 'approved' : 'rejected';
            $receipt->review_note = $note;
            $receipt->reviewed_by = $actor->id;
            $receipt->reviewed_at = now();
            $receipt->save();
            AdminHistory::record(AdminAction::FinancePosted, 'payment_receipt', $receipt->id, $actor->id);
        }, 3);
    }

    public function recordRefund(User $actor, string $password, string $documentId, int $amount, string $reference, string $operation): void
    {
        DB::transaction(function () use ($actor, $password, $documentId, $amount, $reference, $operation): void {
            $actor = $this->actor($actor, $password, 'reverse');
            $document = FinancialDocument::query()->where('public_id', $documentId)->lockForUpdate()->firstOrFail();
            FinanceAccess::source($actor, $document->sales_order_id ? 'order' : 'repair');
            $prior = DB::table('payment_credit_events')->where('operation_key', $operation)->first();
            $digest = hash_hmac('sha256', 'REFUND-'.strtoupper(trim($reference)), config('app.key'));
            if ($prior) {
                abort_unless((int) $prior->financial_document_id === $document->id && (int) $prior->amount_delta === -$amount && (int) $prior->actor_id === $actor->id && hash_equals((string) $prior->reference_digest, $digest), 409);

                return;
            }
            $balance = (int) DB::table('payment_credit_events')->where('financial_document_id', $document->id)->sum('amount_delta');
            abort_unless($amount > 0 && $amount <= $balance && strlen($reference) >= 6 && strlen($reference) <= 80, 409);
            abort_if(DB::table('payment_credit_events')->where('reference_digest', $digest)->exists(), 409, 'مرجع بازپرداخت تکراری است.');
            DB::table('payment_credit_events')->insert(['operation_key' => $operation, 'financial_document_id' => $document->id, 'actor_id' => $actor->id, 'amount_delta' => -$amount, 'reference_digest' => $digest, 'reason' => encrypt('بازپرداخت واقعی با تأیید همکار؛ بدون انتقال خودکار وجه'), 'reference' => encrypt($reference), 'created_at' => now()]);
            AdminHistory::record(AdminAction::FinancePosted, 'financial_document', $document->id, $actor->id);
        }, 3);
    }
}
