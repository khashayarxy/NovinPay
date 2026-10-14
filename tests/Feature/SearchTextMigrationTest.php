<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Models\Product;
use App\Support\SearchNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchTextMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_10_14_000001_add_search_text_to_products_table.php'
        );
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'دستگاه کارتخوان نمونه',
            'slug' => 'migration-product-'.uniqid(),
            'summary' => 'خلاصهٔ توضیحات کالا',
            'description' => 'توضیحات کامل کالا برای نمایش در صفحهٔ جزئیات.',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'price' => 1200000,
            'stock_status' => StockStatus::InStock,
        ], $overrides));
    }

    public function test_migration_backfills_search_text_for_existing_products(): void
    {
        // Pre-migration data: rows exist but search_text is NULL (inserted
        // via DB::table so the model's saving hook is deliberately bypassed).
        DB::table('products')->insert([
            [
                'name' => 'پوز مدل 1200',
                'slug' => 'backfill-pos-1200',
                'summary' => 'خلاصهٔ پوز مدل ۱۲۰۰',
                'description' => 'توضیحات پوز مدل 1200.',
                'category' => ProductCategory::PosTerminal->value,
                'condition' => ProductCondition::New->value,
                'price' => 100,
                'stock_status' => StockStatus::InStock->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'رول کاغذ ۵۷',
                'slug' => 'backfill-paper-57',
                'summary' => null,
                'description' => null,
                'category' => ProductCategory::Consumable->value,
                'condition' => ProductCondition::New->value,
                'price' => null,
                'stock_status' => StockStatus::InStock->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->migration()->up();

        $this->assertSame(
            SearchNormalizer::forProduct('پوز مدل 1200', 'خلاصهٔ پوز مدل ۱۲۰۰', 'توضیحات پوز مدل 1200.'),
            DB::table('products')->where('slug', 'backfill-pos-1200')->value('search_text'),
        );
        $this->assertSame(
            SearchNormalizer::forProduct('رول کاغذ ۵۷', null, null),
            DB::table('products')->where('slug', 'backfill-paper-57')->value('search_text'),
        );
        $this->assertSame(0, DB::table('products')->whereNull('search_text')->count());
    }

    public function test_migration_recovers_from_an_incomplete_backfill(): void
    {
        $intact = $this->makeProduct(['name' => 'کارتخوان سیار', 'slug' => 'intact-1']);
        $broken = $this->makeProduct(['name' => 'رول کاغذ ۵۷', 'slug' => 'broken-1']);

        // Simulate a partial failure: one row was written before the run
        // died; the rest were left NULL. A sentinel on the intact row proves
        // already-written values are preserved, not recomputed.
        DB::table('products')->where('id', $intact->id)->update(['search_text' => 'SENTINEL-KEEP']);
        DB::table('products')->where('id', $broken->id)->update(['search_text' => null]);

        // Retrying the migration must repair NULL rows without touching
        // written ones, and must be safe to run again.
        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('SENTINEL-KEEP', DB::table('products')->where('id', $intact->id)->value('search_text'));
        $this->assertSame(
            SearchNormalizer::forProduct($broken->name, $broken->summary, $broken->description),
            DB::table('products')->where('id', $broken->id)->value('search_text'),
        );
    }
}
