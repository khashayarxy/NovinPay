<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ProductFeedbackController;
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
use App\Models\ProductFeedback;
use App\Models\RepairCase;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\CommerceManagement;
use App\Services\CustomerNotifications;
use App\Services\DeliveryManagement;
use App\Services\FinancialOperations;
use App\Services\PaymentReview;
use App\Services\ProductPurchaseVerification;
use App\Services\ShoppingCart;
use App\Services\StoreCheckout;
use App\Services\ZibalPayments;
use App\Support\RepairIdentity;
use App\Support\SiteMaintenanceState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
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
        $this->withCredentials();
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
        config(['app.url' => 'https://novinpaypos.test', 'payments.zibal.enabled' => true, 'payments.zibal.live_requests' => true, 'payments.zibal.merchant' => 'fixture-merchant', 'payments.zibal.callback_url' => 'https://novinpaypos.test/payments/zibal/callback']);
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
        foreach (['in_person' => 'pickup', 'shipping' => 'courier'] as $method => $carrier) {
            $case->refresh()->forceFill(['delivery_method' => $method, 'delivery_carrier' => $carrier])->save();
            $plan->refresh()->forceFill(['method' => $carrier])->save();
            $this->post($url, ['version' => $case->version, 'intent' => 'handover', 'handover_confirmed' => '1',
                'shipment_tracking' => 'SYNTHETIC', 'confirmed_delivery_method' => $carrier])->assertForbidden();
            $this->assertSame('ready', $case->fresh()->status->value);
            $this->assertSame('planned', $plan->fresh()->state);
        }
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
            'payments.zibal.callback_url' => 'https://novinpaypos.test/payments/zibal/callback']);
    }

    public function test_connectivity_warning_and_rechecks_reuse_one_owned_payment_attempt(): void
    {
        // Browser fixture IPs are only compared with the verified local snapshot.
        $data = json_decode(file_get_contents(config('payments.connectivity.ranges_path')), true, flags: JSON_THROW_ON_ERROR);
        $this->travelTo(Carbon::parse($data['month'].'-15'));
        $this->gateway();
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1]);
        $order = SalesOrder::findOrFail($this->document->sales_order_id);
        $this->get(route('payments.order', $order->public_id))->assertOk()->assertSee('پرداخت آنلاین زیبال');
        $input = ['amount' => '10000', 'operation_key' => (string) Str::uuid()];
        $start = route('payments.start', $this->document->public_id);
        $response = $this->post($start, $input)->assertStatus(303);
        $attempt = PaymentAttempt::query()->sole();
        $connect = route('payments.connect', $attempt->public_id);
        $toGateway = route('payments.gateway', $attempt->public_id);
        $result = route('payments.result', $attempt->public_id);
        $response->assertRedirect($connect);
        $this->post($start, $input)->assertRedirect($connect);
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->get($connect.'?pay_url=https://elsewhere.test')
            ->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('بررسی دوباره اتصال')->assertSee('href="'.route('payments.gateway', $attempt->public_id, false).'"', false)
            ->assertDontSee('ادامه به درگاه')->assertDontSee('بازگشت به سند پرداخت')
            ->assertDontSee('8.8.8.8')->assertDontSee('elsewhere.test')->assertDontSee('gateway.zibal.ir/start/501');
        $this->getJson($connect)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['ir' => false]);
        $this->getJson($connect)->assertOk()->assertExactJson(['ir' => false]);
        $this->get($toGateway.'?redirect=https://elsewhere.test')->assertOk()->assertHeader('Referrer-Policy', 'origin')
            ->assertDontSee('http-equiv="refresh"', false)->assertSee('وضعیت وی‌پی‌ان قابل تأیید نیست')->assertSee('css/payment-flow.css?v=', false)->assertSee('href="https://gateway.zibal.ir/start/501"', false)->assertSee('referrerpolicy="origin"', false)->assertDontSee('elsewhere.test');
        $this->get($toGateway.'?continue=1')->assertOk()->assertSee('0;url=https://gateway.zibal.ir/start/501');
        $this->withServerVariables(['REMOTE_ADDR' => '5.160.0.1'])->get($connect)->assertOk()
            ->assertHeader('Referrer-Policy', 'origin')->assertSee('0;url=https://gateway.zibal.ir/start/501');
        $this->getJson($connect)->assertOk()->assertExactJson(['ir' => true]);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get($connect)->assertOk()
            ->assertHeader('Referrer-Policy', 'origin')->assertSee('وضعیت وی‌پی‌ان قابل تأیید نیست');
        $this->getJson($connect)->assertOk()->assertExactJson(['ir' => null]);
        $this->get('/pay/index.html?pay_url=https://elsewhere.test')->assertNotFound();
        $this->get('/pay/index.html?'.http_build_query(['pay_url' => 'https://gateway.zibal.ir/start/501']))->assertRedirect($connect);
        $this->get($result)->assertOk()->assertSee('پرداخت سفارش')->assertSee(route('payments.order', $order->public_id));
        $this->getJson(route('payments.status', $attempt->public_id))->assertExactJson(['status' => 'processing']);
        $this->get(route('payments.connect', (string) Str::uuid()))->assertNotFound();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['callbackUrl'] === 'https://novinpaypos.test/payments/zibal/callback');
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_attempt_pages_require_the_same_current_customer_and_stop_after_settlement(): void
    {
        $this->gateway();
        $attempt = app(ZibalPayments::class)->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $result = route('payments.result', $attempt->public_id);
        $this->get($result)->assertRedirect(route('account.login'));
        $this->assertSame(route('payments.result', $attempt->public_id, false), session('customer_intended'));
        $other = $this->account->replicate();
        $other->forceFill(['public_id' => (string) Str::uuid(), 'customer_id' => null, 'phone' => '09000000002',
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09000000002')])->save();
        $this->actingAs($other, 'customer')->withSession(['customer_session_version' => 1]);
        foreach (['payments.connect', 'payments.gateway', 'payments.result', 'payments.status'] as $route) {
            $this->get(route($route, $attempt->public_id))->assertNotFound();
        }
        // The old merchant callback setting must not interrupt reconciliation of an existing attempt.
        config(['payments.zibal.callback_url' => 'https://novinpaypos.test/bot/payment_verify.php']);
        $this->get('/bot/payment_verify.php?trackId=501')->assertRedirect($result);
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1]);
        $this->get(route('payments.gateway', $attempt->public_id))->assertRedirect($result);
        $this->getJson(route('payments.connect', $attempt->public_id))->assertConflict()->assertExactJson([]);
        $this->get($result)->assertOk()->assertSee('پرداخت تأیید شد');
        $this->getJson(route('payments.status', $attempt->public_id))->assertExactJson(['status' => 'success']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_repair_payment_uses_the_same_callback_and_returns_to_the_repair_document(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->withSession(['repair_intake_token' => $token])->post('/admin/repairs/intake', [
            'simple_intake' => '1', 'submission_token' => $token, 'customer_name' => 'Synthetic Customer',
            'contact_phone' => '09000000000', 'device_model' => 'SYNTHETIC', 'issue' => 'Synthetic issue', 'physical_condition' => 'Synthetic condition',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $case = RepairCase::query()->sole();
        $case->forceFill(['customer_account_id' => $this->account->id, 'customer_id' => $this->account->customer_id])->save();
        $payment = route('payments.repair', $case->public_id);
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1])->get($payment)->assertNotFound();
        $this->actingAs($this->owner, 'web');
        $this->post(route('admin.repair-cases.record', $case), ['version' => 1, 'status' => 'ready', 'repair_cost' => '1000'])->assertSessionHasNoErrors();
        $entry = app(FinancialOperations::class)->execute($this->owner, 'issue', [
            'operation_key' => (string) Str::uuid(), 'password' => 'password', 'reason' => 'Synthetic repair charge',
            'source_type' => 'repair', 'source' => $case->public_id, 'source_version' => 2,
        ]);
        $document = FinancialDocument::findOrFail(FinancialEntry::findOrFail($entry)->financial_document_id);
        (new DeliveryPlan)->forceFill(['public_id' => (string) Str::uuid(), 'financial_document_id' => $document->id,
            'customer_account_id' => $this->account->id, 'method' => 'courier'])->save();
        $this->gateway(['amount' => 10000]);
        $this->actingAs($this->account, 'customer')->get($payment)->assertOk()->assertSee('پرداخت آنلاین زیبال');
        $this->post(route('payments.start', $document->public_id), ['amount' => '1000', 'operation_key' => (string) Str::uuid()])->assertStatus(303);
        $attempt = PaymentAttempt::query()->sole();
        $result = route('payments.result', $attempt->public_id);
        $this->get(route('payments.callback', ['trackId' => 501, 'orderId' => $attempt->public_id]))->assertRedirect($result);
        $this->get($result)->assertOk()->assertSee('پرداخت تعمیر')->assertSee($payment);
        $this->assertSame($document->charged_minor, $document->fresh()->paid_minor);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_provider_test_merchant_cannot_create_a_live_attempt(): void
    {
        $this->enableGateway();
        config(['payments.zibal.merchant' => 'zibal']);
        Http::fake();
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1]);
        $this->post(route('payments.start', $this->document->public_id), ['amount' => '10000', 'operation_key' => (string) Str::uuid()])->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_pausing_new_payments_still_allows_scheduled_reconciliation_of_existing_attempts(): void
    {
        $this->gateway();
        $service = app(ZibalPayments::class);
        $attempt = $service->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $this->travel(3)->minutes();
        config(['payments.zibal.enabled' => false]);
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1]);
        $this->post(route('payments.start', $this->document->public_id), ['amount' => '10000', 'operation_key' => (string) Str::uuid()])->assertStatus(503);
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame('settled', $attempt->fresh()->state);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://gateway.zibal.ir/v1/request'
            && $request['amount'] === 100000 && $request['orderId'] === $attempt->public_id
            && $request['merchant'] === 'fixture-merchant' && ! $request->hasHeader('Authorization'));
    }

    public function test_gateway_verification_cannot_be_overridden_by_inquiry_with_a_different_binding(): void
    {
        $this->enableGateway();
        Http::fake([
            '*/request' => Http::response(['result' => 100, 'trackId' => '505']),
            '*/verify' => Http::response(['result' => 100, 'amount' => 100001]),
            '*/inquiry' => fn () => Http::response(['result' => 100, 'status' => 1, 'amount' => 100000, 'orderId' => PaymentAttempt::firstOrFail()->public_id]),
        ]);
        $service = app(ZibalPayments::class);
        $attempt = $service->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $this->assertSame('review', $service->verify($attempt)->state);
        $this->assertSame(0, $this->document->fresh()->paid_minor);
        $this->assertDatabaseCount('payment_transactions', 0);
        Http::assertSentCount(2);
    }

    public function test_inquiry_must_independently_confirm_bound_verified_payment(): void
    {
        $this->enableGateway();
        $inquiry = ['status' => 2, 'trackId' => '506'];
        Http::fake([
            '*/request' => Http::response(['result' => 100, 'trackId' => '506']),
            '*/verify' => Http::response(['result' => 201]),
            '*/inquiry' => function () use (&$inquiry) {
                return Http::response($inquiry + ['result' => 100, 'amount' => 100000, 'orderId' => PaymentAttempt::firstOrFail()->public_id]);
            },
        ]);
        $service = app(ZibalPayments::class);
        $attempt = $service->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $this->assertSame('uncertain', $service->verify($attempt)->state);
        $inquiry = ['status' => 1, 'trackId' => '507'];
        $this->assertSame('review', $service->verify($attempt)->state);
        $this->assertSame(0, $this->document->fresh()->paid_minor);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_official_gateway_amount_track_number_and_known_error_contract(): void
    {
        $this->enableGateway();
        Http::fakeSequence('*/request')
            ->push(['result' => 115, 'message' => 'Synthetic allowlist rejection'])
            ->push(['result' => 100, 'trackId' => 15966442233311]);
        Http::fake(['*/verify' => fn () => Http::response(['result' => 100, 'status' => 2,
            'orderId' => PaymentAttempt::query()->where('track_id', '15966442233311')->sole()->public_id, 'amount' => 1000])]);
        $service = app(ZibalPayments::class);
        $rejected = $service->start($this->account, $this->document->public_id, 10000, (string) Str::uuid());
        $this->assertSame('failed', $rejected->state);
        $this->assertDatabaseHas('payment_events', ['payment_attempt_id' => $rejected->id, 'event' => 'request_rejected_115']);
        $attempt = $service->start($this->account, $this->document->public_id, 10000, (string) Str::uuid());
        $this->assertSame('15966442233311', $attempt->track_id);
        $this->assertSame('review', $service->verify($attempt)->state);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/request')
            && $request['amount'] === 1000 && $request['callbackUrl'] === 'https://novinpaypos.test/payments/zibal/callback');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/verify') && $request['trackId'] === 15966442233311);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_provider_waiting_remains_resumable_and_already_verified_settles_once(): void
    {
        $this->enableGateway();
        $verifyCode = 202;
        $status = -1;
        Http::fake([
            '*/request' => Http::response(['result' => 100, 'trackId' => 509]),
            '*/verify' => function () use (&$verifyCode) {
                return Http::response(['result' => $verifyCode]);
            },
            '*/inquiry' => function () use (&$status) {
                return Http::response(['result' => 100, 'status' => $status,
                    'orderId' => PaymentAttempt::query()->sole()->public_id, 'amount' => 100000]);
            },
        ]);
        $service = app(ZibalPayments::class);
        $attempt = $service->start($this->account, $this->document->public_id, 1000000, (string) Str::uuid());
        $this->travel(3)->minutes();
        $this->assertSame(1, $service->reconcile());
        $this->assertSame('pending', $attempt->fresh()->state);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1])
            ->get(route('payments.gateway', $attempt->public_id))->assertOk()->assertHeader('Referrer-Policy', 'origin');
        $verifyCode = 201;
        $status = 1;
        $this->assertSame('settled', $service->verify($attempt)->state);
        $this->assertSame('settled', $service->verify($attempt)->state);
        Http::assertSentCount(5);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/inquiry') && $request['trackId'] === 509);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_online_payment_requires_balance_and_respects_an_explicit_source_account(): void
    {
        $this->gateway();
        $route = route('payments.start', $this->document->public_id);
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1]);
        $operation = (string) Str::uuid();
        $this->post($route, ['amount' => '20000.10', 'operation_key' => $operation])->assertConflict();

        $other = $this->account->replicate();
        $other->public_id = (string) Str::uuid();
        $other->phone = '09000000002';
        $other->phone_digest = RepairIdentity::digest('customer-account-phone', $other->phone);
        $other->customer_id = null;
        $other->save();

        $order = SalesOrder::findOrFail($this->document->sales_order_id);
        $order->customer_account_id = $other->id;
        $order->save();

        $this->get(route('payments.document', $this->document->public_id))->assertNotFound();
        $this->post($route, ['amount' => '10000', 'operation_key' => $operation])->assertNotFound();
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_attempts', 0);
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
        config(['app.url' => 'https://novinpaypos.test', 'payments.zibal.enabled' => true, 'payments.zibal.live_requests' => true, 'payments.zibal.merchant' => 'fixture-merchant', 'payments.zibal.callback_url' => 'https://novinpaypos.test/payments/zibal/callback']);
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
        $resultUrl = route('payments.result', $attempt->public_id);
        $this->get(route('payments.callback', ['trackId' => 501, 'orderId' => (string) Str::uuid(), 'success' => 1]))->assertNotFound();
        $this->get(route('payments.callback', ['trackId' => 501, 'orderId' => $attempt->public_id, 'success' => 1]))->assertRedirect($resultUrl);
        $this->get('/bot/payment_verify.php?trackId=501&orderId='.$attempt->public_id.'&success=0')->assertRedirect($resultUrl);
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
        config(['app.url' => 'https://novinpaypos.test', 'payments.zibal.enabled' => true, 'payments.zibal.live_requests' => true, 'payments.zibal.merchant' => 'fixture-merchant', 'payments.zibal.callback_url' => 'https://novinpaypos.test/payments/zibal/callback']);
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
        $this->post('/account/documents/'.$this->document->public_id.'/zibal', ['amount' => '10000', 'operation_key' => $operation])->assertRedirectToRoute('payments.result', $attempt->public_id);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_missing_verified_amount_requires_independent_inquiry_binding(): void
    {
        config(['app.url' => 'https://novinpaypos.test', 'payments.zibal.enabled' => true, 'payments.zibal.live_requests' => true, 'payments.zibal.merchant' => 'fixture-merchant', 'payments.zibal.callback_url' => 'https://novinpaypos.test/payments/zibal/callback']);
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
        $this->assertTrue(SiteMaintenanceState::paymentCallback('/payments/zibal/callback?trackId=1', 'GET'));
        foreach (['//host/bot/payment_verify.php', '/bot/payment_verify.php/extra', 'https://host/bot/payment_verify.php', '/bot/%70ayment_verify.php', '/payments/zibal/callback/', '/payments/zibal/%63allback'] as $target) {
            $this->assertFalse(SiteMaintenanceState::paymentCallback($target, 'GET'));
        }
        $this->assertFalse(SiteMaintenanceState::paymentCallback('/bot/payment_verify.php', 'HEAD'));
        $this->assertFalse(SiteMaintenanceState::paymentCallback('/payments/zibal/callback', 'HEAD'));
        $this->assertDatabaseCount('payment_events', 0);
        $this->get('/bot/check_payment_status.php?order_id='.Str::uuid().'&track_id=1')->assertNotFound();
        $this->assertDatabaseCount('payment_events', 0);
    }

    public function test_explicit_order_account_ownership_is_not_overridden_by_a_different_crm_link(): void
    {
        $original = $this->account;
        $otherCustomer = Customer::findOrFail($original->customer_id)->replicate();
        $otherCustomer->public_id = (string) Str::uuid();
        $otherCustomer->phone_digest = bin2hex(random_bytes(32));
        $otherCustomer->identity_digest = bin2hex(random_bytes(32));
        $otherCustomer->save();
        $other = $original->replicate();
        $other->public_id = (string) Str::uuid();
        $other->phone = '09000000002';
        $other->phone_digest = RepairIdentity::digest('customer-account-phone', $other->phone);
        $other->customer_id = $otherCustomer->id;
        $other->save();
        $order = SalesOrder::findOrFail($this->document->sales_order_id);
        $order->customer_id = $otherCustomer->id;
        $order->save();
        $path = '/account/orders/'.$order->public_id;

        $this->actingAs($other, 'customer')->withSession(['customer_session_version' => 1]);
        $this->get($path)->assertNotFound();
        $this->get('/account?section=orders')->assertDontSee($order->public_id);

        $this->actingAs($original, 'customer')->withSession(['customer_session_version' => 1]);
        $this->get($path)->assertOk();

        // Staff-origin history without an explicit account still uses the reviewed CRM link.
        $order->customer_account_id = null;
        $order->save();
        $this->actingAs($other, 'customer')->withSession(['customer_session_version' => 1]);
        $this->get($path)->assertOk();
        Http::assertNothingSent();
    }

    public function test_store_checkout_uses_server_price_and_existing_reservation_workflow(): void
    {
        $this->post('/admin/products', ['name' => 'Synthetic terminal', 'slug' => 'synthetic-terminal',
            'category' => 'pos_terminal', 'is_published' => '1', 'condition' => 'new',
            'stock_status' => 'in_stock', 'price' => '10000'])->assertRedirect('/admin/products');
        $product = Product::query()->where('slug', 'synthetic-terminal')->sole();
        $commerce = app(CommerceManagement::class);
        $id = $commerce->execute($this->owner, 'item.create', ['operation_key' => (string) Str::uuid(), 'sku' => 'SYNTHETIC-STORE', 'name' => 'Synthetic stock', 'condition' => 'new', 'serialized' => false, 'product_id' => $product->id, 'low_stock_threshold' => 0, 'reason' => 'Synthetic store setup']);
        $item = InventoryItem::findOrFail($id);
        $commerce->execute($this->owner, 'item.receive', ['operation_key' => (string) Str::uuid(), 'version' => $item->version, 'quantity' => 2, 'reason' => 'Synthetic opening stock'], $item);
        $checkout = app(StoreCheckout::class);
        // Retain legacy catalogue data, but never advertise an unrepresentable offer.
        $product->price = 10000000000;
        $product->save();
        $this->assertNull($checkout->offer($product));
        $product->price = 10000;
        $product->save();
        $key = (string) Str::uuid();
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1]);
        $product->update(['online_purchasing_enabled' => false]);
        $this->get(route('products.show', $product))->assertOk()->assertSee('هماهنگی خرید')
            ->assertDontSee('افزودن به سبد خرید')->assertDontSee('موجودی تأیید نشده');
        $this->post(route('cart.add', $product), ['quantity' => 1])->assertConflict();
        $product->update(['online_purchasing_enabled' => true]);
        $product->update(['product_details' => ['sale_paused' => true]]);
        $this->get(route('products.show', $product))->assertOk()->assertSee('فروش موقتاً متوقف است')
            ->assertDontSee('افزودن به سبد خرید')->assertDontSee('هماهنگی خرید');
        $this->post(route('cart.add', $product), ['quantity' => 1])->assertConflict();
        $product->update(['product_details' => ['sale_paused' => false], 'price' => null]);
        $this->get(route('products.show', $product))->assertOk()->assertSee('استعلام قیمت')
            ->assertDontSee('موجودی تأیید نشده')->assertDontSee('افزودن به سبد خرید');
        $product->update(['price' => 10000]);
        $this->get('/products/'.$product->slug)->assertOk()->assertSee($product->name);
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('d', 64));
        $this->post(route('cart.add', $product), $this->cartFields() + ['quantity' => 2])->assertRedirect(route('cart.index'));
        $this->get('/cart')->assertOk()->assertSee($product->name);
        $payload = $this->cartFields() + ['operation_key' => $key, 'method' => 'pickup'];
        $this->get(route('cart.review'))->assertOk()->assertSee('روش تحویل')->assertSee('پرداخت آنلاین فعلاً در دسترس نیست');
        $product->update(['price' => 10001]);
        $this->postJson('/cart/checkout', $payload)->assertConflict();
        $product->update(['price' => 10000]);
        $this->postJson('/cart/checkout', array_replace($payload, ['method' => 'tipax', 'address' => (string) Str::uuid()]))->assertNotFound();
        $this->assertDatabaseCount('cart_purchase_snapshots', 0);
        $this->post('/cart/checkout', $payload)->assertRedirect(route('account.index', ['section' => 'orders']));
        $order = SalesOrder::query()->where('checkout_key', $key)->sole();
        $this->assertFalse(app(ProductPurchaseVerification::class)->verified($this->account, $product));
        $this->assertSame([$product->slug => 2], $this->get('/cart')->viewData('quantity'));
        $count = SalesOrder::count();
        $this->post('/cart/checkout', $payload)->assertRedirect(route('account.index', ['section' => 'orders']));
        $this->assertSame($count, SalesOrder::count());
        $this->post('/cart/checkout', array_replace($payload, ['method' => 'tipax', 'address' => (string) Str::uuid()]))->assertConflict();
        $this->assertSame($count, SalesOrder::count());
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
        $plan = DeliveryPlan::query()->where('financial_document_id', $document->id)->sole();
        $this->assertSame('pickup', $plan->requested_method);
        $plan = $delivery->confirm($this->owner, 'password', $plan->public_id, $plan->version, (string) Str::uuid());
        $this->assertSame([$product->slug => 2], $this->get('/cart')->viewData('quantity'));
        app(PaymentReview::class)->inStore($this->owner, 'password', $document->public_id, 1000000, (string) Str::uuid());
        $this->assertSame([$product->slug => 2], $this->get('/cart')->viewData('quantity'));
        $operation = (string) Str::uuid();
        app(PaymentReview::class)->inStore($this->owner, 'password', $document->public_id, 1000000, $operation);
        $this->assertSame([], $this->get('/cart')->viewData('quantity'));
        $this->assertNotNull(DB::table('cart_purchase_snapshots')->where('sales_order_id', $order->id)->sole()->consumed_at);
        $delivery->dispatch($this->owner, 'password', $plan->public_id, $plan->version, 'SYNTHETIC-HANDOVER', 'SYNTHETIC CUSTOMER');
        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame('collected', $plan->fresh()->state);
        $verification = app(ProductPurchaseVerification::class);
        $this->assertTrue($verification->verified($this->account, $product));
        $this->assertFalse($verification->verified($this->account, Product::query()->whereKeyNot($product->id)->first() ?? new Product));
        $this->post(route('products.feedback.store', $product), [
            'kind' => 'review', 'body' => 'Synthetic response to the fulfilled purchase.', 'rating' => 4,
        ])->assertRedirect(route('products.show', $product));
        $this->assertDatabaseHas('product_feedback', ['product_id' => $product->id,
            'verified_buyer' => true, 'rating' => 4, 'status' => 'pending']);
        $feedback = ProductFeedback::where('product_id', $product->id)->sole();
        $this->get(route('products.show', $product))->assertDontSee('aggregateRating');
        $this->actingAs($this->owner, 'web')->patch(route('admin.products.feedback.update', [$product, $feedback]), [
            'status' => 'approved', 'revision' => ProductFeedbackController::revision($feedback),
        ])->assertRedirect();
        $this->actingAs($this->account, 'customer');
        $this->get(route('products.show', $product))->assertOk()
            ->assertSee('خریدار تأییدشده')->assertSee('aggregateRating');
        $this->assertSame(0, $item->fresh()->on_hand);
        $this->assertSame(0, $item->fresh()->reserved);
        $this->get(route('products.show', $product))->assertOk()->assertSee('ناموجود')
            ->assertSee('https://schema.org/OutOfStock', false)->assertDontSee('افزودن به سبد خرید');
        $this->get('/account/orders/'.$order->public_id)->assertOk()->assertSee('تحویل ثبت شد');
        $this->assertSame($document->fresh()->charged_minor, $document->fresh()->paid_minor);
        Http::assertNothingSent();
        $this->expectException(HttpException::class);
        $delivery->request($this->account, $document->public_id, 'pickup', null, $plan->fresh()->version);
    }

    public function test_verified_settlement_does_not_delete_changed_or_unrelated_cart_rows(): void
    {
        $order = SalesOrder::findOrFail($this->document->sales_order_id);
        $inventoryId = app(CommerceManagement::class)->execute($this->owner, 'item.create', [
            'operation_key' => (string) Str::uuid(), 'sku' => 'SWITCHED-CART-ITEM', 'name' => 'Synthetic settlement item',
            'condition' => 'used', 'serialized' => false, 'low_stock_threshold' => 0, 'reason' => 'Synthetic cart snapshot',
        ]);
        $line = new SalesOrderLine;
        $line->forceFill(['sales_order_id' => $order->id, 'inventory_item_id' => $inventoryId,
            'sku' => 'SWITCHED-CART-ITEM', 'description' => 'Synthetic settlement item', 'condition' => 'used',
            'quantity' => 2, 'revision' => 1, 'unit_price' => '10000.00', 'line_total' => '20000.00'])->save();
        $scope = hash('sha256', 'device:synthetic checkout snapshot');
        $state = [
            'changed-item' => ['quantity' => 3, 'inventory' => $inventoryId, 'version' => (string) Str::uuid()],
            'unrelated-item' => ['quantity' => 1, 'inventory' => $inventoryId, 'version' => (string) Str::uuid()],
        ];
        DB::table('shopping_carts')->insert(['scope' => $scope, 'state' => json_encode($state, JSON_THROW_ON_ERROR)]);
        DB::table('cart_purchase_snapshots')->insert(['sales_order_id' => $order->id, 'sources' => json_encode([
            'rows' => [$scope => ['changed-item' => ['line' => $line->id, 'inventory' => $inventoryId, 'ordered' => 2,
                'quantity' => 2, 'version' => (string) Str::uuid()]]],
            'preferences' => [], 'view' => null,
        ], JSON_THROW_ON_ERROR)]);
        $payment = app(PaymentReview::class);
        $operation = (string) Str::uuid();
        $receipt = $payment->inStore($this->owner, 'password', $this->document->public_id, 2000000, $operation);
        $replay = $payment->inStore($this->owner, 'password', $this->document->public_id, 2000000, $operation);

        $this->assertSame($receipt->id, $replay->id);
        $this->assertNotNull(DB::table('cart_purchase_snapshots')->sole()->consumed_at);
        $stored = json_decode(DB::table('shopping_carts')->where('scope', $scope)->value('state'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(Arr::sortRecursive($state), Arr::sortRecursive($stored));
    }

    public function test_verified_gateway_callback_consumes_only_purchased_quantity_after_failure_and_replay(): void
    {
        $order = SalesOrder::findOrFail($this->document->sales_order_id);
        $inventoryId = app(CommerceManagement::class)->execute($this->owner, 'item.create', [
            'operation_key' => (string) Str::uuid(), 'sku' => 'ZIBAL-CART-ITEM', 'name' => 'Synthetic checkout item',
            'condition' => 'used', 'serialized' => false, 'low_stock_threshold' => 0, 'reason' => 'Synthetic payment fixture',
        ]);
        $line = new SalesOrderLine;
        $line->forceFill(['sales_order_id' => $order->id, 'inventory_item_id' => $inventoryId,
            'sku' => 'ZIBAL-CART-ITEM', 'description' => 'Synthetic checkout item', 'condition' => 'used',
            'quantity' => 2, 'revision' => 1, 'unit_price' => '10000.00', 'line_total' => '20000.00'])->save();

        $scope = hash('sha256', 'device:gateway checkout fixture');
        $view = hash('sha256', 'view:gateway checkout fixture');
        $version = (string) Str::uuid();
        $state = [
            'selected' => ['quantity' => 3, 'inventory' => $inventoryId, 'version' => $version],
            'unrelated' => ['quantity' => 1, 'inventory' => $inventoryId, 'version' => (string) Str::uuid()],
        ];
        DB::table('shopping_carts')->insert([
            ['scope' => $scope, 'state' => json_encode($state, JSON_THROW_ON_ERROR)],
            ['scope' => $view, 'state' => json_encode(['selected' => 2], JSON_THROW_ON_ERROR)],
        ]);
        DB::table('cart_purchase_snapshots')->insert(['sales_order_id' => $order->id, 'sources' => json_encode([
            'rows' => [$scope => ['selected' => ['line' => $line->id, 'inventory' => $inventoryId,
                'ordered' => 2, 'quantity' => 2, 'version' => $version]]],
            'preferences' => ['selected' => 2], 'view' => $view,
        ], JSON_THROW_ON_ERROR)]);
        $currentCart = fn () => json_decode(DB::table('shopping_carts')->where('scope', $scope)->value('state'), true, flags: JSON_THROW_ON_ERROR);

        $this->enableGateway();
        $paid = false;
        Http::fake([
            '*/request' => Http::response(['result' => 100, 'trackId' => '508']),
            '*/verify' => function () use (&$paid) {
                return Http::response($paid
                    ? ['result' => 100, 'orderId' => PaymentAttempt::firstOrFail()->public_id, 'amount' => 200000]
                    : ['result' => 202]);
            },
            '*/inquiry' => fn () => Http::response(['result' => 100, 'status' => 3,
                'orderId' => PaymentAttempt::firstOrFail()->public_id, 'amount' => 200000]),
        ]);
        $attempt = app(ZibalPayments::class)->start($this->account, $this->document->public_id, 2000000, (string) Str::uuid());
        $callback = route('payments.callback', ['trackId' => '508', 'orderId' => $attempt->public_id]);
        $this->get($callback)->assertRedirectToRoute('payments.result', $attempt->public_id);
        $this->assertSame(Arr::sortRecursive($state), Arr::sortRecursive($currentCart()));
        $this->assertNull(DB::table('cart_purchase_snapshots')->sole()->consumed_at);
        $this->assertDatabaseCount('payment_transactions', 0);

        $paid = true;
        $this->get($callback)->assertRedirectToRoute('payments.result', $attempt->public_id);
        $remaining = $currentCart();
        $this->assertSame(1, $remaining['selected']['quantity']);
        $this->assertSame(Arr::sortRecursive($state['unrelated']), Arr::sortRecursive($remaining['unrelated']));
        $this->assertNotNull(DB::table('cart_purchase_snapshots')->sole()->consumed_at);
        $this->get($callback)->assertRedirectToRoute('payments.result', $attempt->public_id);
        $this->assertSame($remaining, $currentCart());
        $this->assertDatabaseCount('payment_transactions', 1);
        Http::assertSentCount(4);
    }

    public function test_empty_checkout_replay_still_requires_the_exact_active_account_and_session(): void
    {
        $order = SalesOrder::findOrFail($this->document->sales_order_id);
        $order->checkout_key = (string) Str::uuid();
        $order->checkout_digest = str_repeat('a', 64);
        $order->save();
        $customer = Customer::findOrFail($this->account->customer_id)->replicate();
        $customer->public_id = (string) Str::uuid();
        $customer->phone_digest = bin2hex(random_bytes(32));
        $customer->identity_digest = bin2hex(random_bytes(32));
        $customer->save();
        $other = $this->account->replicate();
        $other->public_id = (string) Str::uuid();
        $other->phone = '09000000003';
        $other->phone_digest = RepairIdentity::digest('customer-account-phone', $other->phone);
        $other->customer_id = $customer->id;
        $other->save();
        $this->actingAs($other, 'customer')->withSession(['customer_session_version' => 1]);
        $this->post('/cart/checkout', $this->cartFields() + ['operation_key' => $order->checkout_key, 'method' => 'pickup'])->assertConflict();
        $this->assertDatabaseCount('sales_orders', 1);
        $stale = $this->account;
        CustomerAccount::query()->whereKey($stale->id)->update(['session_version' => 2]);
        try {
            app(StoreCheckout::class)->create($stale, [], $order->checkout_key);
            $this->fail('Stale account session replay accepted');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_withdrawn_products_can_be_removed_without_exposing_unpublished_data(): void
    {
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1]);
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('e', 64));
        $product = Product::create(['name' => 'PRIVATE WITHDRAWN NAME', 'slug' => 'withdrawn',
            'category' => 'pos_terminal', 'condition' => 'new', 'stock_status' => 'in_stock',
            'price' => 10000, 'is_published' => false, 'is_sample' => false]);
        DB::table('shopping_carts')->insert(['scope' => hash('sha256', 'device:'.str_repeat('e', 64)), 'state' => json_encode([
            $product->slug => ['quantity' => 1, 'inventory' => 10, 'version' => (string) Str::uuid()],
        ], JSON_THROW_ON_ERROR)]);
        $this->get('/cart')->assertOk()->assertDontSee($product->name)->assertSee('کالای خارج از دسترس');
        $this->get(route('cart.review'))->assertRedirect(route('cart.index'));
        $this->post(route('cart.remove', $product->slug), $this->cartFields() + ['scope' => 'device'])->assertRedirect();
        $this->assertSame([], $this->get('/cart')->viewData('quantity'));
        Http::assertNothingSent();
    }

    private function cartFields(): array
    {
        $cart = $this->get('/cart')->assertOk();

        return ['cart_context' => $cart->viewData('proof'), 'cart_revision' => $cart->viewData('revision'), 'quote' => $cart->viewData('quote')];
    }

    public function test_in_account_notifications_are_durable_and_never_send_sms(): void
    {
        Http::fake();
        $service = app(CustomerNotifications::class);
        $service->record($this->account->id, 'payment_confirmed', 'synthetic-operation');
        $service->record($this->account->id, 'payment_confirmed', 'synthetic-operation');
        $this->assertDatabaseCount('customer_notifications', 1);
        $this->assertFalse(method_exists($service, 'sendPending'));
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
