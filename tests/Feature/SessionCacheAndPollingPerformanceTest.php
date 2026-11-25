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
use App\Services\OperationalReadiness;
use App\Services\ShoppingCart;
use App\Support\Chat\ChatBridge;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionCacheAndPollingPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir().'/novinpay-perf-test-'.bin2hex(random_bytes(10));
        mkdir($this->tempRoot.'/sessions', 0700, true);
        mkdir($this->tempRoot.'/cache', 0700, true);
        mkdir($this->tempRoot.'/chat', 0700, true);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_COOKIE = [];
        app(ChatBridge::class)->finish();
        if (isset($this->tempRoot) && str_starts_with($this->tempRoot, sys_get_temp_dir().'/novinpay-perf-test-')) {
            File::deleteDirectory($this->tempRoot);
        }
        parent::tearDown();
    }

    public function test_config_files_default_to_file_driver_and_zero_http_session_lottery(): void
    {
        $sessionConfig = file_get_contents(config_path('session.php'));
        $cacheConfig = file_get_contents(config_path('cache.php'));
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString("env('SESSION_DRIVER', 'file')", $sessionConfig);
        $this->assertStringContainsString("env('SESSION_LOTTERY_CHANCE', 0)", $sessionConfig);
        $this->assertStringContainsString("env('CACHE_STORE', 'file')", $cacheConfig);
        $this->assertStringContainsString('SESSION_DRIVER=file', $envExample);
        $this->assertStringContainsString('CACHE_STORE=file', $envExample);
    }

    public function test_file_session_and_file_cache_handle_storefront_cart_auth_and_locks_with_zero_db_session_or_cache_queries(): void
    {
        config([
            'session.driver' => 'file',
            'session.files' => $this->tempRoot.'/sessions',
            'session.encrypt' => true,
            'session.lottery' => [0, 100],
            'cache.default' => 'file',
            'cache.stores.file.path' => $this->tempRoot.'/cache',
            'cache.stores.file.lock_path' => $this->tempRoot.'/cache',
        ]);
        Cache::forgetDriver('file');
        app('session')->forgetDrivers();

        $readiness = app(OperationalReadiness::class);
        $readiness->heartbeat();
        $status = $readiness->status();
        $this->assertTrue($status['scheduler_recent']);
        $this->assertTrue($status['cross_process_cache_locks']);

        $owner = User::factory()->superAdmin()->create();
        $product = Product::create([
            'name' => 'Newland ME31 (4G)',
            'slug' => 'newland-me31-perf',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 4500000,
            'price_changed_at' => now(),
            'online_purchasing_enabled' => true,
            'is_published' => true,
            'is_sample' => false,
        ]);
        $commerce = app(CommerceManagement::class);
        $itemId = $commerce->execute($owner, 'item.create', [
            'operation_key' => (string) Str::uuid(),
            'sku' => 'ME31-PERF-1',
            'name' => 'ME31',
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'product_id' => $product->id,
            'low_stock_threshold' => 0,
            'reason' => 'Perf test',
        ]);
        $item = InventoryItem::findOrFail($itemId);
        $commerce->execute($owner, 'item.receive', [
            'operation_key' => (string) Str::uuid(),
            'version' => $item->version,
            'quantity' => 5,
            'reason' => 'Perf test',
        ], $item);

        $customer = new CustomerAccount;
        $customer->public_id = (string) Str::uuid();
        $customer->phone = '09123456789';
        $customer->phone_digest = RepairIdentity::digest('customer-account-phone', '09123456789');
        $customer->verified_at = now();
        $customer->is_active = true;
        $customer->session_version = 1;
        $customer->save();

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $cookie = str_repeat('c', 64);
            $this->actingAs($customer, 'customer')
                ->withSession(['customer_session_version' => 1])
                ->withCookie(ShoppingCart::DEVICE_COOKIE, $cookie);

            $this->get('/')->assertOk();
            $this->get('/products')->assertOk();
            $cartRes = $this->get('/cart')->assertOk();
            $cartData = $cartRes->original->getData();
            $this->post(route('cart.add', $product), [
                'quantity' => 1,
                'cart_context' => $cartData['proof'],
            ])->assertRedirect();
            $this->get('/repair')->assertOk();

            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
        }

        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(sessions|cache|cache_locks)\b/i', $sql);
        }
    }

    public function test_support_chat_status_and_authenticated_polls_avoid_redundant_db_writes(): void
    {
        config([
            'support_chat.enabled' => true,
            'support_chat.directory' => $this->tempRoot.'/chat',
            'cache.default' => 'file',
            'cache.stores.file.path' => $this->tempRoot.'/cache',
            'cache.stores.file.lock_path' => $this->tempRoot.'/cache',
        ]);
        Cache::forgetDriver('file');
        User::factory()->superAdmin()->create();
        app(ChatBridge::class)->initialize();

        // Unauthenticated GET ?action=status runs zero SQL queries and needs no chat session.
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->get('https://localhost/support-chat/customer-notifications.php?action=status')
                ->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('push_enabled', false);
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        // Authenticated customer bootstrap binds chat_customer_owners once; subsequent polls execute zero INSERT/UPDATE/DELETE queries.
        $customer = new CustomerAccount;
        $customer->public_id = (string) Str::uuid();
        $customer->phone = '09121112233';
        $customer->phone_digest = RepairIdentity::digest('customer-account-phone', '09121112233');
        $customer->verified_at = now();
        $customer->is_active = true;
        $customer->session_version = 1;
        $customer->save();

        $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
        $boot = $this->get('https://localhost/support-chat/api.php?get_session=1&bootstrap=1')->assertOk();
        $chatCookieName = session_name();
        $chatCookieVal = session_id();
        $this->assertNotSame('', $chatCookieName);
        $this->assertNotSame('', $chatCookieVal);
        $draftId = $boot->json('draft_user_id');

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->withCookie($chatCookieName, $chatCookieVal)
                ->get('https://localhost/support-chat/api.php?get_conversation=1&protocol_version=2&scope=customer&user_id='.$draftId)
                ->assertOk();
            $this->withCookie($chatCookieName, $chatCookieVal)
                ->get('https://localhost/support-chat/customer-notifications.php?action=hint')
                ->assertOk();

            $pollQueries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
        }

        foreach ($pollQueries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $sql);
            $this->assertDoesNotMatchRegularExpression('/\b(sessions|cache|cache_locks)\b/i', $sql);
        }
    }

    public function test_prune_ephemeral_command_is_scheduled_and_removes_only_expired_session_and_cache_files(): void
    {
        $scheduled = collect(Schedule::events())->filter(
            fn ($event) => isset($event->command) && str_contains($event->command, 'novinpay:prune-ephemeral')
        );
        $this->assertCount(1, $scheduled);
        $this->assertTrue($scheduled->first()->withoutOverlapping);

        config([
            'session.driver' => 'file',
            'session.lifetime' => 120,
            'session.files' => $this->tempRoot.'/sessions',
            'cache.default' => 'file',
            'cache.stores.file.path' => $this->tempRoot.'/cache',
            'cache.stores.file.lock_path' => $this->tempRoot.'/cache',
        ]);
        Cache::forgetDriver('file');

        $expiredSession = $this->tempRoot.'/sessions/expired_sess';
        $freshSession = $this->tempRoot.'/sessions/fresh_sess';
        $gitignore = $this->tempRoot.'/sessions/.gitignore';
        file_put_contents($expiredSession, 'expired');
        touch($expiredSession, now()->subMinutes(180)->getTimestamp());
        file_put_contents($freshSession, 'fresh');
        touch($freshSession, now()->subMinutes(15)->getTimestamp());
        file_put_contents($gitignore, "*\n!.gitignore\n");
        touch($gitignore, now()->subDays(30)->getTimestamp());

        Cache::store('file')->put('active_key', 'ok', 600);
        Cache::store('file')->forever('forever_key', 'keep');
        mkdir($this->tempRoot.'/cache/aa/bb', 0700, true);
        $expiredCacheFile = $this->tempRoot.'/cache/aa/bb/expired_entry';
        file_put_contents($expiredCacheFile, str_pad((string) now()->subMinutes(5)->getTimestamp(), 10, '0', STR_PAD_LEFT).serialize('stale'));

        $this->assertSame(0, Artisan::call('novinpay:prune-ephemeral'));
        $report = json_decode(trim(Artisan::output()), true, 16, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $report['sessions_pruned']);
        $this->assertSame(1, $report['cache_files_pruned']);
        $this->assertFileDoesNotExist($expiredSession);
        $this->assertFileExists($freshSession);
        $this->assertFileExists($gitignore);
        $this->assertFileDoesNotExist($expiredCacheFile);
        $this->assertSame('ok', Cache::store('file')->get('active_key'));
        $this->assertSame('keep', Cache::store('file')->get('forever_key'));
    }
}
