<?php

namespace Tests\Feature;

use App\Models\AdminAccount;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Observability\BackupReporter;
use App\Services\Observability\ObservabilityEvents;
use App\Services\Observability\ObservabilityMetrics;
use App\Services\Observability\SystemHealthService;
use App\Services\Observability\TelegramTopicNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ObservabilityAndTelegramTopicsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_delivery_failure_is_visible_and_manual_force_bypasses_dedupe(): void
    {
        $this->enableTelegramTopics();
        Http::fakeSequence()->push(['ok' => false], 403)->push(['ok' => true]);
        $options = ['status' => 'ok', 'path' => '/private/fixture.tar.gz.enc', 'size' => 100];
        $this->assertFalse(app(BackupReporter::class)->report($options)['notification_sent']);
        $this->assertFalse(app(BackupReporter::class)->report($options)['notification_sent']);
        $this->assertTrue(app(BackupReporter::class)->report([...$options, 'force' => true])['notification_sent']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request['message_thread_id'] === 17);
    }

    public function test_slow_responses_are_queued_and_failed_delivery_can_be_retried(): void
    {
        $this->enableTelegramTopics();
        Http::fakeSequence()->push(['ok' => false], 503)->push(['ok' => true]);
        config(['observability.performance.slow_request_ms' => 1]);
        $fixture = sys_get_temp_dir().'/novinpay-performance-'.Str::uuid();
        File::ensureDirectoryExists($fixture, 0700);
        config(['observability.performance.outbox_path' => $fixture]);
        try {
            Route::get('/slow-test/{record}', function () {
                request()->attributes->set('performance_started', hrtime(true) - 10_000_000);

                return redirect('/fixture');
            });
            $this->get('/slow-test/private-reference')->assertRedirect('/fixture')
                ->assertHeader('Server-Timing')->assertHeader('X-Request-Id');
            Http::assertNothingSent();
            $this->artisan('novinpay:deliver-performance-alerts')->assertFailed();
            $this->artisan('novinpay:deliver-performance-alerts')->assertSuccessful();
            $this->artisan('novinpay:deliver-performance-alerts')->assertSuccessful();
            Http::assertSentCount(2);
            Http::assertSent(fn ($request) => str_contains($request['text'], '/slow-test/{record}')
                && ! str_contains($request['text'], 'private-reference'));
        } finally {
            File::deleteDirectory($fixture);
        }
    }

    public function test_raw_confirmed_transition_is_suppressed_without_losing_metrics(): void
    {
        $this->enableTelegramTopics();
        Http::fake(['*' => Http::response(['ok' => true])]);
        $order = new SalesOrder;
        $order->public_id = (string) Str::uuid();
        app(ObservabilityEvents::class)->orderStatusChanged($order, 'draft', 'confirmed', fullConfirmation: true);
        Http::assertNothingSent();
        $this->assertSame(1, app(ObservabilityMetrics::class)->get('orders.status.confirmed'));
    }

    public function test_nested_backup_discovery_uses_receipts_and_ignores_recent_metadata(): void
    {
        $this->enableTelegramTopics();
        Http::fake(['*' => Http::response(['ok' => true])]);
        $root = storage_path('framework/testing/backup-topic-'.Str::uuid());
        mkdir($root.'/recovery-fixture', 0700, true);
        try {
            file_put_contents($root.'/recovery-fixture/backup.npb', 'encrypted-fixture');
            file_put_contents($root.'/recovery-fixture/receipt.json', json_encode([
                'result' => 'BACKUP_AND_DISPOSABLE_RESTORE_PASS', 'archiveBytes' => 17,
                'snapshotUnix' => now()->subHours(73)->timestamp,
            ], JSON_THROW_ON_ERROR));
            file_put_contents($root.'/new-metadata.json', '{}');
            $report = app(BackupReporter::class)->report(['path' => $root]);
            $this->assertSame('stale', $report['status']);
            $this->assertSame('backup.npb', $report['file']);
            $this->assertTrue($report['notification_sent']);
            Http::assertSent(fn ($request) => $request['message_thread_id'] === 18);
            Http::assertSent(fn ($request) => $request['message_thread_id'] === 17);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_topic_admin_link_is_a_button_and_payment_amount_is_toman(): void
    {
        $this->enableTelegramTopics();
        config(['app.url' => 'https://novinpaypos.test']);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $url = 'https://novinpaypos.test/admin/orders/fixture';
        app(TelegramTopicNotifier::class)->send('orders', "سفارش\n".$url, adminUrl: $url);
        Http::assertSent(fn ($request) => ! str_contains($request['text'], '/admin/orders')
            && $request['reply_markup']['inline_keyboard'][0][0]['url'] === $url);
        $attempt = new PaymentAttempt;
        $attempt->forceFill(['public_id' => '12345678-fixture', 'state' => 'settled', 'amount_minor' => 8500000]);
        app(ObservabilityEvents::class)->payment('settled', $attempt);
        Http::assertSent(fn ($request) => str_contains($request['text'], '85,000 تومان')
            && str_contains($request['text'], 'تسویه‌شده') && ! str_contains($request['text'], 'settled'));
    }

    private function enableTelegramTopics(): void
    {
        config([
            'observability.telegram_topics.enabled' => true,
            'observability.telegram_topics.bot_token' => '123456:TEST_BOT_TOKEN_SECRET',
            'observability.telegram_topics.chat_id' => '-100999888777',
            'observability.telegram_topics.throttle_seconds' => 300,
            'observability.telegram_topics.threads' => [
                'payments' => 11,
                'orders' => 12,
                'repairs' => 13,
                'users' => 14,
                'sms' => 15,
                'inventory' => 16,
                'errors' => 17,
                'backups' => 18,
                'performance' => 19,
                'digest' => 20,
            ],
        ]);
    }

    private function createAdminUser(string $role, array $permissions = []): User
    {
        $user = User::factory()->create([
            'password' => bcrypt('StrongPass!2345'),
        ]);
        $account = new AdminAccount;
        $account->user_id = $user->id;
        $account->role = $role;
        $account->owner_slot = $role === 'super_admin' ? 1 : null;
        $account->permissions = $permissions;
        $account->is_active = true;
        $account->must_change_password = false;
        $account->session_version = 1;
        $account->save();

        return $user;
    }

    public function test_daily_rotating_log_channels_are_configured(): void
    {
        foreach (['daily', 'payments', 'sms', 'frontend', 'performance'] as $channel) {
            $cfg = config("logging.channels.{$channel}");
            $this->assertIsArray($cfg, "Channel {$channel} should be configured");
            $this->assertSame('daily', $cfg['driver']);
            $this->assertGreaterThanOrEqual(14, (int) $cfg['days']);
            $this->assertLessThanOrEqual(30, (int) $cfg['days']);
        }
    }

    public function test_requests_attach_incident_id_header_and_500_view_displays_it(): void
    {
        $response = $this->get('/terms');
        $response->assertOk();
        $requestId = $response->headers->get('X-Request-Id');
        $this->assertNotNull($requestId);
        $this->assertMatchesRegularExpression('/\AE\d{6}-[a-fA-F0-9]{6}\z/', $requestId);

        request()->attributes->set('incident_id', 'E261125-ABC123');
        $html = view('errors.500')->render();
        $this->assertStringContainsString('E261125-ABC123', $html);
        $this->assertStringContainsString('کد پیگیری خطا', $html);
    }

    public function test_telegram_topic_notifier_is_noop_when_disabled_and_routes_redacts_and_deduplicates_when_enabled(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        config(['observability.telegram_topics.enabled' => false]);

        $notifier = app(TelegramTopicNotifier::class);
        $this->assertFalse($notifier->send('errors', 'Should not be sent'));
        Http::assertNothingSent();

        $this->enableTelegramTopics();
        Cache::flush();

        $sentFirst = $notifier->send(
            'errors',
            'Error with Bearer secret_token_abc123 and otp: 654321 and bot123456:ABCDEF_secret',
            'dedupe-key-1',
            300,
        );
        $sentDuplicate = $notifier->send(
            'errors',
            'Duplicate alert should be suppressed',
            'dedupe-key-1',
            300,
        );
        $sentPayments = $notifier->send('payments', 'Payment settled', 'dedupe-pay-1', 300);

        $this->assertTrue($sentFirst);
        $this->assertFalse($sentDuplicate);
        $this->assertTrue($sentPayments);

        Http::assertSentCount(2);
        Http::assertSent(function (HttpRequest $request): bool {
            if (($request['message_thread_id'] ?? null) !== 17) {
                return false;
            }
            $text = (string) ($request['text'] ?? '');

            return str_contains($text, 'Bearer [REDACTED]')
                && str_contains($text, '[REDACTED_OTP]')
                && str_contains($text, '[REDACTED_TOKEN]')
                && ! str_contains($text, '654321')
                && ! str_contains($text, 'secret_token_abc123')
                && ! str_contains($text, 'ABCDEF_secret');
        });
        Http::assertSent(fn (HttpRequest $request) => ($request['message_thread_id'] ?? null) === 11
            && ($request['chat_id'] ?? null) === '-100999888777');

        // Custom Telegram proxy / Cloudflare Worker API base URL is respected
        config(['observability.telegram_topics.api_base' => 'https://tg-proxy.example.test']);
        $this->assertTrue($notifier->send('orders', 'Proxied topic alert', 'dedupe-proxy-1', 300));
        Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://tg-proxy.example.test/bot123456:TEST_BOT_TOKEN_SECRET/sendMessage')
            && ($request['message_thread_id'] ?? null) === 12);
    }

    public function test_client_telemetry_validates_sanitizes_caps_size_and_rate_limits(): void
    {
        $this->enableTelegramTopics();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        Cache::flush();

        // Oversized payload rejected with 413
        $oversized = $this->postJson('/telemetry/client-error', [
            'message' => str_repeat('X', 5000),
            'path' => '/products',
        ]);
        $oversized->assertStatus(413);

        // Valid payload accepted (204), sanitized, and sent to errors topic
        $res = $this->postJson('/telemetry/client-error', [
            'message' => 'TypeError for 09123456789 and test@example.com',
            'source' => 'https://novinpaypos.com/build/app.js?token=secret123',
            'line' => 42,
            'column' => 7,
            'path' => '/products?search=pos&phone=09123456789',
            'kind' => 'error',
        ]);
        $res->assertNoContent();
        $this->assertSame(1, app(ObservabilityMetrics::class)->get('errors.client_js'));

        Http::assertSent(function (HttpRequest $request): bool {
            $text = (string) ($request['text'] ?? '');

            return ($request['message_thread_id'] ?? null) === 17
                && ! str_contains($text, '09123456789')
                && ! str_contains($text, 'test@example.com')
                && ! str_contains($text, 'secret123');
        });

        // Rate limit: 10 requests per minute; we already sent 1 valid + 1 oversized (total 2 hits to route)
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/telemetry/client-error', [
                'message' => "Minor warning {$i}",
                'path' => '/repair',
            ])->assertNoContent();
        }
        $this->postJson('/telemetry/client-error', [
            'message' => 'Eleventh request must be throttled',
            'path' => '/repair',
        ])->assertStatus(429);
    }

    public function test_sms_categorization_counts_categories_without_leaking_otp_and_alerts_failures(): void
    {
        $this->enableTelegramTopics();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        Cache::flush();

        $events = app(ObservabilityEvents::class);
        $events->sms('otp', 'accepted', 'ippanel', 'customer_otp');
        $events->sms('order', 'accepted', 'ippanel', 'order_confirmed');
        $events->sms('repair', 'rejected', 'ippanel', 'repair_created');
        $events->sms('alert', 'accepted', 'ippanel', 'admin_alert');

        $breakdown = app(ObservabilityMetrics::class)->smsBreakdown();
        $this->assertSame(1, $breakdown['otp']);
        $this->assertSame(1, $breakdown['order']);
        $this->assertSame(1, $breakdown['repair']);
        $this->assertSame(1, $breakdown['alert']);
        $this->assertSame(0, $breakdown['other']);
        $this->assertSame(4, $breakdown['total']);
        $this->assertSame(1, $breakdown['failed']);

        Http::assertSent(fn (HttpRequest $request) => ($request['message_thread_id'] ?? null) === 15
            && str_contains((string) ($request['text'] ?? ''), 'خطا در ارسال پیامک')
            && str_contains((string) ($request['text'] ?? ''), 'ثبت تعمیر'));
    }

    public function test_backup_sales_inventory_and_daily_digest_commands_route_to_correct_topics(): void
    {
        $this->enableTelegramTopics();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        Cache::flush();

        // Seed 2 inventory items (one out of stock, one low stock)
        $oos = new InventoryItem;
        $oos->public_id = (string) Str::uuid();
        $oos->sku = 'POS-OOS-01';
        $oos->name = 'کارتخوان ناموجود تستی';
        $oos->condition = 'new';
        $oos->serialized = false;
        $oos->on_hand = 0;
        $oos->reserved = 0;
        $oos->dispatched = 0;
        $oos->low_stock_threshold = 2;
        $oos->is_low_stock = true;
        $oos->version = 1;
        $oos->save();

        $low = new InventoryItem;
        $low->public_id = (string) Str::uuid();
        $low->sku = 'POS-LOW-02';
        $low->name = 'کارتخوان موجودی کم تستی';
        $low->condition = 'new';
        $low->serialized = false;
        $low->on_hand = 2;
        $low->reserved = 1;
        $low->dispatched = 0;
        $low->low_stock_threshold = 2;
        $low->is_low_stock = true;
        $low->version = 1;
        $low->save();

        // 1) Backup OK -> backups topic only (18)
        $codeOk = Artisan::call('novinpay:telegram-backup-report', [
            '--status' => 'ok',
            '--path' => '/backups/novinpay-2026.tar.gz.enc',
            '--size' => 10485760,
            '--note' => 'Encrypted backup verified',
        ]);
        $this->assertSame(0, $codeOk);
        $this->assertSame('ok', app(ObservabilityMetrics::class)->latestBackup()['status'] ?? null);

        // 2) Backup FAILED -> backups topic (18) AND errors topic (17)
        $codeFail = Artisan::call('novinpay:telegram-backup-report', [
            '--status' => 'failed',
            '--path' => '/backups/novinpay-failed.tar.gz.enc',
            '--note' => 'Disk full',
        ]);
        $this->assertSame(1, $codeFail);

        // 3) Sales & Inventory report -> orders (12) + inventory (16)
        $this->assertSame(0, Artisan::call('novinpay:telegram-sales-inventory-report', ['--hours' => 24]));

        // 4) Daily digest -> digest (20)
        $this->assertSame(0, Artisan::call('novinpay:telegram-daily-digest'));

        Http::assertSent(fn (HttpRequest $r) => ($r['message_thread_id'] ?? null) === 18 && str_contains((string) $r['text'], 'فایل بکاپ به‌روز'));
        Http::assertSent(fn (HttpRequest $r) => ($r['message_thread_id'] ?? null) === 17 && str_contains((string) $r['text'], 'هشدار بکاپ'));
        Http::assertSent(fn (HttpRequest $r) => ($r['message_thread_id'] ?? null) === 12 && str_contains((string) $r['text'], 'گزارش دوره‌ای فروش'));
        Http::assertSent(fn (HttpRequest $r) => ($r['message_thread_id'] ?? null) === 16 && str_contains((string) $r['text'], 'POS-OOS-01') && str_contains((string) $r['text'], 'POS-LOW-02'));
        Http::assertSent(fn (HttpRequest $r) => ($r['message_thread_id'] ?? null) === 20 && str_contains((string) $r['text'], 'گزارش جمع‌بندی روزانه نوین‌پی'));
    }

    public function test_admin_system_health_enforces_access_control_and_redacts_logs(): void
    {
        // Guest redirected to admin login
        $this->get('/admin/system-health')->assertRedirect('/admin/login');

        // Staff without maintenance.view -> 403
        $staff = $this->createAdminUser('staff', ['orders.view']);
        $this->actingAs($staff, 'web')
            ->withSession(['admin_session_version' => 1])
            ->get('/admin/system-health')
            ->assertForbidden();

        // Owner with full access -> 200
        $owner = $this->createAdminUser('super_admin');
        $response = $this->actingAs($owner, 'web')
            ->withSession(['admin_session_version' => 1])
            ->get('/admin/system-health?channel=daily&level=ALL');

        $response->assertOk();
        $response->assertSee('سلامت سیستم و لاگ‌ها');
        $response->assertSee('وضعیت اجزای سیستم');
        $response->assertSee('مشاهدهٔ لاگ‌های اخیر');

        // Log redaction sanity check
        $health = app(SystemHealthService::class);
        $redacted = $health->redactLogText('Bearer my_secret_token phone=09121234567 email=user@example.com otp=987654');
        $this->assertStringNotContainsString('my_secret_token', $redacted);
        $this->assertStringNotContainsString('09121234567', $redacted);
        $this->assertStringNotContainsString('user@example.com', $redacted);
        $this->assertStringNotContainsString('987654', $redacted);
        $this->assertStringContainsString('09•••••4567', $redacted);
    }
}
