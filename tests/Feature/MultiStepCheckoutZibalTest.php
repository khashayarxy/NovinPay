<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Events\OrderPaid;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\ShoppingCart;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiStepCheckoutZibalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $customer;

    private CustomerAccount $account;

    private Product $product;

    private CustomerAddress $address;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://novinpaypos.test',
            'payments.zibal.enabled' => true,
            'payments.zibal.live_requests' => true,
            'payments.zibal.sandbox' => false,
            'payments.zibal.merchant' => 'merchant-live-checkout-01',
            'payments.zibal.callback_url' => 'https://novinpaypos.test/payments/zibal/callback',
            'payments.zibal.api_base_url' => 'https://gateway.zibal.ir',
        ]);

        $this->owner = User::factory()->superAdmin()->create();

        $this->customer = Customer::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'خریدار تست زیبال',
            'contact_phone' => '09123334455',
            'name_digest' => bin2hex(random_bytes(32)),
            'phone_digest' => RepairIdentity::digest('phone', '09123334455'),
            'identity_digest' => bin2hex(random_bytes(32)),
            'is_active' => true,
        ]);

        $this->account = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'phone_digest' => RepairIdentity::digest('phone', '09123334455'),
            'phone' => '09123334455',
            'name' => 'خریدار تست زیبال',
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);

        $this->address = CustomerAddress::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_account_id' => $this->account->id,
            'recipient' => 'خریدار تست زیبال',
            'phone' => '09123334455',
            'province' => 'تهران',
            'city' => 'شهریار',
            'address' => 'بلوار ولیعصر پلاک ۲۴',
            'postal_code' => '3351611111',
        ]);

        $category = ProductCategory::storefrontCases()[0];

        $this->product = Product::forceCreate([
            'name' => 'کارتخوان سیار PAX S910',
            'slug' => 'pax-s910-zibal-test',
            'category' => $category,
            'brand' => 'PAX',
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 4500000, // 4,500,000 Toman = 45,000,000 Rial
            'is_published' => true,
        ]);

        InventoryItem::forceCreate([
            'public_id' => (string) Str::uuid(),
            'product_id' => $this->product->id,
            'sku' => 'SKU-PAX-S910-NEW',
            'name' => 'PAX S910',
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'on_hand' => 5,
            'reserved' => 0,
            'version' => 1,
        ]);
    }

    public function test_checkout_renders_three_steps_and_active_zibal_option_when_enabled(): void
    {
        $this->seedCartItem();

        $response = $this->get('https://novinpaypos.test/cart/checkout')->assertOk();
        $response->assertSee('data-checkout-step="1"', false)
            ->assertSee('data-checkout-step="2"', false)
            ->assertSee('data-checkout-step="3"', false)
            ->assertSee('پرداخت آنلاین با درگاه زیبال')
            ->assertSee('name="payment_method" value="zibal"', false)
            ->assertSee('images/zibal-trust-light.svg')
            ->assertSee('پرداخت آنلاین با زیبال');

        // When Zibal is disabled, online option is hidden and request mode is default.
        config(['payments.zibal.enabled' => false]);
        $disabled = $this->get('https://novinpaypos.test/cart/checkout')->assertOk();
        $disabled->assertDontSee('name="payment_method" value="zibal"', false)
            ->assertSee('ثبت درخواست سفارش')
            ->assertSee('پرداخت آنلاین فعلاً در دسترس نیست');
    }

    public function test_checkout_with_zibal_initiates_gateway_redirect_and_settles_on_callback(): void
    {
        Event::fake([OrderPaid::class]);
        [$proof, $revision, $quote] = $this->seedCartItem();

        Http::fake([
            'https://gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 246813579], 200),
        ]);

        $res = $this->post('https://novinpaypos.test/cart/checkout', [
            'cart_context' => $proof,
            'cart_revision' => $revision,
            'operation_key' => (string) Str::uuid(),
            'quote' => $quote,
            'method' => 'tipax',
            'address' => $this->address->public_id,
            'payment_method' => 'zibal',
        ]);

        $attempt = PaymentAttempt::query()->sole();
        $order = SalesOrder::query()->sole();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame(45000000, $attempt->amount_rial);
        $this->assertSame('246813579', $attempt->track_id);
        $this->assertSame('pending', $attempt->state);
        $res->assertRedirect('https://novinpaypos.test/payments/'.$attempt->public_id.'/connect');

        Http::fake([
            'https://gateway.zibal.ir/v1/verify' => Http::response([
                'result' => 100,
                'status' => 1,
                'amount' => 45000000,
                'orderId' => $attempt->public_id,
                'trackId' => 246813579,
                'paidAt' => '2026-11-23T02:20:00',
            ], 200),
        ]);

        $this->get('https://novinpaypos.test/payments/zibal/callback?trackId=246813579&orderId='.$attempt->public_id)
            ->assertRedirect('https://novinpaypos.test/payments/'.$attempt->public_id.'/result');

        $this->assertSame('settled', $attempt->fresh()->state);
        Event::assertDispatchedTimes(OrderPaid::class, 1);

        // Cart is automatically cleared after verified Zibal settlement.
        $this->assertSame(0, app(ShoppingCart::class)->count(request()));
    }

    public function test_checkout_with_zibal_gateway_rejection_preserves_order_and_shows_retry(): void
    {
        [$proof, $revision, $quote] = $this->seedCartItem();

        Http::fake([
            'https://gateway.zibal.ir/v1/request' => Http::response(['result' => 105], 200),
        ]);

        $res = $this->post('https://novinpaypos.test/cart/checkout', [
            'cart_context' => $proof,
            'cart_revision' => $revision,
            'operation_key' => (string) Str::uuid(),
            'quote' => $quote,
            'method' => 'pickup',
            'payment_method' => 'zibal',
        ]);

        $attempt = PaymentAttempt::query()->sole();
        $this->assertSame('failed', $attempt->state);
        $res->assertRedirect('https://novinpaypos.test/payments/'.$attempt->public_id.'/result');

        $this->get('https://novinpaypos.test/payments/'.$attempt->public_id.'/result')
            ->assertOk()
            ->assertSee('پرداخت انجام نشد')
            ->assertSee('تلاش مجدد برای پرداخت');
    }

    private function seedCartItem(): array
    {
        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->withUnencryptedCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('a', 64));

        $cart = $this->get('https://novinpaypos.test/cart')->assertOk();

        $this->post('https://novinpaypos.test/cart/products/'.$this->product->slug, [
            'cart_context' => $cart->viewData('proof'),
            'cart_revision' => $cart->viewData('revision'),
            'quantity' => 1,
        ])->assertRedirect();

        $checkout = $this->get('https://novinpaypos.test/cart/checkout')->assertOk();

        return [
            $checkout->viewData('proof'),
            $checkout->viewData('revision'),
            $checkout->viewData('quote'),
        ];
    }
}
