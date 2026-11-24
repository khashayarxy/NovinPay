<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CommerceManagement;
use App\Services\ShoppingCart;
use App\Services\StoreCheckout;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class CartStockAndQuantitySpeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        URL::forceRootUrl('https://novinpaypos.test');
        URL::forceScheme('https');
    }

    public function test_ordering_ten_fifty_and_hundred_when_hundred_in_stock_succeeds_and_checkout_creates_order(): void
    {
        [$product] = $this->createStockedProduct('pos-paper-roll-test', 100, false, 85_000);
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('a', 64));

        $initial = $this->get(route('cart.index'))->assertOk();
        $add10 = $this->withHeaders(['X-Storefront-Cart-State' => '1'])
            ->postJson(route('cart.add', $product), [
                'cart_context' => $initial->viewData('proof'),
                'cart_revision' => $initial->viewData('revision'),
                'quantity' => 10,
            ])
            ->assertOk()
            ->assertJsonMissingPath('html')
            ->assertJsonPath('count', 10)
            ->assertJsonPath('quantities.'.$product->slug, 10)
            ->assertJsonPath('available.'.$product->slug, 100)
            ->assertJsonPath('stockAvailable.'.$product->slug, 100)
            ->assertJsonPath('prices.'.$product->slug, 85_000)
            ->assertJsonPath('total', 850_000);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('max="100"', false)
            ->assertSee('data-product-max="100"', false)
            ->assertDontSee('data-product-max="10"', false);

        $account = $this->createCustomerAccount('09120001100');
        $order = app(StoreCheckout::class)->create($account, [$product->slug => 10], (string) Str::uuid());
        $this->assertSame(10, $order->lines()->sole()->quantity);

        $cartAfter10 = $this->get(route('cart.index'))->assertOk();
        $this->assertSame(100, $cartAfter10->viewData('available')[$product->slug]);

        $this->withHeaders(['X-Storefront-Cart-State' => '1'])
            ->postJson(route('cart.update', $product), [
                'cart_context' => $add10->json('proof'),
                'cart_revision' => $add10->json('revision'),
                'quantity' => 50,
            ])
            ->assertOk()
            ->assertJsonPath('count', 50)
            ->assertJsonPath('total', 4_250_000);

        $fresh = $this->get(route('cart.index'))->assertOk();
        $this->withHeaders(['X-Storefront-Cart-State' => '1'])
            ->postJson(route('cart.update', $product), [
                'cart_context' => $fresh->viewData('proof'),
                'cart_revision' => $fresh->viewData('revision'),
                'quantity' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('count', 100)
            ->assertJsonPath('total', 8_500_000);
    }

    public function test_repeated_quantity_updates_up_and_down_never_double_count_stock(): void
    {
        [$product] = $this->createStockedProduct('paper-roll-stepper', 100, false, 85_000);
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('b', 64));

        $cart = $this->get(route('cart.index'))->assertOk();
        $res = $this->withHeaders(['X-Storefront-Cart-State' => '1'])
            ->postJson(route('cart.add', $product), [
                'cart_context' => $cart->viewData('proof'),
                'cart_revision' => $cart->viewData('revision'),
                'quantity' => 1,
            ])
            ->assertOk();

        $proof = $res->json('proof');
        $revision = $res->json('revision');

        foreach ([10, 25, 12, 100, 5] as $targetQty) {
            $step = $this->withHeaders(['X-Storefront-Cart-State' => '1'])
                ->postJson(route('cart.update', $product), [
                    'cart_context' => $proof,
                    'cart_revision' => $revision,
                    'quantity' => $targetQty,
                ])
                ->assertOk()
                ->assertJsonPath('count', $targetQty)
                ->assertJsonPath('quantities.'.$product->slug, $targetQty)
                ->assertJsonPath('available.'.$product->slug, 100)
                ->assertJsonPath('total', 85_000 * $targetQty);

            $proof = $step->json('proof');
            $revision = $step->json('revision');
        }

        $this->assertSame([$product->slug => 5], $this->get(route('cart.index'))->assertOk()->viewData('quantity'));
    }

    public function test_hidden_cart_item_does_not_double_count_against_stock_and_guest_to_account_add_accumulates(): void
    {
        [$product] = $this->createStockedProduct('roll-hidden-check', 100, false, 85_000);
        $device = str_repeat('c', 64);
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, $device);

        // Guest adds 2 units on device
        $guestCart = $this->get(route('cart.index'))->assertOk();
        $this->postJson(route('cart.add', $product), [
            'cart_context' => $guestCart->viewData('proof'),
            'cart_revision' => $guestCart->viewData('revision'),
            'quantity' => 2,
        ])->assertOk()->assertJsonPath('count', 2);

        // User logs in and adds 8 more -> combined visible quantity becomes 10
        $account = $this->createCustomerAccount('09120002200');
        $this->actingAs($account, 'customer')->withSession(['customer_session_version' => $account->session_version]);
        $loggedInCart = $this->get(route('cart.index'))->assertOk();
        $afterAdd8 = $this->withHeaders(['X-Storefront-Cart-State' => '1'])
            ->postJson(route('cart.add', $product), [
                'cart_context' => $loggedInCart->viewData('proof'),
                'cart_revision' => $loggedInCart->viewData('revision'),
                'quantity' => 8,
            ])
            ->assertOk()
            ->assertJsonPath('count', 10);

        // User hides/removes item from combined view, then adds 95 units -> must succeed (not 10 + 95 = 105!)
        $afterHide = $this->withHeaders(['X-Storefront-Cart-State' => '1'])
            ->postJson(route('cart.remove', $product->slug), [
                'cart_context' => $afterAdd8->json('proof'),
                'cart_revision' => $afterAdd8->json('revision'),
                'scope' => 'hide',
            ])
            ->assertOk()
            ->assertJsonPath('count', 0);

        $this->withHeaders(['X-Storefront-Cart-State' => '1'])
            ->postJson(route('cart.add', $product), [
                'cart_context' => $afterHide->json('proof'),
                'cart_revision' => $afterHide->json('revision'),
                'quantity' => 95,
            ])
            ->assertOk()
            ->assertJsonPath('count', 95)
            ->assertJsonPath('quantities.'.$product->slug, 95);
    }

    public function test_true_out_of_stock_and_serialized_cap_reject_excess_quantities(): void
    {
        [$paperRoll] = $this->createStockedProduct('roll-boundary', 100, false, 85_000);
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('d', 64));

        $cart = $this->get(route('cart.index'))->assertOk();
        $this->postJson(route('cart.add', $paperRoll), [
            'cart_context' => $cart->viewData('proof'),
            'cart_revision' => $cart->viewData('revision'),
            'quantity' => 101,
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'موجودی برای این تعداد کافی نیست.');

        $this->assertSame([], $this->get(route('cart.index'))->assertOk()->viewData('quantity'));

        [$serializedDevice] = $this->createStockedProduct('pos-serialized-cap', 120, true, 5_000_000);
        $cart2 = $this->get(route('cart.index'))->assertOk();
        $this->postJson(route('cart.add', $serializedDevice), [
            'cart_context' => $cart2->viewData('proof'),
            'cart_revision' => $cart2->viewData('revision'),
            'quantity' => 101,
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'موجودی برای این تعداد کافی نیست.');
    }

    private function createCustomerAccount(string $phone): CustomerAccount
    {
        $account = new CustomerAccount;
        $account->forceFill([
            'public_id' => (string) Str::uuid(),
            'phone' => $phone,
            'phone_digest' => RepairIdentity::digest('customer-account-phone', $phone),
            'verified_at' => now(),
        ])->save();

        return $account->refresh();
    }

    /**
     * @return array{0: Product, 1: InventoryItem}
     */
    private function createStockedProduct(string $slug, int $quantity, bool $serialized, int $price): array
    {
        $product = Product::query()->create([
            'name' => 'کالای تست '.$slug,
            'slug' => $slug,
            'category' => $serialized ? ProductCategory::PosTerminal : ProductCategory::Consumable,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'is_published' => true,
            'is_sample' => false,
            'online_purchasing_enabled' => true,
            'price' => $price,
            'currency' => 'TOMAN',
        ]);

        $owner = User::query()->first() ?? User::factory()->superAdmin()->create();
        $commerce = app(CommerceManagement::class);
        $itemId = $commerce->execute($owner, 'item.create', [
            'operation_key' => (string) Str::uuid(),
            'sku' => 'SKU-'.strtoupper($slug),
            'name' => $product->name,
            'condition' => ProductCondition::New->value,
            'serialized' => $serialized,
            'product_id' => $product->id,
            'low_stock_threshold' => 0,
            'reason' => 'Stock audit test fixture',
        ]);
        $item = InventoryItem::query()->findOrFail($itemId);

        if ($serialized) {
            for ($offset = 0; $offset < $quantity; $offset += 100) {
                $batch = min(100, $quantity - $offset);
                $serials = [];
                for ($i = 1; $i <= $batch; $i++) {
                    $serials[] = sprintf('SN-%s-%04d', strtoupper(substr($slug, 0, 6)), $offset + $i);
                }
                $commerce->execute($owner, 'item.receive', [
                    'operation_key' => (string) Str::uuid(),
                    'version' => $item->fresh()->version,
                    'quantity' => $batch,
                    'serials' => implode("\n", $serials),
                    'reason' => 'Serialized stock batch',
                ], $item->fresh());
            }
        } else {
            $commerce->execute($owner, 'item.receive', [
                'operation_key' => (string) Str::uuid(),
                'version' => $item->version,
                'quantity' => $quantity,
                'reason' => 'Initial test stock',
            ], $item);
        }

        return [$product->fresh(), $item->fresh()];
    }
}
