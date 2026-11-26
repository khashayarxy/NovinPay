<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Models\AdminAccount;
use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use App\Services\ShoppingCart;
use App\Support\GuestPageCache;
use App\Support\ProductMediaPublisher;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase3PerformanceAndCachingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $cleanupPublicMedia = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'app.url' => 'https://novinpaypos.test',
            'payments.test_product.slug' => 'zibal-gateway-test-5000',
            'payments.test_product.allowed_phones' => ['09123456789'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupPublicMedia as $relativePath) {
            ProductMediaPublisher::removePublishedImage($relativePath);
        }

        parent::tearDown();
    }

    public function test_guest_page_cache_serves_anonymous_home_and_products_with_zero_queries_on_hit_and_no_cookies(): void
    {
        $product = $this->createPublicProduct('کارتخوان سیار نیولند', 'newland-me31', 5_900_000, 8);
        GuestPageCache::bump();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $missHome = $this->get('/');
        $missHome->assertOk()
            ->assertHeader('X-Guest-Cache', 'MISS')
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, private')
            ->assertHeader('X-LiteSpeed-Cache-Control', 'no-cache')
            ->assertSee('data-cart-empty-guest="1"', false)
            ->assertSee('نوین‌پی');
        $this->assertSame([], $missHome->headers->getCookies());

        $missQueries = collect(DB::getQueryLog())->pluck('query')->all();
        foreach ($missQueries as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('shopping_carts', $sql);
        }

        DB::flushQueryLog();

        $hitHome = $this->get('/');
        $hitHome->assertOk()
            ->assertHeader('X-Guest-Cache', 'HIT')
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, private')
            ->assertSee('نوین‌پی');
        $this->assertSame([], $hitHome->headers->getCookies());
        $this->assertCount(0, DB::getQueryLog(), 'Guest page cache HIT must execute zero DB queries.');

        $missProducts = $this->get('/products');
        $missProducts->assertOk()
            ->assertHeader('X-Guest-Cache', 'MISS')
            ->assertSee($product->name);

        DB::flushQueryLog();
        $hitProducts = $this->get('/products');
        $hitProducts->assertOk()
            ->assertHeader('X-Guest-Cache', 'HIT')
            ->assertSee($product->name);
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_guest_page_cache_strictly_bypasses_cart_cookie_session_cookie_authenticated_users_and_protected_routes(): void
    {
        $this->createPublicProduct('کارتخوان پکس S910', 'pax-s910', 4_500_000, 5);

        // Warm anonymous cache first
        $this->get('/')->assertOk()->assertHeader('X-Guest-Cache', 'MISS');
        $this->get('/products')->assertOk()->assertHeader('X-Guest-Cache', 'MISS');

        // 1. Active cart cookie must bypass 100%
        $withCartCookie = $this->withUnencryptedCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('a', 64))->get('/');
        $withCartCookie->assertOk()->assertHeaderMissing('X-Guest-Cache');

        $productsWithCartCookie = $this->withUnencryptedCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('b', 64))->get('/products');
        $productsWithCartCookie->assertOk()->assertHeaderMissing('X-Guest-Cache');

        // 2. Active session cookie must bypass 100%
        $withSessionCookie = $this->withUnencryptedCookie((string) config('session.cookie'), 'active-session-id')->get('/');
        $withSessionCookie->assertOk()->assertHeaderMissing('X-Guest-Cache');

        // 3. Authenticated customer must bypass 100%
        $customer = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09120001122'),
            'phone' => '09120001122',
            'verified_at' => now(),
        ])->fresh();
        $authedHome = $this->actingAs($customer, 'customer')
            ->withSession(['customer_session_version' => $customer->session_version])
            ->get('/');
        $authedHome->assertOk()
            ->assertHeaderMissing('X-Guest-Cache')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('data-customer-state="authenticated"', false);

        Auth::forgetGuards();
        $this->flushSession();

        // Guest storefront product detail & comparison get bfcache-friendly Cache-Control
        $this->get('/products/pax-s910')
            ->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, private');
        $this->get('/product-comparison')
            ->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, private');

        // 4. Non-whitelisted routes (/cart, /repair, /login, /admin/login, /account) never use guest cache and keep strict no-store
        foreach (['/cart', '/repair', '/login', '/admin/login'] as $route) {
            $res = $this->get($route);
            $res->assertOk()->assertHeaderMissing('X-Guest-Cache');
            $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control', ''));
        }
    }

    public function test_guest_page_cache_invalidates_immediately_on_product_price_visibility_or_inventory_updates(): void
    {
        [$product, $inventory] = $this->createPublicProductWithInventory('کارتخوان آنفو AF70', 'anfu-af70', 6_100_000, 4);

        $this->get('/products')
            ->assertOk()
            ->assertHeader('X-Guest-Cache', 'MISS')
            ->assertSee('۶٬۱۰۰٬۰۰۰');

        $this->get('/products')
            ->assertOk()
            ->assertHeader('X-Guest-Cache', 'HIT');

        // Price update immediately invalidates cache
        $product->update(['price' => 6_450_000]);

        $afterPrice = $this->get('/products');
        $afterPrice->assertOk()
            ->assertHeader('X-Guest-Cache', 'MISS')
            ->assertSee('۶٬۴۵۰٬۰۰۰')
            ->assertDontSee('۶٬۱۰۰٬۰۰۰');

        // Warm again, then deplete inventory -> immediate invalidation
        $this->get('/products')->assertOk()->assertHeader('X-Guest-Cache', 'HIT');

        $inventory->forceFill(['on_hand' => 0, 'reserved' => 0])->save();

        $afterStock = $this->get('/products');
        $afterStock->assertOk()
            ->assertHeader('X-Guest-Cache', 'MISS')
            ->assertSee('ناموجود');
    }

    public function test_static_product_media_publishes_responsive_variants_for_public_products_while_keeping_private_test_product_protected(): void
    {
        Storage::fake('public');

        $publicFilename = 'phase3-public-terminal.png';
        $privateFilename = 'phase3-private-gateway.png';
        $this->cleanupPublicMedia[] = 'products/'.$publicFilename;
        $this->cleanupPublicMedia[] = 'products/'.$privateFilename;

        $pngFile = UploadedFile::fake()->image($publicFilename, 800, 600);
        Storage::disk('public')->put('products/'.$publicFilename, (string) file_get_contents($pngFile->getRealPath()));
        Storage::disk('public')->put('products/'.$privateFilename, (string) file_get_contents($pngFile->getRealPath()));

        $publicProduct = $this->createPublicProduct('کارتخوان سیار تسلا T1', 'tesla-t1', 5_200_000, 6);
        $publicProduct->forceFill(['image_path' => 'products/'.$publicFilename])->save();

        // Force sync against fake disk to exercise static publishing & WebP derivative generation
        ProductMediaPublisher::syncProduct($publicProduct, force: true);

        $cacheDir = public_path(ProductMediaPublisher::PUBLIC_SUBDIR);
        $this->assertFileExists($cacheDir.DIRECTORY_SEPARATOR.$publicFilename);
        if (function_exists('imagewebp')) {
            $this->assertFileExists($cacheDir.DIRECTORY_SEPARATOR.'phase3-public-terminal-160w.webp');
            $this->assertFileExists($cacheDir.DIRECTORY_SEPARATOR.'phase3-public-terminal-400w.webp');
        }

        GuestPageCache::bump();
        $catalogHtml = $this->get('/products')->assertOk()->getContent();
        $this->assertStringContainsString('/media-cache/products/', $catalogHtml);
        $this->assertStringContainsString('srcset=', $catalogHtml);

        // Provision private payment test product and attach an image
        $owner = User::factory()->superAdmin()->create();
        if (! AdminAccount::query()->where('user_id', $owner->id)->exists()) {
            AdminAccount::forceCreate([
                'user_id' => $owner->id,
                'role' => 'owner',
                'owner_slot' => 1,
                'is_active' => true,
            ]);
        }
        $this->artisan('novinpay:provision-payment-test-product', ['--apply' => true])->assertExitCode(0);
        $testProduct = Product::query()->where('slug', 'zibal-gateway-test-5000')->firstOrFail();
        $testProduct->forceFill(['image_path' => 'products/'.$privateFilename])->save();

        // Even with force=true, private gateway-test product MUST NEVER be published to public/media-cache
        ProductMediaPublisher::syncProduct($testProduct, force: true);
        $this->assertFileDoesNotExist($cacheDir.DIRECTORY_SEPARATOR.$privateFilename);
        $this->assertFileDoesNotExist($cacheDir.DIRECTORY_SEPARATOR.'phase3-private-gateway-400w.webp');

        // Guest & non-allowlisted customer MUST receive 404 on /product-media/{privateFilename}
        $this->get('/product-media/'.$privateFilename)->assertNotFound();

        $outsider = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09350009988'),
            'phone' => '09350009988',
            'verified_at' => now(),
        ])->fresh();
        $this->actingAs($outsider, 'customer')
            ->withSession(['customer_session_version' => $outsider->session_version])
            ->get('/product-media/'.$privateFilename)
            ->assertNotFound();

        Auth::forgetGuards();
        $this->flushSession();

        // Allowlisted customer receives 200 with private no-store headers and protected route URL on product page
        $allowlisted = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09123456789'),
            'phone' => '09123456789',
            'verified_at' => now(),
        ])->fresh();
        $mediaResponse = $this->actingAs($allowlisted, 'customer')
            ->withSession(['customer_session_version' => $allowlisted->session_version])
            ->get('/product-media/'.$privateFilename);
        $mediaResponse->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $detailResponse = $this->actingAs($allowlisted, 'customer')
            ->withSession(['customer_session_version' => $allowlisted->session_version])
            ->get('/products/zibal-gateway-test-5000');
        $detailResponse->assertOk()
            ->assertSee('/product-media/'.$privateFilename, false)
            ->assertDontSee('/media-cache/products/'.$privateFilename, false);

        Auth::forgetGuards();
        $this->flushSession();

        // Active admin also receives 200 with private no-store headers
        $adminAccount = AdminAccount::query()->where('user_id', $owner->id)->firstOrFail();
        $this->actingAs($owner, 'web')
            ->withSession(['admin_session_version' => $adminAccount->session_version])
            ->get('/product-media/'.$privateFilename)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_cwv_asset_preloads_eager_first_row_cards_split_admin_css_and_external_repair_locations(): void
    {
        config(['support_chat.enabled' => true]);

        Storage::fake('public');

        for ($i = 1; $i <= 4; $i++) {
            $p = $this->createPublicProduct("کارتخوان مدل {$i}", "pos-model-{$i}", 4_000_000 + ($i * 100_000), 3);
            Storage::disk('public')->put("products/pos-model-{$i}.png", 'fake');
            $p->forceFill(['image_path' => "products/pos-model-{$i}.png"])->save();
        }
        GuestPageCache::bump();

        $home = $this->get('/');
        $home->assertOk()
            ->assertSee('DanaFaNum-Regular.woff2', false)
            ->assertSee('DanaFaNum-Bold.woff2', false)
            ->assertSee('rel="preload" as="image"', false)
            ->assertSee('data-support-v2="1"', false);

        $productsHtml = $this->get('/products')->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(2, substr_count($productsHtml, 'fetchpriority="high"'));
        $this->assertGreaterThanOrEqual(2, substr_count($productsHtml, 'loading="lazy"'));

        $repair = $this->get('/repair');
        $repair->assertOk()
            ->assertSee('id="repair-iran-locations"', false)
            ->assertSee('js/iran-locations.json?v=', false);
        $this->assertFileExists(public_path('js/iran-locations.json'));
        $decodedLocations = json_decode((string) file_get_contents(public_path('js/iran-locations.json')), true);
        $this->assertIsArray($decodedLocations);
        $this->assertArrayHasKey('تهران', $decodedLocations);
    }

    private function createPublicProduct(string $name, string $slug, int $price, int $stock): Product
    {
        return $this->createPublicProductWithInventory($name, $slug, $price, $stock)[0];
    }

    /**
     * @return array{0: Product, 1: InventoryItem}
     */
    private function createPublicProductWithInventory(string $name, string $slug, int $price, int $stock): array
    {
        $product = Product::forceCreate([
            'name' => $name,
            'slug' => $slug,
            'category' => ProductCategory::PosTerminal,
            'brand' => 'PAX',
            'condition' => ProductCondition::New,
            'stock_status' => $stock > 0 ? StockStatus::InStock : StockStatus::OutOfStock,
            'price' => $price,
            'is_published' => true,
            'online_purchasing_enabled' => true,
        ]);

        $inventory = InventoryItem::forceCreate([
            'public_id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'sku' => 'SKU-'.strtoupper($slug),
            'name' => $name,
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'on_hand' => $stock,
            'reserved' => 0,
            'version' => 1,
        ]);

        return [$product, $inventory];
    }
}
