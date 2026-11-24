<?php

namespace Tests\Feature;

use App\Enums\RepairBrand;
use App\Enums\RepairStage;
use App\Events\OrderPaid;
use App\Events\RepairPaid;
use App\Events\RepairPaymentLinkGenerated;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\FinancialEntry;
use App\Models\PaymentAttempt;
use App\Models\RepairCase;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Notifications\PatternSms;
use App\Services\Notifications\UnifiedNotifier;
use App\Services\ZibalGateway;
use App\Support\RepairIdentity;
use App\Support\RepairPaymentCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZibalUnifiedPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $customer;

    private CustomerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://novinpaypos.test',
            'payments.zibal.enabled' => true,
            'payments.zibal.live_requests' => true,
            'payments.zibal.sandbox' => false,
            'payments.zibal.merchant' => 'merchant-live-test-001',
            'payments.zibal.callback_url' => 'https://novinpaypos.test/payments/zibal/callback',
            'payments.zibal.api_base_url' => 'https://gateway.zibal.ir',
        ]);

        $this->owner = User::factory()->superAdmin()->create();

        $this->customer = Customer::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'مشتری تستی زیبال',
            'contact_phone' => '09120001122',
            'name_digest' => bin2hex(random_bytes(32)),
            'phone_digest' => RepairIdentity::digest('phone', '09120001122'),
            'identity_digest' => bin2hex(random_bytes(32)),
            'is_active' => true,
        ]);

        $this->account = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'phone_digest' => RepairIdentity::digest('phone', '09120001122'),
            'phone' => '09120001122',
            'name' => 'مشتری تستی زیبال',
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);
    }

    public function test_sandbox_merchant_is_allowed_only_when_sandbox_enabled_in_testing(): void
    {
        $gateway = app(ZibalGateway::class);
        config(['payments.zibal.merchant' => 'zibal', 'payments.zibal.sandbox' => false]);
        $this->assertFalse($gateway->available());

        config(['payments.zibal.merchant' => 'zibal', 'payments.zibal.sandbox' => true]);
        $this->assertTrue($gateway->available());
    }

    public function test_storefront_order_zibal_checkout_success_idempotency_and_order_paid_event(): void
    {
        Event::fake([OrderPaid::class]);

        [$order, $document] = $this->createPayableOrder(2500000); // 25,000.00 Toman = 250,000 Rial

        Http::fake([
            'https://gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 987654321], 200),
        ]);

        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1]);

        $start = $this->post('https://novinpaypos.test/payments/documents/'.$document->public_id.'/zibal', [
            'amount' => '25000.00',
            'operation_key' => (string) Str::uuid(),
        ]);

        $attempt = PaymentAttempt::query()->sole();
        $this->assertSame(2500000, $attempt->amount_minor);
        $this->assertSame(250000, $attempt->amount_rial);
        $this->assertSame('987654321', $attempt->track_id);
        $this->assertSame('pending', $attempt->state);
        $start->assertRedirect('https://novinpaypos.test/payments/'.$attempt->public_id.'/connect');

        Http::fake([
            'https://gateway.zibal.ir/v1/verify' => Http::response([
                'result' => 100,
                'status' => 1,
                'amount' => 250000,
                'orderId' => $attempt->public_id,
                'trackId' => 987654321,
                'paidAt' => '2026-11-23T01:05:00',
            ], 200),
        ]);

        $callback = $this->get('https://novinpaypos.test/payments/zibal/callback?trackId=987654321&orderId='.$attempt->public_id);
        $callback->assertRedirect('https://novinpaypos.test/payments/'.$attempt->public_id.'/result');

        $this->assertSame('settled', $attempt->fresh()->state);
        $this->assertSame(2500000, $document->fresh()->paid_minor);

        // Idempotent replay of callback must NOT double-verify or double-dispatch OrderPaid.
        $this->get('https://novinpaypos.test/payments/zibal/callback?trackId=987654321&orderId='.$attempt->public_id)
            ->assertRedirect('https://novinpaypos.test/payments/'.$attempt->public_id.'/result');

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e) => $e->order->id === $order->id && $e->document->id === $document->id);

        $this->get('https://novinpaypos.test/payments/'.$attempt->public_id.'/result')
            ->assertOk()
            ->assertSee('پرداخت تأیید شد')
            ->assertSee('۹۸۷۶۵۴۳۲۱')
            ->assertSee('پیگیری سفارش')->assertSee(route('account.orders.show', $order->public_id));
    }

    public function test_storefront_order_canceled_payment_preserves_unpaid_order_and_shows_retry(): void
    {
        Event::fake([OrderPaid::class]);

        [, $document] = $this->createPayableOrder(1500000);

        Http::fake([
            'https://gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 112233445], 200),
        ]);

        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1]);

        $this->post('https://novinpaypos.test/payments/documents/'.$document->public_id.'/zibal', [
            'amount' => '15000.00',
            'operation_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $attempt = PaymentAttempt::query()->sole();

        Http::fake([
            'https://gateway.zibal.ir/v1/verify' => Http::response(['result' => 202], 200),
            'https://gateway.zibal.ir/v1/inquiry' => Http::response([
                'result' => 100,
                'status' => 3, // canceled by user
                'amount' => 150000,
                'orderId' => $attempt->public_id,
                'trackId' => 112233445,
            ], 200),
        ]);

        $this->get('https://novinpaypos.test/payments/zibal/callback?trackId=112233445&orderId='.$attempt->public_id)
            ->assertRedirect('https://novinpaypos.test/payments/'.$attempt->public_id.'/result');

        $this->assertSame('failed', $attempt->fresh()->state);
        $this->assertSame(0, $document->fresh()->paid_minor);
        Event::assertNotDispatched(OrderPaid::class);

        $this->get('https://novinpaypos.test/payments/'.$attempt->public_id.'/result')
            ->assertOk()
            ->assertSee('پرداخت انجام نشد')
            ->assertSee('تلاش مجدد برای پرداخت');
    }

    public function test_repair_direct_payment_link_generation_guest_zibal_payment_and_events(): void
    {
        Event::fake([RepairPaymentLinkGenerated::class, RepairPaid::class]);

        [$case, $document] = $this->createPayableRepair(3500000); // 35,000.00 Toman = 350,000 Rial

        $this->actingAs($this->owner, 'web')
            ->withSession(['admin_session_version' => 1]);

        $genResponse = $this->post(route('admin.repair-cases.payment-link', $case));
        $genResponse->assertRedirect(route('admin.repair-cases.show', $case));
        $paymentUrl = $genResponse->getSession()->get('repair_payment_link');
        $this->assertIsString($paymentUrl);
        $this->assertStringContainsString('/pay/repair/'.$case->public_id, $paymentUrl);

        Event::assertDispatched(RepairPaymentLinkGenerated::class, fn (RepairPaymentLinkGenerated $e) => $e->repairCase->id === $case->id && $e->paymentUrl === $paymentUrl);

        // Log out admin — customer opens signed link directly as a guest without forcing login.
        auth('web')->logout();
        $this->flushSession();

        // Unsigned/tampered URL must be rejected.
        $this->get('https://novinpaypos.test/pay/repair/'.$case->public_id)->assertForbidden();

        // Valid signed link shows NovinPay summary and Zibal button.
        $linkPage = $this->get($paymentUrl)
            ->assertOk()
            ->assertSee('PAX S910')
            ->assertSee('پرداخت آنلاین با زیبال');

        preg_match('/action="([^"]+)"/', $linkPage->getContent(), $actionMatch);
        $startUrl = html_entity_decode($actionMatch[1], ENT_QUOTES, 'UTF-8');

        Http::fake([
            'https://gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 554433221], 200),
        ]);

        $startRes = $this->post($startUrl, ['operation_key' => (string) Str::uuid()]);
        $attempt = PaymentAttempt::query()->sole();
        $this->assertSame(350000, $attempt->amount_rial);
        $this->assertSame('554433221', $attempt->track_id);
        $startRes->assertRedirect();
        $this->assertStringContainsString('/pay/repair/'.$case->public_id.'/connect/'.$attempt->public_id, $startRes->headers->get('Location'));

        Http::fake([
            'https://gateway.zibal.ir/v1/verify' => Http::response([
                'result' => 100,
                'status' => 1,
                'amount' => 350000,
                'orderId' => $attempt->public_id,
                'trackId' => 554433221,
                'paidAt' => '2026-11-23T01:20:00',
            ], 200),
        ]);

        $cb = $this->get('https://novinpaypos.test/payments/zibal/callback?trackId=554433221&orderId='.$attempt->public_id);
        $cb->assertRedirect();
        $resultUrl = $cb->headers->get('Location');
        $this->assertStringContainsString('/pay/repair/'.$case->public_id.'/result/'.$attempt->public_id, $resultUrl);

        $this->assertSame('settled', $attempt->fresh()->state);
        $this->assertSame(3500000, $document->fresh()->paid_minor);

        Event::assertDispatchedTimes(RepairPaid::class, 1);
        Event::assertDispatched(RepairPaid::class, fn (RepairPaid $e) => $e->repairCase->id === $case->id && $e->document->id === $document->id);

        $this->get($resultUrl)
            ->assertOk()
            ->assertSee('پرداخت تأیید شد')
            ->assertSee('۵۵۴۴۳۳۲۲۱');

        // Re-visiting signed link shows already settled and hides payment button.
        $this->get($paymentUrl)
            ->assertOk()
            ->assertSee('هزینهٔ تعمیر تسویه شده است')
            ->assertDontSee('پرداخت آنلاین با زیبال');
    }

    public function test_repair_direct_payment_link_failure_preserves_unpaid_and_shows_retry(): void
    {
        Event::fake([RepairPaid::class]);

        [$case, $document] = $this->createPayableRepair(1800000);

        $paymentUrl = URL::temporarySignedRoute('payments.repair.link', now()->addDays(14), ['repair' => $case->public_id]);
        $linkPage = $this->get($paymentUrl)->assertOk();
        preg_match('/action="([^"]+)"/', $linkPage->getContent(), $actionMatch);
        $startUrl = html_entity_decode($actionMatch[1], ENT_QUOTES, 'UTF-8');

        Http::fake([
            'https://gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 778899001], 200),
        ]);

        $this->post($startUrl, ['operation_key' => (string) Str::uuid()])->assertRedirect();
        $attempt = PaymentAttempt::query()->sole();

        Http::fake([
            'https://gateway.zibal.ir/v1/verify' => Http::response(['result' => 202], 200),
            'https://gateway.zibal.ir/v1/inquiry' => Http::response([
                'result' => 100,
                'status' => 3,
                'amount' => 180000,
                'orderId' => $attempt->public_id,
                'trackId' => 778899001,
            ], 200),
        ]);

        $cb = $this->get('https://novinpaypos.test/payments/zibal/callback?trackId=778899001&orderId='.$attempt->public_id);
        $cb->assertRedirect();

        $this->assertSame('failed', $attempt->fresh()->state);
        $this->assertSame(0, $document->fresh()->paid_minor);
        Event::assertNotDispatched(RepairPaid::class);

        $this->get($cb->headers->get('Location'))
            ->assertOk()
            ->assertSee('پرداخت انجام نشد')
            ->assertSee('تلاش مجدد برای پرداخت');
    }

    public function test_short_links_preserve_account_and_phone_verification_and_payment_expiry(): void
    {
        [$order] = $this->createPayableOrder(3500000);
        [$case] = $this->createPayableRepair(3500000);
        $orderUrl = route('account.orders.short', ['code' => substr($order->public_id, 0, 8)]);
        $this->get($orderUrl)->assertOk()->assertSee('شماره موبایل')->assertDontSee('data-stage=', false);
        $this->post($orderUrl, ['contact_phone' => '09120001123'])
            ->assertOk()->assertSee('سفارشی با این کد و شماره پیدا نشد.')->assertDontSee('data-stage=', false);
        $this->post($orderUrl, ['contact_phone' => '09120001122'])
            ->assertOk()->assertSee('data-stage="current"', false)->assertDontSee($this->customer->name);
        $this->actingAs($this->account, 'customer')->withSession(['customer_session_version' => 1]);
        $this->get($orderUrl)->assertRedirect(route('account.orders.show', ['record' => $order->public_id]));
        $other = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'Another account',
            'phone' => '09120001123',
            'phone_digest' => RepairIdentity::digest('customer-phone', '09120001123'),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);
        $this->actingAs($other, 'customer')->get($orderUrl)->assertNotFound();

        $this->get('/r/40219900')->assertOk()
            ->assertSee('value="40219900"', false)
            ->assertDontSee('data-stage=', false)
            ->assertDontSee('09120001122');
        $this->post(route('repair.track.store'), [
            'tracking_reference' => '40219900',
            'contact_phone' => '09120001122',
        ])->assertOk()->assertSee('data-stage="current"', false)->assertSee('data-stage="upcoming"', false)
            ->assertDontSee($case->device_model);

        $expiry = now()->addMinutes(10);
        $code = RepairPaymentCode::issue($case->public_id, $expiry->timestamp);
        $this->get('/p/'.$code)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertDontSee($case->customer_name)
            ->assertDontSee($case->contact_phone)
            ->assertDontSee($case->device_model)
            ->assertSee('پرداخت آنلاین با زیبال')
            ->assertSee('expires='.$expiry->timestamp, false);
        $this->get('/p/'.($code[0] === 'a' ? 'b' : 'a').substr($code, 1))->assertNotFound();
        $this->travel(11)->minutes();
        $this->get('/p/'.$code)->assertNotFound();
    }

    public function test_all_ippanel_patterns_use_the_expected_variables_and_send_once_per_event(): void
    {
        [$order] = $this->createPayableOrder(3500000);
        [$case, $document] = $this->createPayableRepair(3500000);
        config([
            'notifications.pattern_sms.enabled' => null,
            'notifications.pattern_sms.provider' => null,
            'notifications.pattern_sms.patterns' => [],
            'customer.sms_provider' => 'ippanel',
            'customer.ippanel.enabled' => true,
            'customer.ippanel.api_key' => 'ippanel-test-token',
            'customer.ippanel.sender' => '+983000505',
            'customer.ippanel.pattern_code' => 'otpPatternOnly',
        ]);
        $this->assertNull(app(PatternSms::class)->configurationIssue());
        Http::preventStrayRequests();
        Http::fake(['https://edge.ippanel.com/v1/api/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [123]]])]);
        $url = URL::temporarySignedRoute('payments.repair.link', now()->addDays(14), ['repair' => $case->public_id]);
        $notifier = app(UnifiedNotifier::class);
        for ($delivery = 0; $delivery < 2; $delivery++) {
            foreach (['order_confirmed', 'order_preparing', 'order_pickup_ready', 'order_cancelled'] as $event) {
                $notifier->orderPattern($order, $event);
            }
            $notifier->orderShipped($order, 'TIPAX-12345');
            $notifier->repairCreatedPattern($case);
            $notifier->repairPaymentLinkGenerated($case, $document, $url);
            $case->status = RepairStage::Ready;
            $notifier->repairStagePattern($case);
            $case->status = RepairStage::Returned;
            $notifier->repairStagePattern($case);
        }

        $expected = [
            'mfae2yqje8msw9e' => ['order_code', 'name', 'product'],
            '694jmegiuep02i6' => ['order_code'],
            'yksd5440vew1loy' => ['order_code', 'tracking_code'],
            '309p3u2jo5b7qds' => ['name', 'repair_code'],
            'a7h9o09htzyjk17' => ['repair_code', 'pay_code'],
            'hn7sgk679tfdxvt' => ['repair_code', 'status'],
            'u2fc816s2qo1u5t' => ['order_code'],
            'xtawhyckra6k1tt' => ['order_code'],
            '8zvy6p1t482z3oy' => ['repair_code'],
        ];
        Http::assertSentCount(count($expected));
        foreach ($expected as $pattern => $keys) {
            Http::assertSent(fn ($request) => $request->url() === 'https://edge.ippanel.com/v1/api/send'
                && $request->hasHeader('Authorization', 'ippanel-test-token')
                && $request['from_number'] === '+983000505'
                && $request['recipients'] === ['+989120001122']
                && $request['code'] === $pattern
                && array_keys($request['params']) === $keys);
        }
        $payment = Http::recorded(fn ($request) => $request['code'] === 'a7h9o09htzyjk17')->sole()[0];
        $this->assertSame($case->public_id, RepairPaymentCode::resolve($payment['params']['pay_code'])['repair']);
    }

    public function test_panelchi_missing_config_does_not_claim_events_and_provider_errors_are_sanitized(): void
    {
        Log::spy();
        config([
            'notifications.pattern_sms.provider' => 'panelchi',
            'customer.ippanel.api_key' => 'must-not-use',
            'notifications.panelchi.enabled' => true,
            'notifications.panelchi.api_key' => '',
            'notifications.panelchi.sender' => '3000505',
        ]);
        Http::fake(['https://api.panelchi.com/sms/pattern' => Http::response(['detail' => 'private provider response'], 403)]);
        $service = app(PatternSms::class);
        $service->send('order_preparing', 'one-order', '09120001122', ['order_code' => 'ORDER']);
        Http::assertNothingSent();
        Log::shouldHaveReceived('log')->with('warning', 'SMS pattern skipped', ['event' => 'order_preparing', 'provider' => 'panelchi', 'reason' => 'missing_api_key'])->once();

        config([
            'notifications.panelchi.api_key' => 'Bearer panelchi-test-token',
            'notifications.pattern_sms.patterns.order_preparing' => 'override123',
        ]);
        $service->send('order_preparing', 'one-order', '09120001122', ['order_code' => 'ORDER']);
        $service->send('order_preparing', 'one-order', '09120001122', ['order_code' => 'ORDER']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['pattern'] === 'override123'
            && $request->hasHeader('Authorization', 'Bearer panelchi-test-token'));
        Log::shouldHaveReceived('log')->with('warning', 'SMS pattern provider outcome', [
            'event' => 'order_preparing', 'provider' => 'panelchi', 'outcome' => 'unconfirmed', 'http_status' => 403,
        ])->once();
    }

    public function test_order_progress_uses_the_admin_workflow_and_keeps_the_send_claim_on_timeout(): void
    {
        [, $document] = $this->createPayableOrder(3500000);
        $plan = DeliveryPlan::query()->where('financial_document_id', $document->id)->sole();
        $url = route('admin.deliveries.order-progress', $plan->public_id);
        config([
            'notifications.pattern_sms.provider' => 'ippanel',
            'customer.ippanel.enabled' => true,
            'customer.ippanel.api_key' => 'ippanel-test-token',
            'customer.ippanel.sender' => '+983000505',
            'notifications.panelchi.enabled' => true,
            'notifications.panelchi.api_key' => 'panelchi-test-token',
            'notifications.panelchi.sender' => '3000505',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://edge.ippanel.com/v1/api/send' => Http::failedConnection(),
        ]);
        $input = ['version' => $plan->version, 'progress' => 'pickup_ready'];
        $this->actingAs($this->owner, 'web')->withSession(['admin_session_version' => 1]);
        $this->post($url, $input)->assertConflict();
        $this->assertDatabaseCount('delivery_events', 0);
        $plan->forceFill(['method' => 'pickup'])->save();
        $this->post($url, $input)->assertRedirect();
        $this->post($url, $input)->assertRedirect();
        $this->assertDatabaseCount('delivery_events', 1);
        $this->assertDatabaseHas('delivery_events', ['delivery_plan_id' => $plan->id, 'action' => 'order_pickup_ready']);

        config(['notifications.pattern_sms.provider' => 'panelchi']);
        Http::fake(['https://api.panelchi.com/sms/pattern' => Http::response(['data' => ['uid' => 'unexpected-replay']])]);
        $order = SalesOrder::query()->findOrFail($document->sales_order_id);
        app(PatternSms::class)->send('order_pickup_ready', $order->public_id, '09120001122', ['order_code' => substr($order->public_id, 0, 8)]);
        Http::assertNothingSent();
    }

    private function createPayableOrder(int $minor): array
    {
        $order = SalesOrder::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'customer_account_id' => $this->account->id,
            'checkout_key' => (string) Str::uuid(),
            'checkout_digest' => bin2hex(random_bytes(32)),
            'status' => 'confirmed',
            'currency' => 'TOMAN',
            'total' => number_format($minor / 100, 2, '.', ''),
            'version' => 1,
        ]);

        $document = FinancialDocument::forceCreate([
            'public_id' => (string) Str::uuid(),
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->owner->id,
            'source_version' => 1,
            'source_amount_minor' => $minor,
            'charged_minor' => $minor,
            'paid_minor' => 0,
            'version' => 1,
            'created_at' => now(),
        ]);
        $this->seedChargeEntry($document, 'sales', $minor);

        $address = CustomerAddress::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_account_id' => $this->account->id,
            'recipient' => 'مشتری تستی',
            'phone' => '09120001122',
            'province' => 'تهران',
            'city' => 'شهریار',
            'address' => 'خیابان ولیعصر پلاک ۱۰',
            'postal_code' => '3351612345',
        ]);

        DeliveryPlan::forceCreate([
            'public_id' => (string) Str::uuid(),
            'financial_document_id' => $document->id,
            'customer_account_id' => $this->account->id,
            'method' => 'tipax',
            'shipping_minor' => 0,
            'version' => 1,
        ]);

        return [$order, $document];
    }

    private function createPayableRepair(int $minor): array
    {
        $tracking = '40219900';
        $case = RepairCase::forceCreate([
            'public_id' => (string) Str::uuid(),
            'tracking_reference' => $tracking,
            'tracking_digest' => RepairIdentity::digest('tracking', $tracking),
            'intake_digest' => bin2hex(random_bytes(32)),
            'customer_id' => $this->customer->id,
            'customer_account_id' => $this->account->id,
            'received_by' => $this->owner->id,
            'received_at' => now(),
            'status' => RepairStage::Ready,
            'customer_name' => 'مشتری تستی تعمیر',
            'contact_phone' => '09120001122',
            'phone_digest' => RepairIdentity::digest('phone', '09120001122'),
            'device_brand' => RepairBrand::Pax,
            'device_model' => 'PAX S910',
            'issue' => 'عدم شارژ و خرابی سوکت',
            'physical_condition' => 'سالم',
            'accessories' => 'ندارد',
            'repair_price_toman' => intdiv($minor, 100),
            'simple_workflow' => true,
            'version' => 1,
        ]);

        $document = FinancialDocument::forceCreate([
            'public_id' => (string) Str::uuid(),
            'repair_case_id' => $case->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->owner->id,
            'source_version' => 1,
            'source_amount_minor' => $minor,
            'charged_minor' => $minor,
            'paid_minor' => 0,
            'version' => 1,
            'created_at' => now(),
        ]);
        $this->seedChargeEntry($document, 'repair', $minor);

        return [$case, $document];
    }

    private function seedChargeEntry(FinancialDocument $document, string $category, int $minor): void
    {
        $opId = DB::table('financial_operations')->insertGetId([
            'operation_key' => (string) Str::uuid(),
            'actor_id' => $this->owner->id,
            'action' => 'issue',
            'payload_digest' => bin2hex(random_bytes(32)),
            'created_at' => now(),
        ]);

        FinancialEntry::forceCreate([
            'public_id' => (string) Str::uuid(),
            'financial_operation_id' => $opId,
            'financial_document_id' => $document->id,
            'actor_id' => $this->owner->id,
            'kind' => 'charge',
            'category' => $category,
            'charge_delta' => $minor,
            'receipt_delta' => 0,
            'expense_delta' => 0,
            'note' => 'صدور سند تست',
            'created_at' => now(),
        ]);
    }
}
