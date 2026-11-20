<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CommerceManagement;
use App\Services\ShoppingCart;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersistentCartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        URL::forceRootUrl('https://novinpaypos.test');
        URL::forceScheme('https');
    }

    public function test_host_only_device_cookie_and_server_side_cart_survive_a_new_session(): void
    {
        $product = $this->stock('terminal');
        config(['session.domain' => '.novinpaypos.test']);
        $response = $this->get(route('cart.index'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $cookie = $response->getCookie(ShoppingCart::DEVICE_COOKIE, false);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->withUnencryptedCookie(ShoppingCart::DEVICE_COOKIE, $cookie->getValue());
        $this->add($product, 2);
        session()->flush();
        $this->assertSame(['terminal' => 2], $this->get(route('cart.index'))->assertOk()->viewData('quantity'));
    }

    public function test_combined_cart_uses_maximum_then_preserves_each_source_and_applies_explicit_exclusion(): void
    {
        $product = $this->stock('shared');
        $device = str_repeat('c', 64);
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, $device);
        $this->add($product, 2);
        $owner = $this->account('09000000001');
        $this->actingAs($owner, 'customer')->withSession(['customer_session_version' => $owner->session_version]);

        $this->withCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('b', 64));
        $this->add($product, 3);
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, $device);
        $cart = $this->get(route('cart.index'))->assertOk();
        $this->assertSame(['shared' => 3], $cart->viewData('quantity'));
        $this->assertSame(2, $cart->viewData('items')['shared']['device']['quantity']);
        $this->assertSame(3, $cart->viewData('items')['shared']['account']['quantity']);

        $this->postJson(route('cart.remove', $product->slug), $this->fields($cart) + ['scope' => 'hide'])
            ->assertOk()->assertExactJson(['count' => 0]);
        $hidden = $this->get(route('cart.index'))->assertOk();
        $this->assertSame(['shared'], $hidden->viewData('excluded'));
        $this->add($product, 1);
        $new = $this->get(route('cart.index'))->assertOk();
        $this->assertSame(['shared' => 1], $new->viewData('quantity'));
        $this->assertSame(4, $new->viewData('items')['shared']['account']['quantity']);
        $this->assertSame(2, $new->viewData('items')['shared']['device']['quantity']);
        $this->postJson(route('cart.remove', $product->slug), $this->fields($new) + ['scope' => 'hide'])->assertOk();
        $hidden = $this->get(route('cart.index'))->assertOk();

        $other = $this->account('09000000002');
        $this->actingAs($other, 'customer')->withSession(['customer_session_version' => $other->session_version]);
        $this->assertSame(['shared' => 2], $this->get(route('cart.index'))->viewData('quantity'));
        $this->postJson(route('cart.remove', $product->slug), $this->fields($hidden) + ['scope' => 'account'])->assertConflict();
        $this->actingAs($owner, 'customer')->withSession(['customer_session_version' => $owner->session_version]);
        $hidden = $this->get(route('cart.index'))->assertOk();
        $this->assertSame([], $hidden->viewData('quantity'));
        $this->postJson(route('cart.remove', $product->slug), $this->fields($hidden) + ['scope' => 'restore'])
            ->assertOk()->assertExactJson(['count' => 4]);

        $this->post(route('account.logout'))->assertRedirect();
        $this->assertSame(['shared' => 2], $this->get(route('cart.index'))->viewData('quantity'));
    }

    public function test_stale_quantity_form_and_unavailable_stock_do_not_mutate_current_cart(): void
    {
        $product = $this->stock('concurrent');
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('a', 64));
        $this->add($product, 1);
        $previous = $this->get(route('cart.index'))->assertOk();
        $this->add($product, 1);
        $this->postJson(route('cart.update', $product), $this->fields($previous) + ['quantity' => 1])->assertConflict();
        $product->update(['stock_status' => 'out_of_stock']);
        $this->postJson(route('cart.add', $product), $this->fields($previous) + ['quantity' => 1])->assertConflict();
        $cart = $this->get(route('cart.index'))->assertOk();
        $this->assertSame(['concurrent' => 2], $cart->viewData('quantity'));
        $this->assertSame(['concurrent'], $cart->viewData('unavailable'));
    }

    public function test_legacy_cart_is_retained_without_showing_its_items_to_another_account(): void
    {
        $legacy = ['unknown-owner-variant' => 2];
        $this->withSession(['store_cart' => $legacy]);
        $this->get(route('cart.index'))->assertOk()->assertDontSee('unknown-owner-variant')->assertSee('سبد قبلی');
        $this->assertSame($legacy, session('store_cart'));
        $this->assertDatabaseCount('shopping_carts', 0);
        $account = $this->account('09000000004');
        $this->actingAs($account, 'customer')->withSession(['customer_session_version' => $account->session_version]);
        $this->get(route('cart.index'))->assertOk()->assertDontSee('unknown-owner-variant');
        $this->post(route('account.logout'))->assertRedirect();
        $this->assertSame($legacy, session('store_cart'));
        $hold = DB::table('legacy_cart_holds')->sole();
        $this->assertStringNotContainsString('unknown-owner-variant', $hold->payload);
        $this->assertSame($legacy, decrypt($hold->payload));
    }

    public function test_migration_refuses_to_drop_saved_selections(): void
    {
        $migration = require database_path('migrations/2026_11_19_000001_create_shopping_carts.php');
        DB::table('shopping_carts')->insert(['scope' => str_repeat('c', 64), 'state' => '{"terminal":{"quantity":1}}']);
        try {
            $migration->down();
            $this->fail('Migration dropped a customer selection');
        } catch (\LogicException) {
            $this->assertTrue(Schema::hasTable('shopping_carts'));
            $this->assertDatabaseCount('shopping_carts', 1);
        }
    }

    public function test_empty_migration_can_be_rolled_back_and_reapplied(): void
    {
        $migration = require database_path('migrations/2026_11_19_000001_create_shopping_carts.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('shopping_carts'));
        $this->assertFalse(Schema::hasTable('cart_purchase_snapshots'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('cart_purchase_snapshots'));
    }

    public function test_legacy_claim_uses_the_original_active_session_and_keeps_every_selection_once(): void
    {
        $product = $this->stock('legacy-live');
        $legacy = [$product->slug => 3, 'legacy-withdrawn' => 2];
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('f', 64));
        $this->add($product, 1);
        $this->withSession(['store_cart' => $legacy]);
        $first = $this->get(route('cart.index'))->assertOk()->assertDontSee('انتقال سبد قبلی به این دستگاه');
        $this->postJson(route('cart.legacy.claim'), $this->fields($first))->assertConflict();

        $name = config('session.cookie');
        $this->withUnencryptedCookie($name, $first->getCookie($name, false)->getValue());
        $confirmed = $this->get(route('cart.index'))->assertOk()->assertSee('انتقال سبد قبلی به این دستگاه');
        $this->postJson(route('cart.legacy.claim'), $this->fields($confirmed))->assertOk()->assertExactJson(['count' => 5]);
        $this->postJson(route('cart.legacy.claim'), $this->fields($confirmed))->assertOk()->assertExactJson(['count' => 5]);
        $this->assertSame($legacy, $this->get(route('cart.index'))->viewData('quantity'));
        $this->assertSame(['legacy-withdrawn'], $this->get(route('cart.index'))->viewData('unavailable'));
        $hold = DB::table('legacy_cart_holds')->sole();
        $this->assertNotNull($hold->migrated_at);
        $this->assertSame($legacy, decrypt($hold->payload));
        $this->assertSame($legacy, session('store_cart'));

        $one = $this->account('09000000006');
        $two = $this->account('09000000007');
        $this->actingAs($one, 'customer')->withSession(['customer_session_version' => $one->session_version]);
        $data = $this->get(route('cart.index'))->viewData('items');
        $this->assertNull($data[$product->slug]['account']);
        $this->actingAs($two, 'customer')->withSession(['customer_session_version' => $two->session_version]);
        $data = $this->get(route('cart.index'))->viewData('items');
        $this->assertSame(3, $data[$product->slug]['device']['quantity']);
        $this->assertNull($data[$product->slug]['account']);
    }

    public function test_legacy_from_a_rotated_session_stays_held_and_cannot_be_claimed_with_the_new_cookie(): void
    {
        $legacy = ['old-private' => 4];
        $one = $this->account('09000000008');
        $this->actingAs($one, 'customer')->withSession(['customer_session_version' => $one->session_version, 'store_cart' => $legacy]);
        $name = config('session.cookie');
        $this->withCookie($name, session()->getId())->withCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('b', 64));
        $result = $this->post(route('account.logout'))->assertRedirect();
        $this->withUnencryptedCookie($name, $result->getCookie($name, false)->getValue());
        $other = $this->account('09000000009');
        $this->actingAs($other, 'customer')->withSession(['customer_session_version' => $other->session_version]);
        $cart = $this->get(route('cart.index'))->assertOk()->assertDontSee('old-private');
        $this->assertSame('held', $cart->viewData('legacyStatus'));
        $this->postJson(route('cart.legacy.claim'), $this->fields($cart))->assertConflict();
        $this->assertSame($legacy, decrypt(DB::table('legacy_cart_holds')->sole()->payload));
        $this->assertDatabaseCount('shopping_carts', 0);
    }

    public function test_quantity_step_buttons_submit_valid_scoped_values_and_respect_stock(): void
    {
        $product = $this->stock('stepper-variant');
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('e', 64));
        $this->add($product, 1);
        $cart = $this->get(route('cart.index'))->assertOk()->assertSee('قیمت واحد')->assertSee('افزایش تعداد');
        $document = new \DOMDocument;
        @$document->loadHTML($cart->getContent());
        $xpath = new \DOMXPath($document);
        $buttons = $xpath->query('//form[contains(@class,"commerce-stepper")]/button');
        $this->assertSame(2, $buttons->length);
        $this->assertTrue($buttons->item(0)->hasAttribute('disabled'));
        $this->assertFalse($buttons->item(1)->hasAttribute('disabled'));
        $this->assertSame('2', $buttons->item(1)->getAttribute('value'));
        $this->post(route('cart.update', $product), $this->fields($cart) + ['quantity' => $buttons->item(1)->getAttribute('value')])->assertRedirect();
        $this->assertSame(['stepper-variant' => 2], $this->get(route('cart.index'))->assertOk()->viewData('quantity'));
    }

    private function fields($response): array
    {
        return ['cart_context' => $response->viewData('proof'), 'cart_revision' => $response->viewData('revision')];
    }

    private function add(Product $product, int $quantity): void
    {
        $cart = $this->get(route('cart.index'))->assertOk();
        $this->post(route('cart.add', $product), $this->fields($cart) + ['quantity' => $quantity])->assertRedirect(route('cart.index'));
    }

    private function account(string $phone): CustomerAccount
    {
        $account = new CustomerAccount;
        $account->forceFill(['public_id' => (string) Str::uuid(), 'phone' => $phone,
            'phone_digest' => RepairIdentity::digest('customer-account-phone', $phone), 'verified_at' => now()])->save();

        return $account->refresh();
    }

    private function stock(string $slug): Product
    {
        $product = Product::create(['name' => 'Synthetic variant', 'slug' => $slug, 'category' => 'pos_terminal',
            'is_published' => true, 'condition' => 'used', 'stock_status' => 'in_stock', 'online_purchasing_enabled' => true, 'price' => 10000]);
        $owner = User::factory()->superAdmin()->create();
        $commerce = app(CommerceManagement::class);
        $id = $commerce->execute($owner, 'item.create', ['operation_key' => (string) Str::uuid(),
            'sku' => 'CART-'.strtoupper($slug), 'name' => 'Synthetic stock', 'condition' => 'used', 'serialized' => false,
            'product_id' => $product->id, 'low_stock_threshold' => 0, 'reason' => 'Isolated cart fixture']);
        $item = InventoryItem::findOrFail($id);
        $commerce->execute($owner, 'item.receive', ['operation_key' => (string) Str::uuid(), 'version' => $item->version,
            'quantity' => 8, 'reason' => 'Isolated cart fixture'], $item);

        return $product;
    }
}
