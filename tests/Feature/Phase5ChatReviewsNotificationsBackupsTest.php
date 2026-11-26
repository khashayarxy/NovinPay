<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\RepairBrand;
use App\Enums\StockStatus;
use App\Events\OrderPaid;
use App\Mail\UnifiedNotificationMail;
use App\Models\Consultation;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\FinancialDocument;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\IndependentSupportOperators;
use App\Services\Observability\EncryptedDatabaseBackupService;
use App\Services\Observability\ObservabilityEvents;
use App\Support\Chat\ChatBridge;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class Phase5ChatReviewsNotificationsBackupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_review_preview_is_visible_only_to_submitter_and_triggers_telegram_reviews_topic(): void
    {
        config([
            'app.url' => 'https://novinpaypos.test',
            'observability.telegram_topics.enabled' => true,
            'observability.telegram_topics.bot_token' => '123456:TEST-TOPICS-TOKEN',
            'observability.telegram_topics.chat_id' => '-1005775833170',
            'observability.telegram_topics.threads' => ['reviews' => 55],
            'notifications.bale.enabled' => true,
            'notifications.bale.bot_token' => '987654:TEST-BALE-TOKEN',
            'notifications.bale.chat_id' => '55443322',
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://tapi.bale.ai/*' => Http::response(['ok' => true], 200),
        ]);

        $product = Product::forceCreate([
            'name' => 'کارتخوان PAX S910 4G',
            'slug' => 'pax-s910-review-preview',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 8500000,
            'is_published' => true,
            'is_sample' => false,
        ]);

        $author = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'سارا رضایی',
            'phone' => '09121110001',
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09121110001'),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);

        $otherUser = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'کاربر دیگر',
            'phone' => '09121110002',
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09121110002'),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);

        $reviewText = 'آنتن‌دهی عالی و سرعت تراکنش بسیار خوب بود.';

        $this->actingAs($author, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->post('https://novinpaypos.test'.route('products.feedback.store', $product, false), [
                'kind' => 'review',
                'body' => $reviewText,
            ])
            ->assertRedirect('https://novinpaypos.test'.route('products.show', $product, false));

        // Submitter immediately sees their pending preview card + private no-store header
        $submitterRes = $this->actingAs($author, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->get('https://novinpaypos.test'.route('products.show', $product, false));
        $submitterRes->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('data-review-preview="pending"', false)
            ->assertSee('پس از تأیید مدیریت نمایش داده می‌شود')
            ->assertSee($reviewText);

        // Other authenticated visitor NEVER sees the pending review preview
        $this->actingAs($otherUser, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertDontSee('data-review-preview="pending"', false)
            ->assertDontSee('پس از تأیید مدیریت نمایش داده می‌شود')
            ->assertDontSee($reviewText);

        // Anonymous guest NEVER sees the pending review preview
        auth('customer')->logout();
        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertDontSee('data-review-preview="pending"', false)
            ->assertDontSee($reviewText);

        // Telegram reviews topic (55) + Bale notified with moderation deep link
        Http::assertSent(fn (HttpRequest $req) => str_starts_with($req->url(), 'https://api.telegram.org/bot123456:TEST-TOPICS-TOKEN/sendMessage')
            && ($req['message_thread_id'] ?? null) === 55
            && str_contains((string) ($req['text'] ?? ''), 'دیدگاه جدید برای بررسی ثبت شد')
            && str_contains((string) ($req['reply_markup']['inline_keyboard'][0][0]['url'] ?? ''), '/admin/products/pax-s910-review-preview/feedback'));
        Http::assertSent(fn (HttpRequest $req) => str_starts_with($req->url(), 'https://tapi.bale.ai/bot987654:TEST-BALE-TOKEN/sendMessage'));
    }

    public function test_admin_sms_and_email_fire_on_paid_orders_and_repairs_even_without_dedicated_admin_patterns(): void
    {
        Cache::flush();
        Mail::fake();
        Http::fake([
            'https://edge.ippanel.com/*' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [501]]], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        // Simulate production .env where NOTIFICATIONS_ADMIN_PHONES & NOTIFICATIONS_ADMIN_EMAILS are set,
        // while dedicated NOTIFICATIONS_IPPANEL_PATTERN_ADMIN_ORDER / ADMIN_REPAIR are blank!
        config([
            'customer.ippanel.enabled' => true,
            'customer.ippanel.api_key' => 'live-ippanel-key',
            'customer.ippanel.sender' => '+983000505',
            'notifications.admin.phones' => ['09129998877'],
            'notifications.admin.emails' => ['owner@novinpaypos.com'],
            'notifications.sms.enabled' => true,
            'notifications.sms.admin_shared_pattern_fallback' => true,
            'notifications.sms.patterns.admin_order_placed' => '',
            'notifications.sms.patterns.admin_order_paid' => '',
            'notifications.sms.patterns.admin_repair_submitted' => '',
            'notifications.pattern_sms.enabled' => true,
            'notifications.pattern_sms.provider' => 'ippanel',
            'notifications.email.enabled' => true,
        ]);

        $owner = User::factory()->superAdmin()->create();
        $customer = Customer::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'مهدی حسینی',
            'contact_phone' => '09123334444',
            'name_digest' => bin2hex(random_bytes(32)),
            'phone_digest' => RepairIdentity::digest('phone', '09123334444'),
            'identity_digest' => bin2hex(random_bytes(32)),
            'is_active' => true,
        ]);
        $account = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'name' => 'مهدی حسینی',
            'phone' => '09123334444',
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09123334444'),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);
        $product = Product::forceCreate([
            'name' => 'Newland ME31',
            'slug' => 'newland-me31-admin-notify',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 6200000,
            'is_published' => true,
        ]);
        $inv = InventoryItem::forceCreate([
            'public_id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'sku' => 'SKU-ME31-ADM',
            'name' => 'Newland ME31',
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'on_hand' => 4,
            'reserved' => 0,
            'version' => 1,
        ]);
        $order = SalesOrder::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'customer_account_id' => $account->id,
            'checkout_key' => (string) Str::uuid(),
            'checkout_digest' => bin2hex(random_bytes(32)),
            'status' => 'confirmed',
            'currency' => 'TOMAN',
            'total' => '6200000.00',
            'version' => 1,
        ]);
        SalesOrderLine::forceCreate([
            'sales_order_id' => $order->id,
            'inventory_item_id' => $inv->id,
            'sku' => $inv->sku,
            'description' => 'Newland ME31',
            'condition' => ProductCondition::New->value,
            'quantity' => 1,
            'revision' => 1,
            'unit_price' => '6200000.00',
            'line_total' => '6200000.00',
        ]);
        $doc = FinancialDocument::forceCreate([
            'public_id' => (string) Str::uuid(),
            'sales_order_id' => $order->id,
            'customer_id' => $customer->id,
            'created_by' => $owner->id,
            'source_version' => 1,
            'source_amount_minor' => 620000000,
            'charged_minor' => 620000000,
            'paid_minor' => 620000000,
            'version' => 1,
            'created_at' => now(),
        ]);
        $attempt = PaymentAttempt::forceCreate([
            'public_id' => (string) Str::uuid(),
            'financial_document_id' => $doc->id,
            'customer_account_id' => $account->id,
            'operation_key' => (string) Str::uuid(),
            'amount_minor' => 620000000,
            'amount_rial' => 62000000,
            'document_version' => 1,
            'merchant_digest' => bin2hex(random_bytes(32)),
            'track_id' => '88776655',
            'state' => 'settled',
        ]);
        $tx = PaymentTransaction::forceCreate([
            'public_id' => (string) Str::uuid(),
            'financial_document_id' => $doc->id,
            'payment_attempt_id' => $attempt->id,
            'operation_key' => (string) Str::uuid(),
            'method' => 'gateway',
            'amount_minor' => 620000000,
            'allocated_minor' => 620000000,
            'reference_digest' => bin2hex(random_bytes(32)),
            'reference' => 'ZIBAL-ADM-1',
            'created_at' => now(),
        ]);

        OrderPaid::dispatch($order, $doc, $tx);

        // Admin Email + Admin SMS (shared fallback pattern mfae2yqje8msw9e) BOTH sent on OrderPaid!
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->hasTo('owner@novinpaypos.com')
            && str_contains($m->mailSubject, 'پرداخت سفارش'));
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://edge.ippanel.com/v1/api/send'
            && ($req['recipients'] ?? null) === ['+989129998877']
            && ($req['code'] ?? null) === 'mfae2yqje8msw9e');

        // Submit repair request -> Admin Email + Admin SMS (shared fallback pattern 309p3u2jo5b7qds) BOTH sent!
        $token = bin2hex(random_bytes(32));
        $this->actingAs($account, 'customer')
            ->withSession(['customer_session_version' => 1, 'repair_submission_token' => $token])
            ->post('/repair', [
                'submission_token' => $token,
                'customer_name' => 'مهدی حسینی',
                'contact_phone' => '09123334444',
                'device_brand' => RepairBrand::Pax->value,
                'device_model' => 'S90',
                'issue' => 'ارتقای ماژول 2G به 4G',
            ])->assertRedirect(route('repair.confirmation'));

        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->hasTo('owner@novinpaypos.com')
            && str_contains($m->mailSubject, 'درخواست تعمیر جدید'));
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://edge.ippanel.com/v1/api/send'
            && ($req['recipients'] ?? null) === ['+989129998877']
            && ($req['code'] ?? null) === '309p3u2jo5b7qds');
    }

    public function test_encrypted_db_backup_runs_twice_daily_uploads_document_to_telegram_and_decrypts_cleanly(): void
    {
        $scheduled = collect(Schedule::events())->filter(
            fn ($event) => isset($event->command) && str_contains($event->command, 'novinpay:telegram-encrypted-backup')
        );
        $this->assertCount(1, $scheduled);
        $this->assertSame('0 6,18 * * *', $scheduled->first()->expression);
        $this->assertSame('Asia/Tehran', (string) $scheduled->first()->timezone);

        $backupRoot = storage_path('framework/testing/enc-backups-'.Str::uuid());
        config([
            'observability.backups.path' => $backupRoot,
            'observability.backups.passphrase' => 'StrongTestBackupPassphrase!2026',
            'observability.backups.retain_scheduled' => 2,
            'observability.telegram_topics.enabled' => true,
            'observability.telegram_topics.bot_token' => '123456:BACKUP-BOT-TOKEN',
            'observability.telegram_topics.chat_id' => '-1005775833170',
            'observability.telegram_topics.threads' => ['backups' => 18],
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200)]);

        try {
            User::factory()->superAdmin()->create(['email' => 'backup-marker@novinpaypos.test']);

            $this->assertSame(0, Artisan::call('novinpay:telegram-encrypted-backup', ['--force' => true]));

            $files = glob($backupRoot.'/scheduled/novinpay-db-*.sql.gz.enc') ?: [];
            $this->assertCount(1, $files);

            // Raw file must NOT contain plaintext SQL or email
            $rawBytes = (string) file_get_contents($files[0]);
            $this->assertStringStartsWith(EncryptedDatabaseBackupService::MAGIC, $rawBytes);
            $this->assertStringNotContainsString('backup-marker@novinpaypos.test', $rawBytes);

            // Decrypting with passphrase recovers full SQL dump
            $decryptedSql = app(EncryptedDatabaseBackupService::class)->decryptArchive($files[0], 'StrongTestBackupPassphrase!2026');
            $this->assertStringContainsString('CREATE TABLE', $decryptedSql);
            $this->assertStringContainsString('backup-marker@novinpaypos.test', $decryptedSql);

            // Verify multipart sendDocument hit Telegram backups topic (#18)
            Http::assertSent(fn (HttpRequest $req) => str_starts_with($req->url(), 'https://api.telegram.org/bot123456:BACKUP-BOT-TOKEN/sendDocument')
                && $req->isMultipart());
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_new_telegram_topics_fallback_safely_inventory_reserve_alerts_and_support_chat_deep_links(): void
    {
        // CI logs to STDERR; suppress expected Bale-offline/fallback warnings in isolated child process.
        Log::spy();
        Cache::flush();
        $chatDir = sys_get_temp_dir().'/novinpay-phase5-chat-'.bin2hex(random_bytes(10));
        config([
            'app.url' => 'https://novinpaypos.test',
            'support_chat.enabled' => true,
            'support_chat.directory' => $chatDir,
            'observability.inventory.low_stock_threshold' => 3,
            'observability.telegram_topics.enabled' => true,
            'observability.telegram_topics.bot_token' => '123456:TEST-TOPICS-TOKEN',
            'observability.telegram_topics.chat_id' => '-1005775833170',
            'observability.telegram_topics.fallback_to_general' => true,
            // Deliberately leave consultations & support_chat topic IDs UNSET (null) to verify safe General fallback!
            'observability.telegram_topics.threads' => [
                'inventory' => 16,
                'digest' => 999999, // Will simulate 400 thread not found -> auto-retry on General
                'consultations' => null,
                'support_chat' => null,
            ],
            'notifications.bale.enabled' => true,
            'notifications.bale.bot_token' => '987654:BALE-TOKEN',
            'notifications.bale.chat_id' => '777888',
        ]);

        Http::fake([
            'https://api.telegram.org/*' => function (HttpRequest $req) {
                if (($req['message_thread_id'] ?? null) === 999999) {
                    return Http::response(['ok' => false, 'description' => 'Bad Request: message thread not found'], 400);
                }

                return Http::response(['ok' => true], 200);
            },
            'https://tapi.bale.ai/*' => fn () => throw new ConnectionException('Bale offline'),
        ]);

        try {
            User::factory()->superAdmin()->create();
            app(ChatBridge::class)->initialize();
            app(IndependentSupportOperators::class)->enroll('owner', 'IndependentSupport12!');
            auth()->forgetGuards();

            // 1) Consultation submits cleanly even when Bale throws ConnectionException AND consultations topic ID is null
            $token = bin2hex(random_bytes(32));
            $this->withSession(['consultation_token' => $token])
                ->post('/consultation', [
                    'submission_token' => $token,
                    'customer_name' => 'امین کریمی',
                    'contact_phone' => '09124445566',
                    'topic' => array_key_first(Consultation::TOPICS),
                    'question' => 'راهنمایی خرید کارتخوان سیار 4G',
                ])->assertRedirect(route('consultation.confirmation'));

            Http::assertSent(fn (HttpRequest $req) => str_starts_with($req->url(), 'https://api.telegram.org/')
                && ! isset($req['message_thread_id'])
                && str_contains((string) ($req['text'] ?? ''), 'درخواست مشاوره جدید ثبت شد'));

            // 2) Inventory 'reserve' deduction sends concise update to inventory topic (#16)
            $item = InventoryItem::forceCreate([
                'public_id' => (string) Str::uuid(),
                'sku' => 'POS-RES-01',
                'name' => 'PAX S910',
                'condition' => 'new',
                'serialized' => false,
                'on_hand' => 6,
                'reserved' => 1, // available = 5 (> threshold 3)
                'low_stock_threshold' => 0,
                'version' => 1,
            ]);
            app(ObservabilityEvents::class)->inventoryChanged($item, 0, 1, 'reserve', 'رزرو پس از پرداخت سفارش');
            Http::assertSent(fn (HttpRequest $req) => ($req['message_thread_id'] ?? null) === 16
                && str_contains((string) ($req['text'] ?? ''), 'رزرو سفارش')
                && str_contains((string) ($req['text'] ?? ''), '-1')
                && str_contains((string) ($req['text'] ?? ''), 'موجودی آزاد: 5'));

            // 3) Real V2 support chat POST (status=accepted) triggers support_chat notification with deep link button!
            $boot = $this->get('https://novinpaypos.test/support-chat/api.php?get_session=1&bootstrap=1')->assertOk();
            $chatCookie = [session_name(), session_id()];
            $draftId = (string) $boot->json('draft_user_id');
            $csrf = (string) $boot->json('token');

            $this->withCookie($chatCookie[0], $chatCookie[1])
                ->post('https://novinpaypos.test/support-chat/api.php', [
                    'send_message' => '1',
                    'user_id' => $draftId,
                    'message' => 'سلام وقت بخیر، هزینه ارتقای 4G چقدر است؟',
                    'protocol_version' => '2',
                    'client_message_id' => (string) Str::uuid(),
                    'csrf_token' => $csrf,
                ])->assertOk()->assertJsonPath('status', 'accepted');

            // Verify rate-shards isolated file created instead of rewriting operator-security.json on sync/send throttle
            $this->assertDirectoryExists($chatDir.'/runtime/rate-shards');

            Http::assertSent(fn (HttpRequest $req) => str_starts_with($req->url(), 'https://api.telegram.org/')
                && str_contains((string) ($req['text'] ?? ''), 'پیام جدید در چت پشتیبانی')
                && str_contains((string) ($req['reply_markup']['inline_keyboard'][0][0]['url'] ?? ''), '/support?conversation='.rawurlencode($draftId)));

            // 4) Daily digest recovers from invalid thread ID 999999 via General fallback & respects --if-unsent
            $this->assertSame(0, Artisan::call('novinpay:telegram-daily-digest', ['--date' => '2026-11-26', '--if-unsent' => true]));
            $this->assertTrue(Cache::get('novinpay:daily_digest_sent:2026-11-26'));
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $_COOKIE = [];
            app(ChatBridge::class)->finish();
            File::deleteDirectory($chatDir);
        }
    }
}
