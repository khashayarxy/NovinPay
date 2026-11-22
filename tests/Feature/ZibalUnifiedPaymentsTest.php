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
use App\Services\ZibalGateway;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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
            ->assertSee('حساب کاربری');
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
        $tracking = '402199';
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
