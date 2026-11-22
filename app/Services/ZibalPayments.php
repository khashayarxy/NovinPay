<?php

namespace App\Services;

use App\Models\AdminAccount;
use App\Models\CustomerAccount;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\FinancialEntry;
use App\Models\PaymentAttempt;
use App\Models\RepairCase;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\OrderMoney;
use App\Support\RepairIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ZibalPayments
{
    private const REQUEST_REJECTIONS = [102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 113, 114, 115, 116, 118, 140, 141];

    public function __construct(private ZibalGateway $gateway, private PaymentSettlement $settlement) {}

    public function merchantDigest(): string
    {
        return hash_hmac('sha256', (string) config('payments.zibal.merchant'), config('app.key'));
    }

    public function ownedDocument(CustomerAccount $account, string $reference, bool $lock = false): FinancialDocument
    {
        abort_unless($account->is_active && $account->customer_id, 403);
        $query = FinancialDocument::query()->where('public_id', $reference)->where('customer_id', $account->customer_id);
        foreach (['sales_orders' => 'sales_order_id', 'repair_cases' => 'repair_case_id'] as $table => $column) {
            // A legacy CRM link must not override an explicitly assigned account.
            $query->whereNotExists(fn ($source) => $source->selectRaw('1')->from($table)
                ->whereColumn("$table.id", "financial_documents.$column")
                ->where("$table.customer_account_id", '<>', $account->id));
        }

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
            $track = ZibalGateway::trackNumber($response['trackId'] ?? null);
            if (($response['result'] ?? null) === 100 && $track !== null) {
                $current->track_id = (string) $track;
                $current->state = 'pending';
            } elseif (in_array($response['result'] ?? null, self::REQUEST_REJECTIONS, true)) {
                // Documented request validation rejection, unlike a lost response.
                $current->state = 'failed';
            } else {
                // Request may have reached the gateway. Never blindly recreate it.
                $current->state = 'uncertain';
            }
            $current->retry_at = now()->addMinutes(2);
            $current->save();
            $this->event($current, $current->state === 'failed' ? 'request_rejected_'.$response['result'] : $current->state);

            return $current;
        }, 3);
    }

    public function startForOrderCheckout(
        CustomerAccount $account,
        SalesOrder $order,
        string $method,
        ?string $addressPublicId,
        string $operation
    ): PaymentAttempt {
        abort_unless($this->gateway->available(), 503, 'پرداخت آنلاین هنوز فعال نشده است.');
        abort_unless(Str::isUuid($operation), 422);

        $amountMinor = OrderMoney::minor((string) $order->total);
        abort_unless($amountMinor > 0 && $amountMinor % 10 === 0, 422, 'مبلغ سفارش قابل تبدیل به ریال نیست.');
        $rial = intdiv($amountMinor, 10);
        abort_unless($rial >= config('payments.zibal.min_rial') && $rial <= config('payments.zibal.max_rial'), 422, 'مبلغ سفارش خارج از محدوده مجاز درگاه است.');

        [$attempt, $created] = DB::transaction(function () use ($account, $order, $method, $addressPublicId, $operation, $amountMinor, $rial): array {
            $freshAccount = CustomerAccount::query()->lockForUpdate()->findOrFail($account->id);
            abort_unless($freshAccount->is_active && $freshAccount->session_version === $account->session_version, 403);

            $lockedOrder = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($lockedOrder->customer_account_id === $freshAccount->id, 403);

            $prior = PaymentAttempt::query()->where('operation_key', $operation)->first();
            if ($prior) {
                abort_unless($prior->customer_account_id === $freshAccount->id && $prior->amount_minor === $amountMinor, 409);

                return [$prior, false];
            }

            if ($lockedOrder->status === 'draft') {
                $lockedOrder->status = 'confirmed';
                $lockedOrder->version++;
                $lockedOrder->save();
            }

            $document = FinancialDocument::query()->where('sales_order_id', $lockedOrder->id)->lockForUpdate()->first();
            if (! $document) {
                $actorId = AdminAccount::query()
                    ->where('is_active', true)
                    ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
                    ->orderBy('id')
                    ->value('user_id') ?? User::query()->orderBy('id')->value('id');
                abort_unless($actorId !== null, 503, 'حساب سیستمی برای صدور سند مالی یافت نشد.');

                $document = new FinancialDocument;
                foreach ([
                    'public_id' => (string) Str::uuid(),
                    'customer_id' => $lockedOrder->customer_id,
                    'sales_order_id' => $lockedOrder->id,
                    'source_version' => $lockedOrder->version,
                    'source_amount_minor' => $amountMinor,
                    'charged_minor' => $amountMinor,
                    'paid_minor' => 0,
                    'version' => 1,
                    'created_by' => $actorId,
                    'created_at' => now(),
                ] as $key => $value) {
                    $document->$key = $value;
                }
                $document->save();

                $opId = DB::table('financial_operations')->insertGetId([
                    'operation_key' => (string) Str::uuid(),
                    'actor_id' => $actorId,
                    'action' => 'issue',
                    'payload_digest' => hash_hmac('sha256', json_encode(['checkout_issue', $lockedOrder->id, $amountMinor], JSON_THROW_ON_ERROR), config('app.key')),
                    'created_at' => now(),
                ]);

                $entry = new FinancialEntry;
                foreach ([
                    'public_id' => (string) Str::uuid(),
                    'financial_operation_id' => $opId,
                    'financial_document_id' => $document->id,
                    'actor_id' => $actorId,
                    'kind' => 'charge',
                    'category' => 'sales',
                    'charge_delta' => $amountMinor,
                    'receipt_delta' => 0,
                    'expense_delta' => 0,
                    'note' => 'صدور خودکار سند سفارش برای پرداخت آنلاین زیبال',
                    'created_at' => now(),
                ] as $key => $value) {
                    $entry->$key = $value;
                }
                $entry->save();
                DB::table('financial_operations')->where('id', $opId)->update(['result_id' => $entry->id]);
            }

            if (! DeliveryPlan::query()->where('financial_document_id', $document->id)->exists()) {
                $addressSnapshot = app(DeliveryManagement::class)->checkoutAddress($freshAccount, $method === 'pickup' ? null : $addressPublicId, $method);
                $plan = new DeliveryPlan;
                foreach ([
                    'public_id' => (string) Str::uuid(),
                    'financial_document_id' => $document->id,
                    'customer_account_id' => $freshAccount->id,
                    'method' => $method === 'courier' ? 'tipax' : $method,
                    'requested_method' => $method === 'courier' ? 'courier' : null,
                    'shipping_minor' => 0,
                    'address_snapshot' => $addressSnapshot,
                    'version' => 1,
                ] as $key => $value) {
                    $plan->$key = $value;
                }
                $plan->save();
            }

            $balance = $document->charged_minor - $document->paid_minor;
            abort_unless($balance >= $amountMinor, 409, 'مبلغ از ماندهٔ سند بیشتر است.');
            abort_if(
                PaymentAttempt::query()->where('financial_document_id', $document->id)->whereNotIn('state', ['settled', 'failed'])->exists(),
                409,
                'پرداخت قبلی هنوز تعیین تکلیف نشده است؛ دوباره پرداخت نکنید.'
            );

            $attempt = new PaymentAttempt;
            foreach ([
                'public_id' => (string) Str::uuid(),
                'financial_document_id' => $document->id,
                'customer_account_id' => $freshAccount->id,
                'operation_key' => $operation,
                'amount_minor' => $amountMinor,
                'amount_rial' => $rial,
                'document_version' => $document->version,
                'merchant_digest' => $this->merchantDigest(),
                'state' => 'requesting',
            ] as $key => $value) {
                $attempt->$key = $value;
            }
            $attempt->save();
            $this->event($attempt, 'request_started');

            return [$attempt, true];
        }, 3);

        if (! $created) {
            return $attempt;
        }

        $response = $this->gateway->call('request', [
            'amount' => $rial,
            'orderId' => $attempt->public_id,
            'callbackUrl' => config('payments.zibal.callback_url'),
            'description' => 'پرداخت سفارش نوین‌پی',
        ]);

        return DB::transaction(function () use ($attempt, $response): PaymentAttempt {
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $track = ZibalGateway::trackNumber($response['trackId'] ?? null);
            if (($response['result'] ?? null) === 100 && $track !== null) {
                $current->track_id = (string) $track;
                $current->state = 'pending';
            } elseif (in_array($response['result'] ?? null, self::REQUEST_REJECTIONS, true)) {
                $current->state = 'failed';
            } else {
                $current->state = 'uncertain';
            }
            $current->retry_at = now()->addMinutes(2);
            $current->save();
            $this->event($current, $current->state === 'failed' ? 'request_rejected_'.$response['result'] : $current->state);

            return $current;
        }, 3);
    }

    public function startForRepairLink(RepairCase $repairCase, string $operation): PaymentAttempt
    {
        abort_unless($this->gateway->available(), 503, 'پرداخت آنلاین هنوز فعال نشده است.');
        abort_unless(Str::isUuid($operation), 422);

        [$attempt, $created, $rial, $trackingRef] = DB::transaction(function () use ($repairCase, $operation): array {
            $case = RepairCase::query()->lockForUpdate()->findOrFail($repairCase->id);
            $document = FinancialDocument::query()->where('repair_case_id', $case->id)->lockForUpdate()->firstOrFail();
            app(FinancialOperations::class)->reconcile($document);

            $account = $this->resolveRepairAccount($case, $document);
            $balance = $document->charged_minor - $document->paid_minor;
            abort_unless($balance > 0 && $balance % 10 === 0, 409, 'ماندهٔ قابل پرداختی برای این پرونده وجود ندارد.');

            $rial = intdiv($balance, 10);
            abort_unless($rial >= config('payments.zibal.min_rial') && $rial <= config('payments.zibal.max_rial'), 422, 'مبلغ پرداخت خارج از محدوده مجاز درگاه است.');

            $prior = PaymentAttempt::query()->where('operation_key', $operation)->first();
            if ($prior) {
                abort_unless($prior->financial_document_id === $document->id && $prior->amount_minor === $balance, 409);

                return [$prior, false, $rial, (string) $case->tracking_reference];
            }

            $active = PaymentAttempt::query()
                ->where('financial_document_id', $document->id)
                ->whereNotIn('state', ['settled', 'failed'])
                ->latest('id')
                ->first();
            if ($active) {
                if ($active->state === 'pending' && $active->track_id && $active->amount_minor === $balance && hash_equals($active->merchant_digest, $this->merchantDigest())) {
                    return [$active, false, $rial, (string) $case->tracking_reference];
                }
                abort(409, 'پرداخت قبلی هنوز تعیین تکلیف نشده است؛ دوباره پرداخت نکنید.');
            }

            $attempt = new PaymentAttempt;
            foreach ([
                'public_id' => (string) Str::uuid(),
                'financial_document_id' => $document->id,
                'customer_account_id' => $account->id,
                'operation_key' => $operation,
                'amount_minor' => $balance,
                'amount_rial' => $rial,
                'document_version' => $document->version,
                'merchant_digest' => $this->merchantDigest(),
                'state' => 'requesting',
            ] as $key => $value) {
                $attempt->$key = $value;
            }
            $attempt->save();
            $this->event($attempt, 'request_started');

            return [$attempt, true, $rial, (string) $case->tracking_reference];
        }, 3);

        if (! $created) {
            return $attempt;
        }

        $response = $this->gateway->call('request', [
            'amount' => $rial,
            'orderId' => $attempt->public_id,
            'callbackUrl' => config('payments.zibal.callback_url'),
            'description' => 'پرداخت هزینه تعمیر نوین‌پی — کد '.$trackingRef,
        ]);

        return DB::transaction(function () use ($attempt, $response): PaymentAttempt {
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $track = ZibalGateway::trackNumber($response['trackId'] ?? null);
            if (($response['result'] ?? null) === 100 && $track !== null) {
                $current->track_id = (string) $track;
                $current->state = 'pending';
            } elseif (in_array($response['result'] ?? null, self::REQUEST_REJECTIONS, true)) {
                $current->state = 'failed';
            } else {
                $current->state = 'uncertain';
            }
            $current->retry_at = now()->addMinutes(2);
            $current->save();
            $this->event($current, $current->state === 'failed' ? 'request_rejected_'.$response['result'] : $current->state);

            return $current;
        }, 3);
    }

    private function resolveRepairAccount(RepairCase $case, FinancialDocument $document): CustomerAccount
    {
        if ($case->customer_account_id) {
            $existing = CustomerAccount::query()->find($case->customer_account_id);
            if ($existing) {
                return $existing;
            }
        }
        if ($document->customer_id) {
            $byCustomer = CustomerAccount::query()->where('customer_id', $document->customer_id)->first();
            if ($byCustomer) {
                return $byCustomer;
            }
        }
        $phone = RepairIdentity::phone((string) $case->contact_phone);
        $phoneDigest = $phone !== '' ? RepairIdentity::digest('phone', $phone) : null;
        if ($phoneDigest) {
            $byPhone = CustomerAccount::query()->where('phone_digest', $phoneDigest)->first();
            if ($byPhone) {
                return $byPhone;
            }
        }
        $account = new CustomerAccount;
        $account->public_id = (string) Str::uuid();
        $account->customer_id = $document->customer_id;
        $account->phone_digest = $phoneDigest;
        $account->phone = $phone !== '' ? $phone : null;
        $account->name = $case->customer_name ?: null;
        $account->verified_at = now();
        $account->is_active = true;
        $account->session_version = 1;
        $account->save();

        return $account;
    }

    public function verify(PaymentAttempt $attempt): PaymentAttempt
    {
        if ($attempt->fresh()->state === 'settled') {
            return $attempt->fresh();
        }
        abort_unless($this->gateway->canVerify(), 503, 'پرداخت آنلاین هنوز فعال نشده است.');
        $claim = DB::transaction(function () use ($attempt): ?PaymentAttempt {
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($current->state === 'settled' || ! $current->track_id || ($current->lease_until && $current->lease_until->isFuture())) {
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
        $track = ZibalGateway::trackNumber($claim->track_id);
        if ($track === null) {
            return $this->finish($claim, 'review', 'invalid_gateway_track');
        }
        $response = $this->gateway->call('verify', ['trackId' => $track]);
        $code = $response['result'] ?? null;
        if (! in_array($code, [100, 201], true)) {
            return $this->resolveUnverified($claim, $track);
        }
        if ($this->bindingConflicts($claim, $response) || (isset($response['status']) && $response['status'] !== 1)) {
            return $this->finish($claim, 'review');
        }
        // An incomplete verification response needs an independently bound, verified inquiry.
        if (! isset($response['orderId'], $response['amount']) || ($code === 201 && ! isset($response['status']))) {
            $inquiry = $this->gateway->call('inquiry', ['trackId' => $track]);
            if (($inquiry['result'] ?? null) !== 100 || ($inquiry['status'] ?? null) !== 1) {
                return $this->finish($claim, $this->needsReview($claim) ? 'review' : 'uncertain');
            }
            $response = $inquiry;
        }
        if (! $this->bindingMatches($claim, $response)) {
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

    private function resolveUnverified(PaymentAttempt $claim, int $track): PaymentAttempt
    {
        $inquiry = $this->gateway->call('inquiry', ['trackId' => $track]);
        $fallback = $this->needsReview($claim) ? 'review' : 'uncertain';
        if (($inquiry['result'] ?? null) !== 100 || ! $this->bindingMatches($claim, $inquiry)) {
            return $this->finish($claim, $fallback);
        }

        $status = $inquiry['status'] ?? null;

        return match ($status) {
            -1 => $this->finish($claim, $fallback === 'review' ? 'review' : 'pending', 'gateway_waiting'),
            3 => $this->finish($claim, 'failed', 'gateway_canceled'),
            4, 5, 6, 7, 8, 9, 10, 12, 21 => $this->finish($claim, 'failed', 'gateway_unpaid_'.$status),
            15, 16, 18 => $this->finish($claim, 'review', 'gateway_return_'.$status),
            // Status 2 has not been verified. A status-1 inquiry after a failed
            // verify is reconciled on a later accepted verify (e.g. result 201).
            default => $this->finish($claim, $fallback),
        };
    }

    private function bindingMatches(PaymentAttempt $attempt, array $response): bool
    {
        return isset($response['orderId'], $response['amount']) && ! $this->bindingConflicts($attempt, $response);
    }

    private function bindingConflicts(PaymentAttempt $attempt, array $response): bool
    {
        $expected = ['orderId' => $attempt->public_id, 'amount' => (string) $attempt->amount_rial, 'trackId' => $attempt->track_id];
        foreach ($expected as $key => $value) {
            if (! isset($response[$key])) {
                continue;
            }
            $actual = $response[$key];
            if ((! is_int($actual) && ! is_string($actual)) || (string) $actual !== $value) {
                return true;
            }
        }

        return false;
    }

    private function needsReview(PaymentAttempt $attempt): bool
    {
        return $attempt->attempts >= (int) config('payments.zibal.reconciliation_limit')
            || $attempt->created_at->lte(now()->subMinutes((int) config('payments.zibal.review_after_minutes')));
    }

    /** Fair, bounded work; missing track IDs never cause a second request. */
    public function reconcile(int $limit = 40, int $budgetSeconds = 180): int
    {
        $started = microtime(true);
        $processed = 0;
        $attempts = PaymentAttempt::query()->whereIn('state', ['requesting', 'pending', 'uncertain', 'processing'])
            ->where('created_at', '<=', now()->subMinutes(2))
            ->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->orderBy('retry_at')->orderBy('id')->limit($limit)->get();
        foreach ($attempts as $attempt) {
            if (microtime(true) - $started >= $budgetSeconds) {
                break;
            }
            if (! $attempt->track_id || $attempt->attempts >= (int) config('payments.zibal.reconciliation_limit')) {
                DB::transaction(function () use ($attempt): void {
                    $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                    if (in_array($current->state, ['requesting', 'pending', 'uncertain', 'processing'], true)
                        && (! $current->lease_until || $current->lease_until->isPast())) {
                        $current->state = 'review';
                        $current->retry_at = null;
                        $current->lease_until = null;
                        $current->save();
                        $this->event($current, $current->track_id ? 'reconciliation_exhausted' : 'request_outcome_unknown');
                    }
                }, 3);
            } else {
                $this->verify($attempt);
            }
            $processed++;
        }

        return $processed;
    }

    private function finish(PaymentAttempt $attempt, string $state, ?string $event = null): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $state, $event): PaymentAttempt {
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($current->state !== 'settled' && (int) $current->attempts === (int) $attempt->attempts) {
                $current->state = $state;
                $current->lease_until = null;
                $current->retry_at = in_array($state, ['failed', 'review'], true) ? null
                    : now()->addSeconds(min(3600, 120 * (2 ** min(5, max(0, $current->attempts - 1)))));
                $current->save();
                $this->event($current, $event ?? $state);
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
