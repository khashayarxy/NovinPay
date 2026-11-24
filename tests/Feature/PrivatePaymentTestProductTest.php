<?php

namespace Tests\Feature;

use App\Console\Commands\ProvisionPaymentTestProduct;
use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Models\AdminAccount;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\StoreCheckout;
use App\Support\PaymentTestProduct;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PrivatePaymentTestProductTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $publicProduct;

    private Product $testProduct;

    private InventoryItem $testInventory;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://novinpaypos.test',
            'payments.zibal.enabled' => true,
            'payments.zibal.live_requests' => true,
            'payments.zibal.sandbox' => false,
            'payments.zibal.merchant' => 'merchant-private-test-01',
            'payments.zibal.callback_url' => 'https://novinpaypos.test/payments/zibal/callback',
            'payments.zibal.api_base_url' => 'https://gateway.zibal.ir',
            'payments.test_product.slug' => 'zibal-gateway-test-5000',
            'payments.test_product.allowed_phones' => ['+98 912-345-6789', '۰۹۱۹۸۷۶۵۴۳۲'],
        ]);

        $this->owner = User::factory()->superAdmin()->create();
        if (! AdminAccount::query()->where('user_id', $this->owner->id)->exists()) {
            AdminAccount::forceCreate([
                'user_id' => $this->owner->id,
                'role' => 'owner',
                'owner_slot' => 1,
                'is_active' => true,
            ]);
        }

        $this->publicProduct = Product::forceCreate([
            'name' => 'کارتخوان سیار PAX S910',
            'slug' => 'pax-s910-public',
            'category' => ProductCategory::PosTerminal,
            'brand' => 'PAX',
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 4_500_000,
            'is_published' => true,
        ]);

        InventoryItem::forceCreate([
            'public_id' => (string) Str::uuid(),
            'product_id' => $this->publicProduct->id,
            'sku' => 'SKU-PAX-S910-PUBLIC',
            'name' => 'PAX S910',
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'on_hand' => 10,
            'reserved' => 0,
            'version' => 1,
        ]);

        $this->artisan('novinpay:provision-payment-test-product', ['--apply' => true])
            ->assertExitCode(0);

        $this->testProduct = Product::query()->where('slug', 'zibal-gateway-test-5000')->firstOrFail();
        $this->testInventory = InventoryItem::query()->where('product_id', $this->testProduct->id)->firstOrFail();
    }

    public function test_provisioning_command_requires_apply_and_is_idempotent(): void
    {
        $this->artisan('novinpay:provision-payment-test-product')
            ->assertExitCode(2);

        $this->assertSame('محصول تست درگاه پرداخت (خصوصی)', $this->testProduct->name);
        $this->assertSame(5_000, $this->testProduct->price);
        $this->assertTrue($this->testProduct->is_published);
        $this->assertTrue($this->testProduct->online_purchasing_enabled);
        $this->assertSame(ProvisionPaymentTestProduct::TARGET_AVAILABLE_STOCK, $this->testInventory->available);

        $this->artisan('novinpay:provision-payment-test-product', ['--apply' => true])
            ->expectsOutput('PAYMENT_TEST_PRODUCT_READY')
            ->assertExitCode(0);

        $this->assertSame(1, Product::query()->where('slug', 'zibal-gateway-test-5000')->count());
        $this->assertSame(999, $this->testInventory->fresh()->available);
    }

    public function test_phone_normalization_handles_variants_and_empty_allowlist(): void
    {
        $this->assertSame('09123456789', PaymentTestProduct::normalizePhone('+98 912-345-6789'));
        $this->assertSame('09123456789', PaymentTestProduct::normalizePhone('00989123456789'));
        $this->assertSame('09123456789', PaymentTestProduct::normalizePhone('989123456789'));
        $this->assertSame('09123456789', PaymentTestProduct::normalizePhone('۰۹۱۲۳۴۵۶۷۸۹'));
        $this->assertSame('', PaymentTestProduct::normalizePhone(''));
        $this->assertSame('', PaymentTestProduct::normalizePhone(null));

        $this->assertSame(['09123456789', '09198765432'], PaymentTestProduct::allowedPhones());

        config(['payments.test_product.allowed_phones' => explode(',', '')]);
        $this->assertSame([], PaymentTestProduct::allowedPhones());

        $blankPhoneAccount = $this->createCustomerAccount('');
        $this->assertFalse(PaymentTestProduct::canAccess($blankPhoneAccount));
    }

    public function test_guest_cannot_see_or_access_private_test_product_anywhere(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee($this->testProduct->name)
            ->assertDontSee($this->testProduct->slug);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee($this->publicProduct->name)
            ->assertDontSee($this->testProduct->name)
            ->assertDontSee($this->testProduct->slug);

        $this->get(route('products.index', ['q' => 'درگاه']))
            ->assertOk()
            ->assertDontSee($this->testProduct->name);

        $this->get(route('products.show', $this->testProduct))
            ->assertNotFound();

        $this->get(route('products.show', $this->publicProduct))
            ->assertOk()
            ->assertDontSee($this->testProduct->name)
            ->assertDontSee($this->testProduct->slug);

        $this->get(route('products.compare', ['products' => [$this->testProduct->slug]]))
            ->assertOk()
            ->assertDontSee($this->testProduct->name);

        $this->get(route('sitemap.part', ['type' => 'products', 'page' => 1]))
            ->assertOk()
            ->assertSee($this->publicProduct->slug)
            ->assertDontSee($this->testProduct->slug);

        $cartIndex = $this->get(route('cart.index'))->assertOk();
        preg_match('/data-cart-context="([^"]+)"/', $cartIndex->getContent(), $proofMatch);
        $proof = $proofMatch[1] ?? '';

        $this->postJson(route('cart.add', $this->testProduct), [
            'quantity' => 1,
            'cart_context' => $proof,
        ])->assertNotFound();
    }

    public function test_non_allowlisted_customer_is_blocked_from_catalog_detail_cart_and_checkout(): void
    {
        $otherAccount = $this->createCustomerAccount('09351112233');

        $this->actingAsCustomer($otherAccount)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee($this->testProduct->name)
            ->assertDontSee($this->testProduct->slug);

        $this->actingAsCustomer($otherAccount)
            ->get(route('products.index'))
            ->assertOk()
            ->assertDontSee($this->testProduct->name);

        $this->actingAsCustomer($otherAccount)
            ->get(route('products.index', ['q' => 'خصوصی']))
            ->assertOk()
            ->assertDontSee($this->testProduct->name);

        $this->actingAsCustomer($otherAccount)
            ->get(route('products.show', $this->testProduct))
            ->assertNotFound();

        $cartIndex = $this->actingAsCustomer($otherAccount)->get(route('cart.index'))->assertOk();
        preg_match('/data-cart-context="([^"]+)"/', $cartIndex->getContent(), $proofMatch);
        $proof = $proofMatch[1] ?? '';

        $this->actingAsCustomer($otherAccount)
            ->postJson(route('cart.add', $this->testProduct), [
                'quantity' => 1,
                'cart_context' => $proof,
            ])->assertNotFound();

        // Even if a cart row is forcibly seeded in the DB, checkout must reject it.
        $accountScope = hash('sha256', 'account:'.$otherAccount->id);
        $state = [
            $this->testProduct->slug => [
                'inventory' => $this->testInventory->id,
                'quantity' => 1,
                'version' => (string) Str::uuid(),
            ],
        ];
        DB::table('shopping_carts')->insert([
            'scope' => $accountScope,
            'state' => json_encode($state, JSON_THROW_ON_ERROR),
        ]);

        $this->actingAsCustomer($otherAccount)
            ->get(route('cart.checkout'))
            ->assertRedirect(route('cart.index'));

        Http::fake();
        $quote = app(StoreCheckout::class)->quote(collect([$this->testProduct]));
        $checkoutCartPage = $this->actingAsCustomer($otherAccount)->get(route('cart.index'))->assertOk();
        preg_match('/data-cart-context="([^"]+)"/', $checkoutCartPage->getContent(), $ctxMatch);
        preg_match('/data-cart-revision="([^"]+)"/', $checkoutCartPage->getContent(), $revMatch);

        $blockedResponse = $this->actingAsCustomer($otherAccount)
            ->post(route('cart.checkout'), [
                'operation_key' => (string) Str::uuid(),
                'quote' => $quote,
                'cart_context' => $ctxMatch[1] ?? '',
                'cart_revision' => $revMatch[1] ?? '',
                'method' => 'pickup',
                'payment_method' => 'zibal',
            ]);
        $this->assertContains($blockedResponse->getStatusCode(), [403, 409]);

        // Direct StoreCheckout::create() call must also fail with 403.
        try {
            app(StoreCheckout::class)->create($otherAccount, [$this->testProduct->slug => 1], (string) Str::uuid(), $quote);
            $this->fail('Expected 403 HttpException from StoreCheckout::create()');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, SalesOrder::query()->count());
        $this->assertSame(0, PaymentAttempt::query()->count());
        Http::assertNothingSent();
    }

    public function test_allowlisted_customer_can_view_add_and_pay_5000_toman_via_zibal(): void
    {
        $allowedAccount = $this->createCustomerAccount('09123456789');

        $this->actingAsCustomer($allowedAccount)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee($this->testProduct->name)
            ->assertSee('خصوصی · تست درگاه')
            ->assertSee('۵٬۰۰۰')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAsCustomer($allowedAccount)
            ->get(route('products.show', $this->testProduct))
            ->assertOk()
            ->assertSee($this->testProduct->name)
            ->assertSee('خصوصی · تست درگاه')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertHeader('Cache-Control', 'no-store, private');

        // Still excluded from sitemap and product comparison even for allowlisted user.
        $this->actingAsCustomer($allowedAccount)
            ->get(route('sitemap.part', ['type' => 'products', 'page' => 1]))
            ->assertOk()
            ->assertDontSee($this->testProduct->slug);

        $this->actingAsCustomer($allowedAccount)
            ->get(route('products.compare', ['products' => [$this->testProduct->slug]]))
            ->assertOk()
            ->assertDontSee($this->testProduct->name);

        // Add to cart via storefront endpoint.
        $detail = $this->actingAsCustomer($allowedAccount)
            ->get(route('products.show', $this->testProduct))
            ->assertOk();
        preg_match('/name="cart_context" value="([^"]+)"/', $detail->getContent(), $proofMatch);
        $proof = $proofMatch[1];

        $this->actingAsCustomer($allowedAccount)
            ->postJson(route('cart.add', $this->testProduct), [
                'quantity' => 1,
                'cart_context' => $proof,
            ], [
                'X-Storefront-Cart-State' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('total', 5000);

        $checkoutPage = $this->actingAsCustomer($allowedAccount)
            ->get(route('cart.checkout'))
            ->assertOk()
            ->assertSee($this->testProduct->name)
            ->assertSee('۵٬۰۰۰');

        preg_match('/name="quote" value="([^"]+)"/', $checkoutPage->getContent(), $quoteMatch);
        preg_match('/name="cart_context" value="([^"]+)"/', $checkoutPage->getContent(), $ctxMatch);
        preg_match('/name="cart_revision" value="([^"]+)"/', $checkoutPage->getContent(), $revMatch);

        Http::fake([
            'https://gateway.zibal.ir/v1/request' => Http::response([
                'result' => 100,
                'trackId' => 88997766,
            ], 200),
        ]);

        $response = $this->actingAsCustomer($allowedAccount)
            ->post(route('cart.checkout'), [
                'operation_key' => (string) Str::uuid(),
                'quote' => $quoteMatch[1],
                'cart_context' => $ctxMatch[1],
                'cart_revision' => $revMatch[1],
                'method' => 'pickup',
                'payment_method' => 'zibal',
            ]);

        $attempt = PaymentAttempt::query()->firstOrFail();
        $response->assertRedirect(route('payments.connect', $attempt->public_id));

        $order = SalesOrder::query()->firstOrFail();
        $this->assertSame('5000.00', $order->total);
        $this->assertSame(500000, $attempt->amount_minor);
        $this->assertSame(50000, $attempt->amount_rial);
        $this->assertSame('pending', $attempt->state);
        $this->assertSame('88997766', $attempt->track_id);

        Http::assertSent(fn ($request) => $request->url() === 'https://gateway.zibal.ir/v1/request'
            && (int) $request['amount'] === 50000);
    }

    public function test_admin_panel_shows_private_test_product_with_badge(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee($this->testProduct->name)
            ->assertSee('تست خصوصی');
    }

    private function actingAsCustomer(CustomerAccount $account): self
    {
        return $this->actingAs($account, 'customer')
            ->withSession(['customer_session_version' => $account->session_version]);
    }

    private function createCustomerAccount(string $phone): CustomerAccount
    {
        $normalized = PaymentTestProduct::normalizePhone($phone);
        $digest = $normalized !== ''
            ? RepairIdentity::digest('phone', $normalized)
            : hash_hmac('sha256', 'blank:'.Str::uuid(), config('app.key'));

        $customer = Customer::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'مشتری تست',
            'contact_phone' => $normalized !== '' ? $normalized : '09000000000',
            'name_digest' => bin2hex(random_bytes(32)),
            'phone_digest' => $digest,
            'identity_digest' => bin2hex(random_bytes(32)),
            'is_active' => true,
        ]);

        return CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'phone_digest' => $digest,
            'phone' => $phone,
            'name' => 'مشتری تست',
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);
    }
}
