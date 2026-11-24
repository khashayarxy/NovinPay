<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\RepairCase;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Notifications\UnifiedNotifier;
use App\Support\FinanceAccess;
use App\Support\OrderMoney;
use App\Support\RepairTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeliveryManagement
{
    public const CHECKOUT_METHODS = [
        'pickup' => ['تحویل حضوری', 'مراجعه حضوری و تحویل مستقیم دستگاه از فروشگاه'],
        'post' => ['پست', 'ارسال از طریق پست پیشتاز به سراسر کشور'],
        'tipax' => ['تیپاکس', 'هزینه هنگام تحویل به شرکت حمل پرداخت می‌شود.'],
        'courier' => ['پیک', 'ارسال سریع با پیک پس از هماهنگی نشانی'],
    ];

    public static function courierAvailable(?string $province, ?string $city): bool
    {
        $normalize = static fn (?string $value): string => preg_replace('/[\s\x{200c}]+/u', '', strtr($value ?? '', ['ي' => 'ی', 'ك' => 'ک'])) ?? '';
        foreach (config('delivery.courier_zones', []) as $region => $cities) {
            if ($normalize($region) === $normalize($province)) {
                return in_array($normalize($city), array_map($normalize, $cities), true);
            }
        }

        return false;
    }

    private function checkCourier(string $method, ?CustomerAddress $address): void
    {
        if ($method === 'courier' && ! self::courierAvailable($address?->province, $address?->city)) {
            throw ValidationException::withMessages(['method' => 'برای این نشانی، پست یا تیپاکس را انتخاب کنید.']);
        }
    }

    public function checkoutAddress(CustomerAccount $account, ?string $addressId, string $method): ?array
    {
        if ($method === 'pickup') {
            return null;
        }
        $address = CustomerAddress::query()->where('public_id', $addressId)->where('customer_account_id', $account->id)->lockForUpdate()->firstOrFail();

        $this->checkCourier($method, $address);

        return $this->addressSnapshot($address);
    }

    public function applyCheckoutPreference(FinancialDocument $document, SalesOrder $order): void
    {
        $encrypted = DB::table('cart_purchase_snapshots')->where('sales_order_id', $order->id)->value('delivery');
        if (! $encrypted || ! $order->customer_account_id) {
            return;
        }
        $preference = decrypt($encrypted);
        $plan = new DeliveryPlan;
        $plan->financial_document_id = $document->id;
        $plan->public_id = (string) Str::uuid();
        $plan->customer_account_id = $order->customer_account_id;
        $plan->requested_method = $preference['method'];
        $plan->requested_address = $preference['address'];
        $plan->version = 1;
        $plan->save();
        $this->event($plan, 'change_requested', null, $order->customer_account_id);
    }

    private function addressSnapshot(CustomerAddress $address): array
    {
        return ['recipient' => $address->recipient, 'phone' => $address->phone, 'province' => $address->province, 'city' => $address->city, 'address' => $address->address, 'postal_code' => $address->postal_code];
    }

    public function request(CustomerAccount $account, string $documentId, string $method, ?string $addressId, int $version): DeliveryPlan
    {
        abort_unless(in_array($method, ['pickup', 'post', 'tipax', 'courier'], true), 422);

        return DB::transaction(function () use ($account, $documentId, $method, $addressId, $version): DeliveryPlan {
            $fresh = CustomerAccount::query()->lockForUpdate()->findOrFail($account->id);
            abort_unless($fresh->session_version === $account->session_version, 403);
            $account = $fresh;
            $document = app(ZibalPayments::class)->ownedDocument($account, $documentId, true);
            $this->openSource($document);
            $plan = DeliveryPlan::query()->where('financial_document_id', $document->id)->lockForUpdate()->first();
            $plan ??= new DeliveryPlan;
            abort_unless(($plan->exists ? $plan->version === $version && $plan->state === 'planned' : $version === 0), 409, 'روش تحویل تغییر کرده یا دستگاه تحویل داده شده است.');
            $address = $method !== 'pickup' ? CustomerAddress::query()->where('public_id', $addressId)->where('customer_account_id', $account->id)->firstOrFail() : null;
            $plan->financial_document_id = $document->id;
            $plan->public_id ??= (string) Str::uuid();
            $plan->customer_account_id = $account->id;
            $this->checkCourier($method, $address);
            $plan->requested_method = $method;
            $plan->requested_address = $address ? $this->addressSnapshot($address) : null;
            $plan->version = $plan->exists ? $plan->version + 1 : 1;
            $plan->save();
            $this->event($plan, 'change_requested', null, $account->id);

            return $plan;
        }, 3);
    }

    public function confirm(User $actor, ?string $password, string $id, int $version, string $operation): DeliveryPlan
    {
        return DB::transaction(function () use ($actor, $password, $id, $version, $operation): DeliveryPlan {
            $actor = $password !== null && $password !== ''
                ? app(PaymentReview::class)->actor($actor, $password, 'credit')
                : app(PaymentReview::class)->authorizedActor($actor, 'credit');
            $lookup = DeliveryPlan::query()->where('public_id', $id)->firstOrFail();
            $document = FinancialDocument::query()->lockForUpdate()->findOrFail($lookup->financial_document_id);
            $this->openSource($document);
            FinanceAccess::source($actor, $document->sales_order_id ? 'order' : 'repair');
            Gate::forUser($actor)->authorize($document->sales_order_id ? 'orders.fulfill' : 'repairs.deliver');
            Gate::forUser($actor)->authorize($document->sales_order_id ? 'orders.customer_data' : 'repairs.customer_data');
            $plan = DeliveryPlan::query()->lockForUpdate()->findOrFail($lookup->id);
            abort_unless($plan->state === 'planned' && $plan->version === $version && $plan->requested_method, 409);
            // No postal amount may enter a final invoice without the verified adapter.
            abort_if($plan->requested_method === 'post', 503, 'استعلام پستی تأیید نشده است؛ روش دیگری انتخاب کنید.');
            if ($plan->shipping_minor !== 0) {
                $this->adjust($actor, (string) $password, $document, -$plan->shipping_minor, $operation);
            }
            $plan->method = $plan->requested_method;
            $plan->address_snapshot = $plan->requested_address;
            $plan->requested_method = null;
            $plan->requested_address = null;
            $plan->shipping_minor = 0; // Pickup and carrier-paid delivery never add postage.
            $plan->version++;
            $plan->save();
            $this->event($plan, 'change_confirmed', $actor->id);

            return $plan;
        }, 3);
    }

    public function adjust(User $actor, string $password, FinancialDocument $document, int $delta, string $operation): void
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $actor = app(PaymentReview::class)->actor($actor, $password, 'credit');
        FinanceAccess::source($actor, $document->sales_order_id ? 'order' : 'repair');
        app(FinancialOperations::class)->reconcile($document);
        if ($delta === 0) {
            return;
        }
        $applicable = $delta < 0 ? min(-$delta, $document->charged_minor - $document->paid_minor) : $delta;
        if ($applicable > 0) {
            app(FinancialOperations::class)->execute($actor, $delta > 0 ? 'debit' : 'credit', ['operation_key' => $operation, 'password' => $password, 'reason' => 'اصلاح هزینهٔ ارسال با حفظ سوابق پرداخت', 'document' => $document->public_id, 'version' => $document->version, 'amount' => OrderMoney::decimal($applicable)]);
        }
        $excess = $delta < 0 ? -$delta - $applicable : 0;
        if ($excess > 0) {
            // Paid invoice stays immutable; the separate credit note is a refund liability.
            // Never delete/rewrite a confirmed receipt to fit a changed shipping cost.
            DB::table('payment_credit_events')->insert(['operation_key' => $operation, 'financial_document_id' => $document->id, 'actor_id' => $actor->id, 'amount_delta' => $excess, 'reason' => encrypt('بستانکاری بابت کاهش هزینهٔ ارسالِ قبلاً پرداخت‌شده'), 'created_at' => now()]);
        }
    }

    public function dispatch(User $actor, ?string $password, string $id, int $version, string $tracking, string $recipient): void
    {
        DB::transaction(function () use ($actor, $password, $id, $version, $tracking, $recipient): void {
            $skipPassword = $password === null || $password === '';
            $actor = $skipPassword
                ? app(PaymentReview::class)->authorizedActor($actor)
                : app(PaymentReview::class)->actor($actor, $password);
            $lookup = DeliveryPlan::query()->where('public_id', $id)->firstOrFail();
            $document = FinancialDocument::query()->lockForUpdate()->findOrFail($lookup->financial_document_id);
            FinanceAccess::source($actor, $document->sales_order_id ? 'order' : 'repair');
            Gate::forUser($actor)->authorize($document->sales_order_id ? 'orders.fulfill' : 'repairs.deliver');
            Gate::forUser($actor)->authorize($document->sales_order_id ? 'orders.customer_data' : 'repairs.customer_data');
            $plan = DeliveryPlan::query()->lockForUpdate()->findOrFail($lookup->id);
            abort_unless($plan->version === $version && $plan->state === 'planned' && $plan->requested_method === null, 409);
            abort_unless($document->paid_minor === $document->charged_minor, 409, 'ماندهٔ سند باید پیش از تحویل تسویه شود.');
            $case = $document->repair_case_id ? RepairCase::query()->findOrFail($document->repair_case_id) : null;
            if ($case?->simple_workflow) {
                app(SimpleRepairRecords::class)->save($actor, $case, [
                    'intent' => 'handover', 'version' => $case->version, 'handover_confirmed' => true,
                    'shipment_tracking' => $tracking, 'recipient_name' => $recipient, 'current_password' => $password ?? '',
                    'skip_password_check' => $skipPassword,
                    'confirmed_delivery_method' => $plan->method,
                ]);

                return;
            }
            app(FinancialOperations::class)->reconcile($document);
            $plan->tracking = $tracking;
            $plan->save();
            if ($document->sales_order_id) {
                $order = SalesOrder::query()->findOrFail($document->sales_order_id);
                app(CommerceManagement::class)->execute($actor, 'order.fulfill', ['operation_key' => (string) Str::uuid(), 'version' => $order->version, 'reason' => 'ثبت تحویل واقعی پس از تسویه', 'fulfillment_info' => $recipient.' / '.$tracking], $order);
            } else {
                app(RepairOperations::class)->update($actor, $case, 'delivery', ['version' => $case->version, 'recipient_name' => $recipient, 'delivered_at' => RepairTime::input(), 'outcome_notes' => 'تحویل واقعی با مرجع '.$tracking, 'handover_confirmed' => true, 'amounts_confirmed' => true, 'current_password' => $password ?? '', 'skip_password_check' => $skipPassword]);
            }
        }, 3);
    }

    public function announceOrderProgress(User $actor, ?string $password, string $id, int $version, string $progress): void
    {
        DB::transaction(function () use ($actor, $password, $id, $version, $progress): void {
            $actor = $password !== null && $password !== ''
                ? app(PaymentReview::class)->actor($actor, $password)
                : app(PaymentReview::class)->authorizedActor($actor);
            Gate::forUser($actor)->authorize('orders.fulfill');
            Gate::forUser($actor)->authorize('orders.customer_data');
            $lookup = DeliveryPlan::query()->where('public_id', $id)->firstOrFail();
            $document = FinancialDocument::query()->lockForUpdate()->findOrFail($lookup->financial_document_id);
            abort_unless($document->sales_order_id, 404);
            $order = SalesOrder::query()->findOrFail($document->sales_order_id);
            $plan = DeliveryPlan::query()->lockForUpdate()->findOrFail($lookup->id);
            abort_unless($order->status === 'confirmed' && $plan->state === 'planned'
                && $plan->version === $version && $plan->requested_method === null, 409);
            abort_unless($progress === 'preparing' || ($progress === 'pickup_ready' && $plan->method === 'pickup'), 409);

            $event = 'order_'.$progress;
            if (! DB::table('delivery_events')->where('delivery_plan_id', $plan->id)->where('action', $event)->exists()) {
                $this->event($plan, $event, $actor->id);
            }
            // Re-announcing can recover a pre-send configuration skip without writing another history entry.
            DB::afterCommit(fn () => app(UnifiedNotifier::class)->orderPattern($order, $event));
        }, 3);
    }

    public function completePlan(FinancialDocument $document, User $actor): void
    {
        $plan = DeliveryPlan::query()->where('financial_document_id', $document->id)->lockForUpdate()->first();
        abort_unless($plan && ! $plan->requested_method && $plan->state === 'planned', 409, 'روش تحویل باید تأیید شده باشد.');
        $trackingLength = mb_strlen(trim((string) $plan->tracking));
        abort_if($plan->method !== 'pickup' && ($trackingLength < 3 || $trackingLength > 200), 409,
            'برای ثبت ارسال، شرکت ارسال و کد پیگیری را وارد کنید.');
        $plan->state = $plan->method === 'pickup' ? 'collected' : 'dispatched';
        $plan->version++;
        $plan->save();
        $this->event($plan, $plan->state, $actor->id);
        if ($plan->state === 'dispatched') {
            app(CustomerNotifications::class)->record($plan->customer_account_id, 'device_shipped', $plan->public_id.':'.$plan->version, '/account/documents/'.$document->public_id.'/delivery');
            if ($document->sales_order_id) {
                $orderId = $document->sales_order_id;
                $tracking = $plan->tracking;
                DB::afterCommit(static function () use ($orderId, $tracking): void {
                    $order = SalesOrder::query()->find($orderId);
                    if ($order) {
                        app(UnifiedNotifier::class)->orderShipped($order, $tracking);
                    }
                });
            }
        }
    }

    private function event(DeliveryPlan $plan, string $action, ?int $actor = null, ?int $customer = null): void
    {
        DB::table('delivery_events')->insert(['delivery_plan_id' => $plan->id, 'actor_id' => $actor, 'customer_account_id' => $customer, 'action' => $action, 'version' => $plan->version, 'created_at' => now()]);
    }

    private function openSource(FinancialDocument $document): void
    {
        if ($document->sales_order_id) {
            $status = SalesOrder::query()->whereKey($document->sales_order_id)->value('status');
            abort_if(in_array($status, ['fulfilled', 'cancelled'], true), 409, 'تحویل یا لغو سفارش ثبت شده است؛ روش ارسال قابل تغییر نیست.');
        } else {
            $case = RepairCase::query()->findOrFail($document->repair_case_id);
            abort_if($case->status->closed(), 409, 'دستگاه قبلاً تحویل داده شده است.');
        }
    }
}
