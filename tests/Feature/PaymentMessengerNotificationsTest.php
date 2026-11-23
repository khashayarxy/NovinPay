<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\RepairBrand;
use App\Enums\RepairStage;
use App\Enums\StockStatus;
use App\Events\OrderPaid;
use App\Events\RepairPaid;
use App\Events\RepairPaymentLinkGenerated;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\RepairCase;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentMessengerNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $customer;

    private CustomerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'app.url' => 'https://novinpaypos.test',
            'notifications.telegram.enabled' => true,
            'notifications.telegram.bot_token' => '123456:TEST-TELEGRAM-TOKEN',
            'notifications.telegram.chat_id' => '-10099887766',
            'notifications.telegram.api_base' => 'https://api.telegram.org',
            'notifications.bale.enabled' => true,
            'notifications.bale.bot_token' => '987654:TEST-BALE-TOKEN',
            'notifications.bale.chat_id' => '55443322',
            'notifications.bale.api_base' => 'https://tapi.bale.ai',
        ]);

        $this->owner = User::factory()->superAdmin()->create();
        $this->customer = Customer::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'رضا نوری',
            'contact_phone' => '09121112233',
            'name_digest' => bin2hex(random_bytes(32)),
            'phone_digest' => RepairIdentity::digest('phone', '09121112233'),
            'identity_digest' => bin2hex(random_bytes(32)),
            'is_active' => true,
        ]);
        $this->account = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'phone_digest' => RepairIdentity::digest('phone', '09121112233'),
            'phone' => '09121112233',
            'name' => 'رضا نوری',
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);
    }

    public function test_order_paid_sends_rich_persian_telegram_and_bale_alerts_once_with_idempotency(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://tapi.bale.ai/*' => Http::response(['ok' => true], 200),
        ]);

        [$order, $document, $transaction] = $this->makePaidOrderFixture(1050000000, 'pickup', '776655443');

        OrderPaid::dispatch($order, $document, $transaction);
        // Duplicate dispatch (e.g. callback replay) must NOT spam a second alert.
        OrderPaid::dispatch($order, $document, $transaction);

        Http::assertSentCount(2);

        Http::assertSent(function (HttpRequest $req) use ($order): bool {
            if (! str_starts_with($req->url(), 'https://api.telegram.org/bot123456:TEST-TELEGRAM-TOKEN/sendMessage')) {
                return false;
            }
            $text = (string) ($req['text'] ?? '');
            $code = strtoupper(substr($order->public_id, 0, 8));

            return $req['chat_id'] === '-10099887766'
                && str_contains($text, 'پرداخت آنلاین سفارش تأیید شد')
                && str_contains($text, $code)
                && str_contains($text, 'رضا نوری')
                && str_contains($text, '۰۹۱۲۱۱۱۲۲۳۳')
                && str_contains($text, 'کارتخوان PAX S910 × ۱')
                && str_contains($text, 'تحویل حضوری در فروشگاه نوین پی')
                && str_contains($text, '۱۰٬۵۰۰٬۰۰۰ تومان')
                && ! str_contains($text, '.00')
                && str_contains($text, '۷۷۶۶۵۵۴۴۳');
        });

        Http::assertSent(fn (HttpRequest $req) => str_starts_with($req->url(), 'https://tapi.bale.ai/bot987654:TEST-BALE-TOKEN/sendMessage')
            && $req['chat_id'] === '55443322'
            && str_contains((string) ($req['text'] ?? ''), '۱۰٬۵۰۰٬۰۰۰ تومان'));
    }

    public function test_repair_paid_and_payment_link_generated_send_rich_persian_alerts(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://tapi.bale.ai/*' => Http::response(['ok' => true], 200),
        ]);

        [$case, $document, $transaction] = $this->makePaidRepairFixture(45000000, '332211009');
        $paymentUrl = 'https://novinpaypos.test/pay/repair/'.$case->public_id.'?signature=demo';

        RepairPaymentLinkGenerated::dispatch($case, $document, $paymentUrl, $this->owner);
        RepairPaid::dispatch($case, $document, $transaction);
        // Replay must be idempotent.
        RepairPaid::dispatch($case, $document, $transaction);

        Http::assertSentCount(4);

        Http::assertSent(fn (HttpRequest $req) => str_starts_with($req->url(), 'https://api.telegram.org/')
            && str_contains((string) ($req['text'] ?? ''), 'لینک پرداخت آنلاین تعمیر ساخته شد')
            && str_contains((string) ($req['text'] ?? ''), '۴۰۵۵۱۲')
            && str_contains((string) ($req['text'] ?? ''), $paymentUrl));

        Http::assertSent(fn (HttpRequest $req) => str_starts_with($req->url(), 'https://api.telegram.org/')
            && str_contains((string) ($req['text'] ?? ''), 'هزینه تعمیر آنلاین پرداخت شد')
            && str_contains((string) ($req['text'] ?? ''), 'PAX · S910')
            && str_contains((string) ($req['text'] ?? ''), '۴۵۰٬۰۰۰ تومان')
            && str_contains((string) ($req['text'] ?? ''), '۳۳۲۲۱۱۰۰۹'));
    }

    public function test_unreachable_telegram_and_bale_fail_gracefully_without_throwing(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('Simulated network timeout');
        });

        [$order, $document, $transaction] = $this->makePaidOrderFixture(25000000, 'tipax', '111222333');

        // Must never throw or break payment settlement/callback.
        OrderPaid::dispatch($order, $document, $transaction);

        $this->assertTrue(true);
    }

    public function test_artisan_notify_test_command_delivers_safely_without_creating_orders(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://tapi.bale.ai/*' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('novinpay:notify-test', ['--channel' => 'telegram'])
            ->expectsOutputToContain('[telegram] Test notification delivered successfully.')
            ->assertExitCode(0);

        $this->artisan('novinpay:notify-test', ['--channel' => 'all'])
            ->expectsOutputToContain('[bale] Test notification delivered successfully.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('sales_orders', 0);

        config(['notifications.bale.enabled' => false]);
        $this->artisan('novinpay:notify-test', ['--channel' => 'bale'])
            ->expectsOutputToContain('[bale] Channel is not enabled')
            ->assertExitCode(1);
    }

    private function makePaidOrderFixture(int $minor, string $method, string $trackId): array
    {
        $category = ProductCategory::storefrontCases()[0];
        $product = Product::forceCreate([
            'name' => 'کارتخوان PAX S910',
            'slug' => 'pax-s910-'.Str::random(6),
            'category' => $category,
            'brand' => 'PAX',
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => intdiv($minor, 100),
            'is_published' => true,
        ]);
        $inv = InventoryItem::forceCreate([
            'public_id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'sku' => 'SKU-'.Str::upper(Str::random(6)),
            'name' => 'PAX S910',
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'on_hand' => 5,
            'reserved' => 0,
            'version' => 1,
        ]);

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
        SalesOrderLine::forceCreate([
            'sales_order_id' => $order->id,
            'inventory_item_id' => $inv->id,
            'sku' => $inv->sku,
            'description' => 'کارتخوان PAX S910',
            'condition' => ProductCondition::New->value,
            'quantity' => 1,
            'revision' => 1,
            'unit_price' => number_format($minor / 100, 2, '.', ''),
            'line_total' => number_format($minor / 100, 2, '.', ''),
        ]);

        $document = FinancialDocument::forceCreate([
            'public_id' => (string) Str::uuid(),
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->owner->id,
            'source_version' => 1,
            'source_amount_minor' => $minor,
            'charged_minor' => $minor,
            'paid_minor' => $minor,
            'version' => 1,
            'created_at' => now(),
        ]);

        DeliveryPlan::forceCreate([
            'public_id' => (string) Str::uuid(),
            'financial_document_id' => $document->id,
            'customer_account_id' => $this->account->id,
            'method' => $method,
            'shipping_minor' => 0,
            'version' => 1,
        ]);

        $attempt = PaymentAttempt::forceCreate([
            'public_id' => (string) Str::uuid(),
            'financial_document_id' => $document->id,
            'customer_account_id' => $this->account->id,
            'operation_key' => (string) Str::uuid(),
            'amount_minor' => $minor,
            'amount_rial' => intdiv($minor, 10),
            'document_version' => 1,
            'merchant_digest' => bin2hex(random_bytes(32)),
            'track_id' => $trackId,
            'state' => 'settled',
        ]);

        $transaction = PaymentTransaction::forceCreate([
            'public_id' => (string) Str::uuid(),
            'financial_document_id' => $document->id,
            'payment_attempt_id' => $attempt->id,
            'operation_key' => (string) Str::uuid(),
            'method' => 'gateway',
            'amount_minor' => $minor,
            'allocated_minor' => $minor,
            'reference_digest' => bin2hex(random_bytes(32)),
            'reference' => 'ZIBAL-TEST-'.$trackId,
            'created_at' => now(),
        ]);

        return [$order, $document, $transaction];
    }

    private function makePaidRepairFixture(int $minor, string $trackId): array
    {
        $tracking = '405512';
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
            'customer_name' => 'رضا نوری',
            'contact_phone' => '09121112233',
            'phone_digest' => RepairIdentity::digest('phone', '09121112233'),
            'device_brand' => RepairBrand::Pax,
            'device_model' => 'S910',
            'issue' => 'خرابی سوکت شارژ',
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

        $attempt = PaymentAttempt::forceCreate([
            'public_id' => (string) Str::uuid(),
            'financial_document_id' => $document->id,
            'customer_account_id' => $this->account->id,
            'operation_key' => (string) Str::uuid(),
            'amount_minor' => $minor,
            'amount_rial' => intdiv($minor, 10),
            'document_version' => 1,
            'merchant_digest' => bin2hex(random_bytes(32)),
            'track_id' => $trackId,
            'state' => 'settled',
        ]);

        $transaction = PaymentTransaction::forceCreate([
            'public_id' => (string) Str::uuid(),
            'financial_document_id' => $document->id,
            'payment_attempt_id' => $attempt->id,
            'operation_key' => (string) Str::uuid(),
            'method' => 'gateway',
            'amount_minor' => $minor,
            'allocated_minor' => $minor,
            'reference_digest' => bin2hex(random_bytes(32)),
            'reference' => 'ZIBAL-REPAIR-'.$trackId,
            'created_at' => now(),
        ]);

        return [$case, $document, $transaction];
    }
}
