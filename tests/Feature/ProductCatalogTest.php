<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Models\Product;
use Database\Seeders\SampleProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'دستگاه کارتخوان نمونه',
            'slug' => 'test-product-'.uniqid(),
            'summary' => 'خلاصهٔ توضیحات کالا',
            'description' => 'توضیحات کامل کالا برای نمایش در صفحهٔ جزئیات.',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'price' => 1200000,
            'stock_status' => StockStatus::InStock,
            'image_path' => null,
            'is_sample' => true,
            'specifications' => ['نوع اتصال' => 'شبکه'],
        ], $overrides));
    }

    public function test_products_page_shows_empty_state_when_catalog_is_empty(): void
    {
        $this->get('/products')
            ->assertOk()
            ->assertSee('هنوز محصولی ثبت نشده است');
    }

    public function test_home_page_shows_empty_state_when_there_are_no_products(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('هنوز محصولی ثبت نشده است');
    }

    public function test_products_page_lists_product_details_and_sample_badge(): void
    {
        $this->makeProduct();

        $response = $this->get('/products');

        $response->assertOk();
        $response->assertSee('دستگاه کارتخوان نمونه');
        $response->assertSee('۱,۲۰۰,۰۰۰');
        $response->assertSee('موجود');
        $response->assertSee('نو');
        $response->assertSee('داده نمونه');
    }

    public function test_home_page_shows_featured_products(): void
    {
        $this->makeProduct(['name' => 'کالای شاخص']);

        $this->get('/')
            ->assertOk()
            ->assertSee('کالای شاخص')
            ->assertDontSee('هنوز محصولی ثبت نشده است');
    }

    public function test_search_filters_products_by_keyword(): void
    {
        $this->makeProduct(['name' => 'پرینتر حرارتی', 'slug' => 'printer-1']);
        $this->makeProduct(['name' => 'کیف محافظ', 'slug' => 'case-1']);

        $response = $this->get('/products?q='.urlencode('پرینتر'));

        $response->assertOk();
        $response->assertSee('پرینتر حرارتی');
        $response->assertDontSee('کیف محافظ');
    }

    public function test_category_filter_returns_only_matching_products(): void
    {
        $this->makeProduct(['category' => ProductCategory::Printer, 'name' => 'پرینتر رسید', 'slug' => 'printer-2']);
        $this->makeProduct(['category' => ProductCategory::Consumable, 'name' => 'رول کاغذ', 'slug' => 'paper-1']);

        $response = $this->get('/products?category='.ProductCategory::Printer->value);

        $response->assertOk();
        $response->assertSee('پرینتر رسید');
        $response->assertDontSee('رول کاغذ');
    }

    public function test_invalid_category_is_rejected_with_persian_message(): void
    {
        $this->from('/products')
            ->get('/products?category=unknown')
            ->assertRedirect('/products')
            ->assertSessionHasErrors('category');

        $this->assertStringContainsString('معتبر نیست', session('errors')->first('category'));
    }

    public function test_product_detail_page_shows_description_and_specifications(): void
    {
        $product = $this->makeProduct();

        $response = $this->get(route('products.show', $product));

        $response->assertOk();
        $response->assertSee('دستگاه کارتخوان نمونه');
        $response->assertSee('توضیحات کامل کالا');
        $response->assertSee('نوع اتصال');
        $response->assertSee('شبکه');
    }

    public function test_unknown_product_slug_returns_404(): void
    {
        $this->get('/products/does-not-exist')->assertNotFound();
    }

    public function test_used_product_and_stock_states_are_visible(): void
    {
        $product = $this->makeProduct([
            'condition' => ProductCondition::Used,
            'stock_status' => StockStatus::OutOfStock,
            'price' => null,
        ]);

        $response = $this->get(route('products.show', $product));

        $response->assertOk();
        $response->assertSee('کارکرده');
        $response->assertSee('ناموجود');
        $response->assertSee('تماس بگیرید');
    }

    public function test_sample_seeder_creates_clearly_labelled_rows(): void
    {
        $this->seed(SampleProductSeeder::class);

        $this->assertTrue(Product::query()->where('slug', 'sample-pos-fixed')->first()->is_sample);

        $this->get('/products')
            ->assertOk()
            ->assertSee('داده نمونه');
    }

    public function test_unpublished_products_are_hidden_from_listing(): void
    {
        $this->makeProduct(['name' => 'محصول منتشرشده', 'slug' => 'visible-1']);
        $this->makeProduct(['name' => 'محصول پیش‌نویس', 'slug' => 'hidden-1', 'is_published' => false]);

        $this->get('/products')
            ->assertOk()
            ->assertSee('محصول منتشرشده')
            ->assertDontSee('محصول پیش‌نویس');
    }

    public function test_unpublished_products_are_hidden_from_search(): void
    {
        $this->makeProduct(['name' => 'پرینتر مخفی', 'slug' => 'hidden-printer', 'is_published' => false]);

        $this->get('/products?q='.urlencode('پرینتر'))
            ->assertOk()
            ->assertDontSee('پرینتر مخفی');
    }

    public function test_unpublished_products_are_hidden_from_home_page(): void
    {
        $this->makeProduct(['name' => 'محصول پیش‌نویس در خانه', 'slug' => 'hidden-home', 'is_published' => false]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('محصول پیش‌نویس در خانه')
            ->assertSee('هنوز محصولی ثبت نشده است');
    }

    public function test_unpublished_product_detail_returns_404(): void
    {
        $product = $this->makeProduct(['is_published' => false]);

        $this->get(route('products.show', $product))->assertNotFound();
    }

    public function test_public_pages_do_not_link_to_admin_panel(): void
    {
        $this->get('/products')->assertDontSee('/admin', false);
        $this->get('/')->assertDontSee('/admin', false);
    }
}
