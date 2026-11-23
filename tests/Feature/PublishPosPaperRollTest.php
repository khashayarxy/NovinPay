<?php

namespace Tests\Feature;

use App\Console\Commands\PublishPosPaperRoll;
use App\Enums\AdminAction;
use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CommerceManagement;
use App\Services\StoreCheckout;
use App\Support\OrderMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublishPosPaperRollTest extends TestCase
{
    use RefreshDatabase;

    public function test_publication_lists_the_consumable_at_the_toman_price_with_audited_stock(): void
    {
        $owner = User::factory()->superAdmin()->create();

        $this->artisan('novinpay:publish-pos-paper-roll')->assertExitCode(2);
        $this->assertDatabaseCount('products', 0);

        $this->artisan('novinpay:publish-pos-paper-roll', ['--apply' => true])
            ->expectsOutput('POS_PAPER_ROLL_PUBLISHED')->assertSuccessful();

        $product = Product::query()->sole();
        $item = app(StoreCheckout::class)->offer($product);
        $this->assertSame('رول کاغذ کارتخوان', $product->name);
        $this->assertSame(ProductCategory::Consumable, $product->category);
        $this->assertSame(85_000, $product->price);
        $this->assertSame('TOMAN', $product->currency);
        $this->assertSame(850_000, intdiv(OrderMoney::minor((string) $product->price), 10));
        $this->assertSame(100, $item?->available);
        $this->assertFalse($item->serialized);

        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $item->id, 'actor_id' => $owner->id, 'kind' => 'receive', 'on_hand_delta' => 100,
        ]);
        $this->assertDatabaseHas('admin_activities', [
            'actor_id' => $owner->id, 'action' => AdminAction::ProductCreated->value,
            'target_type' => 'product', 'target_id' => $product->id,
        ]);
        $this->get(route('products.index', ['category' => 'consumable']))
            ->assertOk()->assertSee('رول کاغذ کارتخوان');
        $this->get(route('products.show', $product))
            ->assertOk()->assertSee('۸۵٬۰۰۰')->assertSee('افزودن به سبد خرید');

        app(CommerceManagement::class)->execute($owner, 'item.adjust', [
            'operation_key' => (string) Str::uuid(), 'version' => $item->version, 'quantity' => -1,
            'reason' => 'Stock adjustment after first publication',
        ], $item);
        $product->update(['price' => 84_000]);

        $this->artisan('novinpay:publish-pos-paper-roll', ['--apply' => true])
            ->expectsOutput('POS_PAPER_ROLL_ALREADY_PUBLISHED')->assertSuccessful();
        $this->assertSame(99, $item->fresh()->available);
        $this->assertSame(84_000, $product->fresh()->price);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_publication_refuses_to_claim_an_existing_catalog_slug(): void
    {
        User::factory()->superAdmin()->create();
        $existing = Product::create([
            'name' => 'Existing product',
            'slug' => PublishPosPaperRoll::SLUG,
            'category' => ProductCategory::Consumable,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::OutOfStock,
            'price' => 90_000,
        ]);

        $this->artisan('novinpay:publish-pos-paper-roll', ['--apply' => true])
            ->expectsOutput('POS_PAPER_ROLL_IDENTITY_CONFLICT')->assertFailed();

        $this->assertSame(90_000, $existing->fresh()->price);
        $this->assertDatabaseCount((new InventoryItem)->getTable(), 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
