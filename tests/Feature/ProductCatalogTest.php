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

    public function test_search_matches_persian_and_ascii_digits_interchangeably(): void
    {
        // Stored with ASCII digits; a shopper typing Persian digits must still match.
        $this->makeProduct(['name' => 'پوز مدل 1200', 'slug' => 'pos-1200']);

        $this->get('/products?q='.urlencode('۱۲۰۰'))
            ->assertOk()
            ->assertSee('پوز مدل 1200');

        // And the reverse: stored with Persian digits, searched with ASCII.
        $this->makeProduct(['name' => 'رول کاغذ ۵۷', 'slug' => 'paper-57']);

        $this->get('/products?q='.urlencode('57'))
            ->assertOk()
            ->assertSee('رول کاغذ ۵۷');
    }

    public function test_search_matches_persian_and_arabic_letter_variants(): void
    {
        // Stored with Persian kaf (ک); a shopper typing Arabic kaf (ك) must match.
        $this->makeProduct(['name' => 'کارتخوان سیار', 'slug' => 'pos-movable']);

        $this->get('/products?q='.urlencode('كارتخوان'))
            ->assertOk()
            ->assertSee('کارتخوان سیار');

        // Stored with Arabic yeh (ي); a shopper typing Persian yeh (ی) must match.
        $this->makeProduct(['name' => 'رسيد حرارتي', 'slug' => 'receipt-1']);

        $this->get('/products?q='.urlencode('رسید'))
            ->assertOk()
            ->assertSee('رسيد حرارتي');
    }

    public function test_search_still_ignores_unpublished_products_after_normalization(): void
    {
        $this->makeProduct(['name' => 'کارتخوان مخفی', 'slug' => 'hidden-pos', 'is_published' => false]);

        $this->get('/products?q='.urlencode('كارتخوان'))
            ->assertOk()
            ->assertDontSee('کارتخوان مخفی');
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $this->makeProduct(['name' => 'تخفیف 50%', 'slug' => 'discount-percent']);
        $this->makeProduct(['name' => 'کالای دیگر', 'slug' => 'other-item']);

        // '%' must not act as a match-everything wildcard: only the product
        // that literally contains '%' may match, not every row.
        $this->get('/products?q='.urlencode('%'))
            ->assertOk()
            ->assertSee('تخفیف 50%')
            ->assertDontSee('کالای دیگر');

        // '50%' must match the literal string only.
        $this->get('/products?q='.urlencode('50%'))
            ->assertOk()
            ->assertSee('تخفیف 50%')
            ->assertDontSee('کالای دیگر');

        // '_' must not act as a single-character wildcard.
        $this->makeProduct(['name' => 'کالای abc', 'slug' => 'abc-product']);
        $this->get('/products?q='.urlencode('a_c'))
            ->assertOk()
            ->assertDontSee('کالای abc');
    }

    public function test_search_treats_escape_character_literally(): void
    {
        $this->makeProduct(['name' => 'هشدار!', 'slug' => 'warning-bang']);

        $this->get('/products?q='.urlencode('!'))
            ->assertOk()
            ->assertSee('هشدار!')
            ->assertSee('جزئیات', false);
    }

    public function test_search_treats_backslash_literally(): void
    {
        // With ESCAPE '!' the backslash is an ordinary character in the
        // bound LIKE pattern on both SQLite and MySQL (parameter binding
        // bypasses MySQL string-literal escape processing).
        $this->makeProduct(['name' => 'مسیر C:\\Users\\test', 'slug' => 'backslash-path']);
        $this->makeProduct(['name' => 'کالای معمولی', 'slug' => 'plain-backslash-case']);

        $this->get('/products?q='.urlencode('C:\\Users'))
            ->assertOk()
            ->assertSee('مسیر C:\\Users\\test')
            ->assertDontSee('کالای معمولی');
    }

    public function test_search_matches_persian_words_across_zwnj_variants(): void
    {
        // Stored WITH ZWNJ (می‌شود); searched WITHOUT.
        $this->makeProduct(['name' => "دستگاه می\u{200C}شود", 'slug' => 'with-zwnj']);
        $this->get('/products?q='.urlencode('میشود'))
            ->assertOk()
            ->assertSee('دستگاه می‌شود');

        // Stored WITHOUT ZWNJ; searched WITH.
        $this->makeProduct(['name' => 'کالای میشود', 'slug' => 'without-zwnj']);
        $this->get('/products?q='.urlencode("می\u{200C}شود"))
            ->assertOk()
            ->assertSee('کالای میشود');
    }

    public function test_search_collapses_repeated_whitespace(): void
    {
        $this->makeProduct(['name' => 'کارتخوان  سیار', 'slug' => 'double-space']);

        $this->get('/products?q='.urlencode('کارتخوان سیار'))
            ->assertOk()
            ->assertSee('کارتخوان  سیار');
    }

    public function test_blank_search_term_shows_all_products(): void
    {
        $this->makeProduct(['name' => 'محصول الف', 'slug' => 'blank-q-a']);

        $this->get('/products?q=')->assertOk()->assertSee('محصول الف');
        $this->get('/products?q='.urlencode('   '))->assertOk()->assertSee('محصول الف');
    }

    public function test_pagination_preserves_search_and_category_query_parameters(): void
    {
        foreach (range(1, 13) as $i) {
            $this->makeProduct([
                'name' => "محصول شماره {$i}",
                'slug' => "paginated-{$i}",
                'category' => ProductCategory::Printer,
            ]);
        }

        $response = $this->get('/products?category=printer&q='.urlencode('محصول'));

        $response->assertOk();
        $response->assertSee('page=2', false);
        $response->assertSee('category=printer', false);
    }

    public function test_page_beyond_range_returns_empty_results_gracefully(): void
    {
        $this->makeProduct();

        $this->get('/products?page=99')->assertOk();
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

    public function test_unknown_product_slug_shows_persian_not_found_page(): void
    {
        $response = $this->get('/products/does-not-exist');

        $response->assertNotFound();
        $response->assertSee('صفحه پیدا نشد');
        $response->assertSee('خطای ۴۰۴');
        $response->assertSee(route('products.index'), false);
        $response->assertDontSee('Sorry', false);
        $response->assertDontSee('Not Found', false);
    }

    public function test_product_title_and_meta_description_are_escaped(): void
    {
        $product = $this->makeProduct([
            'name' => '</title><script>alert(1)</script>',
            'slug' => 'xss-title-product',
            'summary' => 'x" onmouseover="alert(2)',
        ]);

        $response = $this->get(route('products.show', $product));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $response->assertDontSee('onmouseover="alert(2)"', false);
    }

    public function test_home_featured_section_describes_live_pricing(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('به‌صورت روزآمد نمایش داده می‌شوند')
            ->assertDontSee('پس از تکمیل بخش مدیریت');
    }

    public function test_filter_validation_error_is_rendered_in_focusable_summary(): void
    {
        $this->from('/products')
            ->get('/products?category=unknown')
            ->assertRedirect('/products');

        $this->get('/products')
            ->assertOk()
            ->assertSee('id="error-summary"', false)
            ->assertSee('دسته‌بندی انتخاب‌شده معتبر نیست');
    }
}
