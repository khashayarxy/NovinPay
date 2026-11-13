<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\FinancialEntry;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\Product;
use App\Models\RepairCase;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\CommerceManagement;
use App\Services\CustomerNotifications;
use App\Services\DeliveryManagement;
use App\Services\FinancialOperations;
use App\Services\PaymentReview;
use App\Services\StoreCheckout;
use App\Services\ZibalPayments;
use App\Support\RepairIdentity;
use App\Support\SiteMaintenanceState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SharedPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CustomerAccount $account;

    private FinancialDocument $document;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->owner = User::factory()->superAdmin()->create();
        $this->actingAs($this->owner);
        $customer = new Customer;
        foreach (['public_id' => (string) Str::uuid(), 'name' => 'SYNTHETIC', 'contact_phone' => '09000000000', 'name_digest' => bin2hex(random_bytes(32)), 'phone_digest' => bin2hex(random_bytes(32)), 'identity_digest' => bin2hex(random_bytes(32)), 'is_active' => true] as $key => $value) {
            $customer->$key = $value;
        }
        $customer->save();
        $order = new SalesOrder;
        foreach (['public_id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'status' => 'fulfilled', 'currency' => 'TOMAN', 'total' => '20000.00', 'version' => 1, 'created_by' => $this->owner->id] as $key => $value) {
            $order->$key = $value;
        }
        $order->save();
        $entry = app(FinancialOperations::class)->execute($this->owner, 'issue', ['operation_key' => (string) Str::uuid(), 'password' => 'password', 'reason' => 'Synthetic test charge', 'source_type' => 'order', 'source' => $order->public_id, 'source_version' => 1]);
        $this->document = FinancialDocument::findOrFail(FinancialEntry::findOrFail($entry)->financial_document_id);
        $this->account = new CustomerAccount;
        foreach (['public_id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'phone' => '09000000000', 'phone_digest' => RepairIdentity::digest('customer-account-phone', '09000000000'), 'verified_at' => now()] as $key => $value) {
            $this->account->$key = $value;
        }
        $this->account->save();
        $this->account->refresh();
        $order->customer_account_id = $this->account->id;
        $order->status = 'confirmed';
        $order->save();
        $plan = new DeliveryPlan;
        foreach (['public_id' => (string) Str::uuid(), 'financial_document_id' => $this->document->id, 'customer_account_id' => $this->account->id, 'method' => 'tipax'] as $key => $value) {
            $plan->$key = $value;
        }
        $plan->save();
    }

    private function gateway(array $verify = []): void
    {
        config(['app.url' => 'https://novinpaypos.test', 'payments.zibal.enabled' => true, 'payments.zibal.live_requests' => true, 'payments.zibal.merchant' => 'fixture-merchant', 'payments.zibal.callback_url' => 'https://novinpaypos.test/bot/payment_verify.php']);
        Http::fake(function ($request) use ($verify) {
            if (str_ends_with($request->url(), '/request')) {
                return Http::response(['result' => 100, 'trackId' => '501']);
            }
            $attempt = PaymentAttempt::firstOrFail();

            return Http::response($verify + ['result' => 100, 'amount' => 100000, 'orderId' => $attempt->public_id]);
        });
    }

    public function test_confirmed_shipping_dispatches_simple_repair_only_after_payment(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->withSession(['repair_intake_token' => $token]);
        $this->post('/admin/repairs/intake', [
            'simple_intake' => '1', 'submission_token' => $token, 'customer_name' => 'Synthetic Customer',
            'contact_phone' => '09000000000', 'device_model' => 'SYNTHETIC', 'issue' => 'Synthetic issue', 'physical_condition' => 'Synthetic condition',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $case = RepairCase::query()->sole();
        $case->forceFill(['customer_account_id' => $this->account->id, 'customer_id' => $this->account->customer_id])->save();
        $url = route('admin.repair-cases.record', $case);
        $this->post($url, ['version' => 1, 'status' => 'ready', 'repair_cost' => '1000'])->assertSessionHasNoErrors();
        $entry = app(FinancialOperations::class)->execute($this->owner, 'issue', [
            'operation_key' => (string) Str::uuid(), 'password' => 'password', 'reason' => 'Synthetic repair charge',
            'source_type' => 'repair', 'source' => $case->public_id, 'source_version' => 2,
        ]);
        $document = FinancialDocument::findOrFail(FinancialEntry::findOrFail($entry)->financial_document_id);
        $plan = new DeliveryPlan;
        $plan->forceFill(['public_id' => (string) Str::uuid(), 'financial_document_id' => $document->id,
            'customer_account_id' => $this->account->id, 'method' => 'courier'])->save();
        $this->post($url, ['version' => 2, 'status' => 'ready', 'delivery_method' => 'shipping'])->assertSessionHasNoErrors();
        $this->assertSame('planned', $plan->fresh()->state);
        try {
            app(DeliveryManagement::class)->dispatch($this->owner, 'password', $plan->public_id, 1, 'SYNTHETIC', 'Synthetic recipient');
            $this->fail('An unpaid repair was dispatched.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        app(PaymentReview::class)->inStore($this->owner, 'password', $document->public_id, $document->charged_minor, (string) Str::uuid());
        $this->post($url, ['version' => 3, 'intent' => 'settlement', 'settlement_method' => 'in_person'])->assertSessionHasNoErrors();
        $this->post($url, ['version' => 4, 'status' => 'ready', 'repair_notes' => 'Synthetic note'])->assertSessionHasNoErrors();
        $this->assertSame('in_person', $case->fresh()->settlement_method);
        $this->assertSame('planned', $plan->fresh()->state);
        app(DeliveryManagement::class)->dispatch($this->owner, 'password', $plan->public_id, 1, 'SYNTHETIC', 'Synthetic recipient');
        $this->assertSame('delivered', $case->fresh()->status->value);
        $this->assertSame('courier', $case->fresh()->delivery_carrier);
        $this->assertSame('dispatched', $plan->fresh()->state);
        $this->assertSame('SYNTHETIC', $plan->fresh()->tracking);
        $this->assertSame('SYNTHETIC', $case->fresh()->delivery->confirmation_reference);
    }

    public function test_disabled_payments_make_no_request_and_do_not_create_attempts(): void
    {
        Http::fake();
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1]);
        $this->post('/account/documents/'.$this->document->public_id.'/zibal', ['amount' => '10000', 'operation_key' => (string) Str::uuid()])->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    private function enableGateway(): void
    {
        config(['app.url' => 'https://novinpaypos.test', 'payments.zibal.enabled' => true,
            'payments.zibal.live_requests' => true, 'payments.zibal.merchant' => 'fixture-merchant',
            'payments.zibal.callback_url' => 'https://novinpaypos.test/bot/payment_verify.php']);
    }

    public function test_unknown_request_is_not_failed_or_replayed_and_can_be_audited_for_manual_resolution(): void
    {
        $this->enableGateway();
        $available = false;
        $requests = 0;
        Http::fake(function () use (&$available, &$requests) {
            $requests++;
            if (! $available) {
                throw new ConnectionException('Synthetic timeout');
            }

            return Http::response(['result' => 100, 'trackId' => '504']);
        });
        $service = app(ZibalPayments::class);
        $key = (string) Str::uuid();
        $attempt = $service->start($this->account, $this->document->public_id, 1000000, $key);
        $this->assertSame('uncertain', $attempt->state);
        $this->assertNull($attempt->track_id);
        $this->travel(3)->minutes();
        $this->assertSame(1, $service->reconcile());
        $this->assertSame('review', $attempt->fresh()->state);
        $this->assertSame(1, $requests);
        $url = route('admin.payments.attempt.resolve', $attempt->public_id);
        $payload = ['password' => 'password', 'unpaid_confirmed' => '1', 'note' => 'Synthetic independent gateway evidence'];
        $this->post($url, array_replace($payload, ['password' => 'incorrect']))->assertForbidden();
        $this->post($url, array_diff_key($payload, ['unpaid_confirmed' => true]))->assertSessionHasErrors('unpaid_confirmed');
        $this->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('failed', $attempt->fresh()->state);
        $this->assertSame($this->owner->id, (int) $attempt->fresh()->resolved_by);
        $this->assertNotSame($payload['note'], DB::table('payment_attempts')->where('id', $attempt->id)->value('resolution_note'));
        $this->assertDatabaseHas('payment_events', ['payment_attempt_id' => $attempt->id, 'event' => 'manual_unpaid_resolution']);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertSame($attempt->id, $service->start($this->account, $this->document->public_id, 1000000, $key)->id);
        $this->assertSame(1, $requests);
        $available = true;
        $this->assertSame('pending', $service->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid())->state);
    }

    public function test_definitive_request_rejection_is_terminal_without_reusing_its_operation(): void
    {
        $this->enableGateway();
        Http::fake(['gateway.zibal.ir/*' => Http::response(['result' => 104])]);
        $service = app(ZibalPayments::class);
        $attempt = $service->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $this->assertSame('failed', $attempt->state);
        $this->assertDatabaseHas('payment_events', ['payment_attempt_id' => $attempt->id, 'event' => 'request_rejected_104']);
        $next = $service->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $this->assertNotSame($attempt->id, $next->id);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_canceled_attempt_still_accepts_a_late_bound_success_exactly_once(): void
    {
        $this->enableGateway();
        $paid = false;
        Http::fake(function ($request) use (&$paid) {
            if (str_ends_with($request->url(), '/request')) {
                return Http::response(['result' => 100, 'trackId' => '505']);
            }
            $binding = ['amount' => 100000, 'orderId' => PaymentAttempt::firstOrFail()->public_id];

            return Http::response($paid ? ['result' => 100] + $binding : (str_ends_with($request->url(), '/inquiry')
                ? ['result' => 100, 'status' => 3] + $binding : ['result' => 202]));
        });
        $service = app(ZibalPayments::class);
        $attempt = $service->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $this->assertSame('failed', $service->verify($attempt)->state);
        $this->assertDatabaseCount('payment_transactions', 0);
        $paid = true;
        $this->assertSame('settled', $service->verify($attempt)->state);
        $this->assertSame('settled', $service->verify($attempt)->state);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_reconciliation_exhaustion_cannot_starve_later_attempts_or_forge_a_failure(): void
    {
        $this->gateway();
        $service = app(ZibalPayments::class);
        $first = $service->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $first->attempts = config('payments.zibal.reconciliation_limit');
        $first->save();
        $later = $first->replicate();
        $later->public_id = (string) Str::uuid();
        $later->operation_key = (string) Str::uuid();
        $later->track_id = '506';
        $later->attempts = 0;
        $later->save();
        $this->travel(3)->minutes();
        $this->assertSame(1, $service->reconcile(1));
        $this->assertSame('review', $first->fresh()->state);
        $this->assertSame(1, $service->reconcile(1));
        $this->assertGreaterThan(0, $later->fresh()->attempts);
        $this->assertDatabaseHas('payment_events', ['payment_attempt_id' => $first->id, 'event' => 'reconciliation_exhausted']);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_manual_resolution_requires_permissions_and_never_interrupts_a_verification_lease(): void
    {
        $this->gateway();
        $attempt = app(ZibalPayments::class)->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $attempt->state = 'review';
        $attempt->lease_until = now()->addMinutes(2);
        $attempt->save();
        $url = route('admin.payments.attempt.resolve', $attempt->public_id);
        $payload = ['password' => 'password', 'unpaid_confirmed' => '1', 'note' => 'Synthetic independent gateway evidence'];
        $this->post($url, $payload)->assertConflict();
        $this->actingAs(User::factory()->staff(['finance.view', 'finance.details', 'orders.view'])->create());
        $this->post($url, $payload)->assertForbidden();
        $this->assertNull($attempt->fresh()->resolved_at);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->travel(3)->minutes();
        $this->actingAs($this->owner)->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('settled', app(ZibalPayments::class)->verify($attempt)->state);
        $this->assertDatabaseCount('payment_transactions', 1);
        $migration = require database_path('migrations/2026_11_13_000001_add_payment_resolution_metadata.php');
        $this->expectExceptionMessage('Preserve payment resolution history');
        $migration->down();
    }

    public function test_malformed_provider_track_is_never_used_as_gateway_identifier(): void
    {
        config(['app.url' => 'https://novinpaypos.test', 'payments.zibal.enabled' => true, 'payments.zibal.live_requests' => true, 'payments.zibal.merchant' => 'fixture-merchant', 'payments.zibal.callback_url' => 'https://novinpaypos.test/bot/payment_verify.php']);
        Http::fake(['gateway.zibal.ir/*' => Http::response(['result' => 100, 'trackId' => true])]);
        $attempt = app(ZibalPayments::class)->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $this->assertSame('uncertain', $attempt->state);
        $this->assertNull($attempt->track_id);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_valid_callback_and_duplicate_callback_settle_exactly_once_with_partial_balance(): void
    {
        $this->gateway();
        $service = app(ZibalPayments::class);
        $operation = (string) Str::uuid();
        $attempt = $service->start($this->account, $this->document->public_id, 1000000, $operation);
        $this->assertSame($attempt->id, $service->start($this->account, $this->document->public_id, 1000000, $operation)->id);
        $this->get('/bot/payment_verify.php?trackId=501&orderId='.$attempt->public_id.'&success=1')->assertRedirectContains('/pay/success.html');
        $this->get('/bot/payment_verify.php?trackId=501&orderId='.$attempt->public_id.'&success=0')->assertRedirectContains('/pay/success.html');
        $this->assertSame(1000000, $this->document->fresh()->paid_minor);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame(1, FinancialEntry::query()->where('method', 'gateway')->count());
        $this->assertNull(FinancialEntry::query()->where('method', 'gateway')->value('actor_id'));
        $this->assertTrue(app(FinancialOperations::class)->reconcile($this->document->fresh())['charged'] === 2000000);
    }

    public function test_amount_mismatch_and_unknown_callback_cannot_credit_money(): void
    {
        $this->gateway(['amount' => 100001]);
        $service = app(ZibalPayments::class);
        $attempt = $service->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $this->assertSame('review', $service->verify($attempt)->state);
        $this->get('/bot/payment_verify.php?trackId=999&success=1')->assertNotFound();
        $this->assertSame(0, $this->document->fresh()->paid_minor);
    }

    public function test_failed_and_uncertain_provider_responses_never_settle(): void
    {
        config(['app.url' => 'https://novinpaypos.test', 'payments.zibal.enabled' => true, 'payments.zibal.live_requests' => true, 'payments.zibal.merchant' => 'fixture-merchant', 'payments.zibal.callback_url' => 'https://novinpaypos.test/bot/payment_verify.php']);
        $paid = false;
        Http::fake(function ($request) use (&$paid) {
            if (str_ends_with($request->url(), '/request')) {
                return Http::response(['result' => 100, 'trackId' => '503']);
            }

            return Http::response($paid ? ['result' => 100, 'amount' => 100000, 'orderId' => PaymentAttempt::firstOrFail()->public_id] : ['result' => 202]);
        });
        $attempt = app(ZibalPayments::class)->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $this->assertSame('uncertain', app(ZibalPayments::class)->verify($attempt)->state);
        $this->assertDatabaseCount('payment_transactions', 0);
        $paid = true;
        $this->assertSame('settled', app(ZibalPayments::class)->verify($attempt)->state);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_paid_initialization_replay_returns_receipt_without_second_gateway_request(): void
    {
        $this->gateway();
        $service = app(ZibalPayments::class);
        $operation = (string) Str::uuid();
        $attempt = $service->start($this->account, $this->document->public_id, 1000000, $operation);
        $service->verify($attempt);
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1]);
        $this->post('/account/documents/'.$this->document->public_id.'/zibal', ['amount' => '10000', 'operation_key' => $operation])->assertRedirectContains('/pay/success.html');
        Http::assertSentCount(2);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_missing_verified_amount_requires_independent_inquiry_binding(): void
    {
        config(['app.url' => 'https://novinpaypos.test', 'payments.zibal.enabled' => true, 'payments.zibal.live_requests' => true, 'payments.zibal.merchant' => 'fixture-merchant', 'payments.zibal.callback_url' => 'https://novinpaypos.test/bot/payment_verify.php']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/request')) {
                return Http::response(['result' => 100, 'trackId' => '502']);
            }
            if (str_ends_with($request->url(), '/verify')) {
                return Http::response(['result' => 201]);
            }

            return Http::response(['result' => 100, 'status' => 1, 'orderId' => PaymentAttempt::firstOrFail()->public_id, 'amount' => 100000]);
        });
        $service = app(ZibalPayments::class);
        $attempt = $service->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $this->assertSame('settled', $service->verify($attempt)->state);
        Http::assertSentCount(3);
        $this->assertSame(1000000, $this->document->fresh()->paid_minor);
    }

    public function test_lease_excludes_duplicate_verification_and_stale_worker_cannot_settle(): void
    {
        $this->gateway();
        $service = app(ZibalPayments::class);
        $attempt = $service->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $attempt->lease_until = now()->addMinute();
        $attempt->save();
        $this->assertSame('pending', $service->verify($attempt)->state);
        Http::assertSentCount(1);
        $attempt->lease_until = null;
        $attempt->save();
        Http::fake(function () use ($attempt) {
            DB::table('payment_attempts')->where('id', $attempt->id)->increment('attempts');

            return Http::response(['result' => 100, 'orderId' => $attempt->public_id, 'amount' => 100000]);
        });
        $this->assertSame('processing', $service->verify($attempt)->state);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertSame(0, $this->document->fresh()->paid_minor);
    }

    public function test_receipt_review_requires_actual_confirmation_and_is_not_automatic(): void
    {
        $receipt = new PaymentReceipt;
        foreach (['public_id' => (string) Str::uuid(), 'financial_document_id' => $this->document->id, 'customer_account_id' => $this->account->id, 'amount_minor' => 500000, 'path' => 'payment-receipts/synthetic.png', 'file_hash' => hash('sha256', 'synthetic')] as $key => $value) {
            $receipt->$key = $value;
        }
        $receipt->save();
        $this->assertSame(0, $this->document->fresh()->paid_minor);
        app(PaymentReview::class)->review($this->owner, 'password', $receipt->public_id, true, 'Confirmed actual funds', 500000, 'SYNTHETIC-REF');
        $this->assertSame(500000, $this->document->fresh()->paid_minor);
        $this->assertSame('approved', $receipt->fresh()->state);
        $this->assertSame('transfer', FinancialEntry::query()->where('kind', 'receipt')->value('method'));
        $this->assertSame('card_transfer', DB::table('payment_transactions')->value('method'));
        $this->expectException(HttpException::class);
        app(PaymentReview::class)->review($this->owner, 'password', $receipt->public_id, true, 'Duplicate review', 500000, 'SYNTHETIC-REF');
    }

    public function test_in_store_receipt_is_idempotent_and_excess_is_retained_as_credit(): void
    {
        $service = app(PaymentReview::class);
        $op = (string) Str::uuid();
        $one = $service->inStore($this->owner, 'password', $this->document->public_id, 2500000, $op);
        $this->assertSame($one->id, $service->inStore($this->owner, 'password', $this->document->public_id, 2500000, $op)->id);
        $this->assertSame(2000000, $this->document->fresh()->paid_minor);
        $this->assertSame(500000, (int) DB::table('payment_credit_events')->sum('amount_delta'));
        $service->recordRefund($this->owner, 'password', $this->document->public_id, 100000, 'SYNTHETIC-REFUND', (string) Str::uuid());
        $this->assertSame(400000, (int) DB::table('payment_credit_events')->sum('amount_delta'));
    }

    public function test_customer_cannot_see_another_documents_or_public_receipts(): void
    {
        $this->get('/bot/check_payment_status.php?order_id='.Str::uuid().'&track_id=501')->assertNotFound();
        $this->get('/payment-receipts/'.Str::uuid())->assertNotFound();
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1]);
        $this->get('/account/documents/'.Str::uuid())->assertNotFound();
        $this->get('/account/documents/'.$this->document->public_id)->assertOk();
    }

    public function test_reference_payment_pages_and_assets_are_byte_identical(): void
    {
        foreach (json_decode(file_get_contents(resource_path('payments/reference-hashes.json')), true, flags: JSON_THROW_ON_ERROR) as $path => $hash) {
            $this->assertSame($hash, hash_file('sha256', base_path($path)), $path);
        }
        $this->get('/pay/success.html')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_carrier_paid_shipping_and_pickup_have_no_charge_and_require_operator_confirmation(): void
    {
        $address = new CustomerAddress;
        foreach (['public_id' => (string) Str::uuid(), 'customer_account_id' => $this->account->id, 'recipient' => 'SYNTHETIC', 'phone' => '09000000000', 'province' => 'تهران', 'city' => 'شهریار', 'address' => 'SYNTHETIC PRIVATE ADDRESS', 'postal_code' => '1234567890'] as $key => $value) {
            $address->$key = $value;
        }
        $address->save();
        $service = app(DeliveryManagement::class);
        $plan = $service->request($this->account, $this->document->public_id, 'courier', $address->public_id, 1);
        $this->assertSame('tipax', $plan->method);
        $this->assertSame('courier', $plan->requested_method);
        $plan = $service->confirm($this->owner, 'password', $plan->public_id, $plan->version, (string) Str::uuid());
        $this->assertSame('courier', $plan->method);
        $this->assertSame(0, $plan->shipping_minor);
        $this->assertNull($plan->requested_method);
        $plan = $service->request($this->account, $this->document->public_id, 'pickup', null, $plan->version);
        $plan = $service->confirm($this->owner, 'password', $plan->public_id, $plan->version, (string) Str::uuid());
        $this->assertSame('pickup', $plan->method);
        $this->assertSame(2000000, $this->document->fresh()->charged_minor);
    }

    public function test_shipping_adjustment_keeps_paid_receipts_and_creates_refund_obligation(): void
    {
        app(PaymentReview::class)->inStore($this->owner, 'password', $this->document->public_id, 2000000, (string) Str::uuid());
        $before = FinancialEntry::query()->get()->map->getAttributes()->all();
        DB::transaction(fn () => app(DeliveryManagement::class)->adjust($this->owner, 'password', $this->document->fresh(), -100000, (string) Str::uuid()));
        $this->assertSame(100000, (int) DB::table('payment_credit_events')->sum('amount_delta'));
        $this->assertSame($before, FinancialEntry::query()->get()->map->getAttributes()->all());
        $this->assertSame(2000000, $this->document->fresh()->paid_minor);
    }

    public function test_unverified_postage_cannot_be_confirmed_and_dispatch_requires_settlement(): void
    {
        $plan = DeliveryPlan::firstOrFail();
        $plan->requested_method = 'post';
        $plan->save();
        try {
            app(DeliveryManagement::class)->confirm($this->owner, 'password', $plan->public_id, 1, (string) Str::uuid());
            $this->fail('Unverified postage accepted');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        $this->assertSame('tipax', $plan->fresh()->method);
        $plan->requested_method = null;
        $plan->save();
        $this->expectException(HttpException::class);
        app(DeliveryManagement::class)->dispatch($this->owner, 'password', $plan->public_id, 1, 'SYNTHETIC-TRACK', 'SYNTHETIC RECIPIENT');
    }

    public function test_head_callback_is_not_a_maintenance_exemption_and_browser_status_never_writes(): void
    {
        $this->assertTrue(SiteMaintenanceState::paymentCallback('/bot/payment_verify.php?trackId=1', 'GET'));
        foreach (['//host/bot/payment_verify.php', '/bot/payment_verify.php/extra', 'https://host/bot/payment_verify.php', '/bot/%70ayment_verify.php'] as $target) {
            $this->assertFalse(SiteMaintenanceState::paymentCallback($target, 'GET'));
        }
        $this->assertFalse(SiteMaintenanceState::paymentCallback('/bot/payment_verify.php', 'HEAD'));
        $this->assertDatabaseCount('payment_events', 0);
        $this->get('/bot/check_payment_status.php?order_id='.Str::uuid().'&track_id=1')->assertNotFound();
        $this->assertDatabaseCount('payment_events', 0);
    }

    public function test_store_checkout_uses_server_price_and_existing_reservation_workflow(): void
    {
        $product = Product::create(['name' => 'Synthetic terminal', 'slug' => 'synthetic-terminal', 'category' => 'pos_terminal', 'is_published' => true, 'is_sample' => false, 'condition' => 'new', 'stock_status' => 'in_stock', 'price' => 10000]);
        $commerce = app(CommerceManagement::class);
        $id = $commerce->execute($this->owner, 'item.create', ['operation_key' => (string) Str::uuid(), 'sku' => 'SYNTHETIC-STORE', 'name' => 'Synthetic stock', 'condition' => 'new', 'serialized' => false, 'product_id' => $product->id, 'low_stock_threshold' => 0, 'reason' => 'Synthetic store setup']);
        $item = InventoryItem::findOrFail($id);
        $commerce->execute($this->owner, 'item.receive', ['operation_key' => (string) Str::uuid(), 'version' => $item->version, 'quantity' => 2, 'reason' => 'Synthetic opening stock'], $item);
        $checkout = app(StoreCheckout::class);
        $key = (string) Str::uuid();
        $order = $checkout->create($this->account, [$product->slug => 2], $key);
        $this->assertSame('20000.00', $order->total);
        $this->assertNull($order->created_by);
        $this->assertSame($order->id, $checkout->create($this->account, [$product->slug => 2], $key)->id);
        $this->assertSame(0, $item->fresh()->reserved);
        $commerce->execute($this->owner, 'order.reserve', ['operation_key' => (string) Str::uuid(), 'version' => $order->version, 'reason' => 'Synthetic reservation'], $order);
        $commerce->execute($this->owner, 'order.confirm', ['operation_key' => (string) Str::uuid(), 'version' => $order->fresh()->version, 'reason' => 'Synthetic confirmation'], $order);
        $this->assertSame(2, $item->fresh()->reserved);
        $entry = app(FinancialOperations::class)->execute($this->owner, 'issue', ['operation_key' => (string) Str::uuid(), 'password' => 'password', 'reason' => 'Synthetic internet sale', 'source_type' => 'order', 'source' => $order->public_id, 'source_version' => $order->fresh()->version]);
        $this->assertSame(2000000, FinancialDocument::findOrFail(FinancialEntry::findOrFail($entry)->financial_document_id)->charged_minor);
        try {
            $commerce->execute($this->owner, 'order.fulfill', ['operation_key' => (string) Str::uuid(), 'version' => $order->fresh()->version, 'reason' => 'Must not ship unpaid', 'fulfillment_info' => 'Synthetic handover'], $order);
            $this->fail('Unpaid handover accepted');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $document = FinancialDocument::findOrFail(FinancialEntry::findOrFail($entry)->financial_document_id);
        $delivery = app(DeliveryManagement::class);
        $plan = $delivery->request($this->account, $document->public_id, 'pickup', null, 0);
        $plan = $delivery->confirm($this->owner, 'password', $plan->public_id, $plan->version, (string) Str::uuid());
        app(PaymentReview::class)->inStore($this->owner, 'password', $document->public_id, 2000000, (string) Str::uuid());
        $delivery->dispatch($this->owner, 'password', $plan->public_id, $plan->version, 'SYNTHETIC-HANDOVER', 'SYNTHETIC CUSTOMER');
        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame('collected', $plan->fresh()->state);
        $this->assertSame(0, $item->fresh()->on_hand);
        $this->assertSame(0, $item->fresh()->reserved);
        $this->expectException(HttpException::class);
        $delivery->request($this->account, $document->public_id, 'pickup', null, $plan->fresh()->version);
    }

    public function test_notifications_are_durable_but_disabled_without_configuration(): void
    {
        Http::fake();
        $service = app(CustomerNotifications::class);
        $service->record($this->account->id, 'payment_confirmed', 'synthetic-operation');
        $service->record($this->account->id, 'payment_confirmed', 'synthetic-operation');
        $this->assertDatabaseCount('customer_notifications', 1);
        $this->assertSame(0, $service->sendPending());
        Http::assertNothingSent();
    }

    public function test_missing_notification_templates_do_not_starve_configured_events(): void
    {
        config(['customer.notifications_enabled' => true, 'customer.sms_api_key' => 'synthetic-key', 'customer.notification_templates.payment_confirmed' => 12, 'customer.notification_parameter' => 'URL', 'customer.approved_template_ids' => [12]]);
        Http::fake(['api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageId' => 123]])]);
        $service = app(CustomerNotifications::class);
        for ($i = 0; $i < 21; $i++) {
            $service->record($this->account->id, 'repair_registered', 'unconfigured-'.$i);
        }
        $service->record($this->account->id, 'payment_confirmed', 'configured-one');
        $this->assertSame(1, $service->sendPending());
        $this->assertSame(21, DB::table('customer_notifications')->where('state', 'pending')->count());
        $this->assertSame(0, $service->sendPending());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['templateId'] === 12 && $request['parameters'][0]['name'] === 'URL');
    }

    public function test_interrupted_notification_is_reviewed_without_resending(): void
    {
        config(['customer.notifications_enabled' => true, 'customer.sms_api_key' => 'synthetic-key', 'customer.notification_templates.payment_confirmed' => 12, 'customer.notification_parameter' => 'URL', 'customer.approved_template_ids' => [12]]);
        Http::fake();
        app(CustomerNotifications::class)->record($this->account->id, 'payment_confirmed', 'interrupted');
        DB::table('customer_notifications')->update(['state' => 'sending', 'claimed_at' => now()->subMinutes(11)]);
        $this->assertSame(0, app(CustomerNotifications::class)->sendPending());
        $this->assertSame('review', DB::table('customer_notifications')->value('state'));
        Http::assertNothingSent();
    }

    public function test_postal_payment_is_disabled_even_if_a_plan_was_previously_marked_post(): void
    {
        $this->gateway();
        DeliveryPlan::query()->update(['method' => 'post']);
        try {
            app(ZibalPayments::class)->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
            $this->fail('Unconfirmed postal billing accepted');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            Http::assertNothingSent();
            $this->assertDatabaseCount('payment_attempts', 0);
        }
    }

    public function test_receipt_upload_is_private_unsettled_and_cannot_be_reused(): void
    {
        Storage::fake('local');
        config(['payments.bank.enabled' => true, 'payments.bank.card_number' => '0000000000000000']);
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1]);
        $file = UploadedFile::fake()->image('receipt.png', 80, 60);
        $this->post('/account/documents/'.$this->document->public_id.'/receipts', ['amount' => '10000', 'receipt' => $file])->assertRedirect();
        $receipt = PaymentReceipt::firstOrFail();
        $this->assertSame('pending', $receipt->state);
        $this->assertSame(0, $this->document->fresh()->paid_minor);
        $this->get('/payment-receipts/'.$receipt->public_id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/account/documents/'.$this->document->public_id.'/receipts', ['amount' => '10000', 'receipt' => $file])->assertConflict();
        Auth::guard('customer')->logout();
        $this->get('/payment-receipts/'.$receipt->public_id)->assertNotFound();
        $this->assertDatabaseCount('payment_receipts', 1);
    }
}
