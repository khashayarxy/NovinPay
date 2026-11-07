<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ZibalPayments
{
    public function __construct(private ZibalGateway $gateway, private PaymentSettlement $settlement) {}

    public function merchantDigest(): string
    {
        return hash_hmac('sha256', (string) config('payments.zibal.merchant'), config('app.key'));
    }

    public function ownedDocument(CustomerAccount $account, string $reference, bool $lock = false): FinancialDocument
    {
        abort_unless($account->is_active && $account->customer_id, 403);
        $query = FinancialDocument::query()->where('public_id', $reference)->where('customer_id', $account->customer_id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function start(CustomerAccount $account, string $documentId, int $amountMinor, string $operation): PaymentAttempt
    {
        abort_unless($this->gateway->available(), 503, 'پرداخت آنلاین هنوز فعال نشده است.');
        abort_unless(Str::isUuid($operation) && $amountMinor > 0 && $amountMinor % 10 === 0, 422, 'مبلغ باید به ریال صحیح قابل تبدیل باشد.');
        $rial = intdiv($amountMinor, 10);
        abort_unless($rial >= config('payments.zibal.min_rial') && $rial <= config('payments.zibal.max_rial'), 422);
        [$attempt, $created] = DB::transaction(function () use ($account, $documentId, $amountMinor, $rial, $operation): array {
            $fresh = CustomerAccount::query()->lockForUpdate()->findOrFail($account->id);
            abort_unless($fresh->is_active && $fresh->session_version === $account->session_version, 403);
            $document = $this->ownedDocument($fresh, $documentId, true);
            $prior = PaymentAttempt::query()->where('operation_key', $operation)->first();
            if ($prior) {
                abort_unless($prior->customer_account_id === $account->id && $prior->financial_document_id === $document->id && $prior->amount_minor === $amountMinor, 409);

                return [$prior, false];
            }
            app(FinancialOperations::class)->reconcile($document);
            self::shippingReady($document);
            abort_unless($amountMinor <= $document->charged_minor - $document->paid_minor, 409, 'مبلغ از ماندهٔ سند بیشتر است.');
            abort_if(PaymentAttempt::query()->where('financial_document_id', $document->id)->whereNotIn('state', ['settled', 'failed'])->exists(), 409, 'پرداخت قبلی هنوز تعیین تکلیف نشده است؛ دوباره پرداخت نکنید.');
            $attempt = new PaymentAttempt;
            foreach (['public_id' => (string) Str::uuid(), 'financial_document_id' => $document->id, 'customer_account_id' => $account->id, 'operation_key' => $operation, 'amount_minor' => $amountMinor, 'amount_rial' => $rial, 'document_version' => $document->version, 'merchant_digest' => $this->merchantDigest(), 'state' => 'requesting'] as $key => $value) {
                $attempt->$key = $value;
            }
            $attempt->save();
            $this->event($attempt, 'request_started');

            return [$attempt, true];
        }, 3);
        if (! $created) {
            return $attempt;
        }
        $response = $this->gateway->call('request', ['amount' => $rial, 'orderId' => $attempt->public_id, 'callbackUrl' => config('payments.zibal.callback_url'), 'description' => 'پرداخت سفارش نوین‌پی']);

        return DB::transaction(function () use ($attempt, $response): PaymentAttempt {
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $track = $response['trackId'] ?? null;
            if (($response['result'] ?? null) === 100 && (is_int($track) || is_string($track)) && preg_match('/\A[0-9]{1,20}\z/D', (string) $track)) {
                $current->track_id = (string) $track;
                $current->state = 'pending';
            } else {
                // Request may have reached the gateway. Never blindly recreate it.
                $current->state = 'uncertain';
            }
            $current->retry_at = now()->addMinutes(2);
            $current->save();
            $this->event($current, $current->state);

            return $current;
        }, 3);
    }

    public function verify(PaymentAttempt $attempt): PaymentAttempt
    {
        if ($attempt->fresh()->state === 'settled') {
            return $attempt->fresh();
        }
        abort_unless($this->gateway->available(), 503, 'پرداخت آنلاین هنوز فعال نشده است.');
        $claim = DB::transaction(function () use ($attempt): ?PaymentAttempt {
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if (in_array($current->state, ['settled', 'failed', 'review'], true) || ! $current->track_id || ($current->lease_until && $current->lease_until->isFuture())) {
                return null;
            }
            abort_unless(hash_equals($current->merchant_digest, $this->merchantDigest()), 503);
            $current->lease_until = now()->addMinutes(2);
            $current->state = 'processing';
            $current->attempts++;
            $current->save();

            return $current;
        }, 3);
        if (! $claim) {
            return $attempt->fresh();
        }
        $response = $this->gateway->call('verify', ['trackId' => $claim->track_id]);
        $code = $response['result'] ?? null;
        if (! in_array($code, [100, 201], true)) {
            // "Not paid" is an observation, not proof the customer cannot finish
            // an already-open gateway session later. Keep late callbacks viable.
            return $this->finish($claim, 'uncertain');
        }
        // The local immutable attempt supplies a binding, but a missing provider amount
        // triggers inquiry instead of silently trusting an unverified browser parameter.
        if (! isset($response['orderId'], $response['amount'])) {
            $inquiry = $this->gateway->call('inquiry', ['trackId' => $claim->track_id]);
            if (($inquiry['result'] ?? null) !== 100 || ! in_array($inquiry['status'] ?? null, [1, 2], true)) {
                return $this->finish($claim, 'uncertain');
            }
            $response = $inquiry;
        }
        $amount = $response['amount'] ?? null;
        if (($response['orderId'] ?? null) !== $claim->public_id || ! (is_int($amount) || (is_string($amount) && ctype_digit($amount))) || (string) $amount !== (string) $claim->amount_rial) {
            return $this->finish($claim, 'review');
        }

        return DB::transaction(function () use ($claim, $response): PaymentAttempt {
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($claim->id);
            if ($current->state === 'settled') {
                return $current;
            }
            if ((int) $current->attempts !== (int) $claim->attempts) {
                return $current;
            }
            $document = FinancialDocument::query()->lockForUpdate()->findOrFail($current->financial_document_id);
            $this->settlement->record($document, $current->amount_minor, 'gateway', 'ZIBAL-'.substr($current->merchant_digest, 0, 16).'-'.$current->track_id, $current->public_id, null, $current->id, null, is_string($response['paidAt'] ?? null) ? $response['paidAt'] : null);
            $current->state = 'settled';
            $current->verified_at = now();
            $current->lease_until = null;
            $current->save();
            $this->event($current, 'settled');

            return $current;
        }, 3);
    }

    private function finish(PaymentAttempt $attempt, string $state): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $state): PaymentAttempt {
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($current->state !== 'settled' && (int) $current->attempts === (int) $attempt->attempts) {
                $current->state = $state;
                $current->lease_until = null;
                $current->retry_at = now()->addMinutes(2);
                $current->save();
                $this->event($current, $state);
            }

            return $current;
        }, 3);
    }

    private function event(PaymentAttempt $attempt, string $event): void
    {
        DB::table('payment_events')->insert(['payment_attempt_id' => $attempt->id, 'event' => $event, 'created_at' => now()]);
    }

    public static function shippingReady(FinancialDocument $document): void
    {
        $plan = DeliveryPlan::query()->where('financial_document_id', $document->id)->lockForUpdate()->first();
        abort_if($plan?->method === 'post', 503, 'پرداخت پستی تا تأیید کاربرد تعرفه و اتصال استعلام به سند فعال نیست.');
        abort_unless($plan && $plan->state === 'planned' && $plan->requested_method === null && in_array($plan->method, ['tipax', 'courier', 'post'], true), 409, 'ابتدا ارسال به آدرس و هزینهٔ آن باید تأیید شود. پرداخت تحویل حضوری هنگام مراجعه است.');
    }
}
