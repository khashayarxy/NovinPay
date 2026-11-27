<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Mail\UnifiedNotificationMail;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\FinancialDocument;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class Phase7ActivationDocsDigestSmsEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private CustomerAccount $account;

    private Product $product;

    private InventoryItem $inventoryItem;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://novinpaypos.com',
            'novinpay.docs_zip_password' => 'NovinPaySecret#2026',
            'novinpay.docs_retention_days' => 7,
            'novinpay.docs_abandoned_days' => 30,
            'observability.telegram_topics.enabled' => true,
            'observability.telegram_topics.bot_token' => '123456789:TEST_DOCS_BOT_TOKEN_SECRET',
            'observability.telegram_topics.chat_id' => '-100555666777',
            'observability.telegram_topics.threads.documents' => 88,
            'observability.telegram_topics.threads.digest' => 99,
            'customer.ippanel.enabled' => true,
            'customer.ippanel.api_key' => 'ippanel-test-key-001',
            'customer.ippanel.sender' => '+983000505',
            'customer.ippanel.pattern_code' => 'otpcode123',
            'customer.ippanel.parameter' => 'code',
        ]);

        $this->admin = User::factory()->superAdmin()->create();

        $this->customer = Customer::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'مهدی رضایی',
            'contact_phone' => '09124445566',
            'name_digest' => bin2hex(random_bytes(32)),
            'phone_digest' => RepairIdentity::digest('phone', '09124445566'),
            'identity_digest' => bin2hex(random_bytes(32)),
            'is_active' => true,
            'version' => 1,
        ]);

        $this->account = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'name' => 'مهدی رضایی',
            'phone' => '09124445566',
            'phone_digest' => RepairIdentity::digest('customer-phone', '09124445566'),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);

        $this->product = Product::forceCreate([
            'name' => 'کارتخوان سیار PAX S910 (4G)',
            'slug' => 'pax-s910-4g',
            'category' => ProductCategory::PosTerminal,
            'brand' => 'PAX',
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 6_500_000,
            'is_published' => true,
            'is_sample' => false,
        ]);

        $this->inventoryItem = InventoryItem::forceCreate([
            'public_id' => (string) Str::uuid(),
            'product_id' => $this->product->id,
            'sku' => 'SKU-PAX-S910',
            'name' => 'PAX S910 4G',
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'on_hand' => 10,
            'reserved' => 1,
            'version' => 1,
        ]);
    }

    public function test_unpaid_or_manual_orders_hide_activation_docs_while_online_paid_orders_display_configurable_slots_and_privacy_notice(): void
    {
        [$unpaidOrder] = $this->createOrderWithDocument(settledOnline: false, manualPaid: false);
        [$manualPaidOrder] = $this->createOrderWithDocument(settledOnline: false, manualPaid: true);
        [$onlinePaidOrder] = $this->createOrderWithDocument(settledOnline: true, manualPaid: false);

        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => $this->account->session_version]);

        $this->get(route('account.orders.show', $unpaidOrder->public_id))
            ->assertOk()
            ->assertDontSee('ارسال مدارک فعال‌سازی و ثبت در سامانه مالیاتی');

        // Manual / non-gateway settlement must NOT unlock activation documents (card-to-card stays disabled)
        $this->get(route('account.orders.show', $manualPaidOrder->public_id))
            ->assertOk()
            ->assertDontSee('ارسال مدارک فعال‌سازی و ثبت در سامانه مالیاتی');

        $this->get(route('account.orders.show', $onlinePaidOrder->public_id))
            ->assertOk()
            ->assertSee('ارسال مدارک فعال‌سازی و ثبت در سامانه مالیاتی')
            ->assertSee('مدارک شما به‌صورت رمزنگاری‌شده پردازش شده و ۷ روز پس از تحویل دستگاه به‌صورت خودکار حذف می‌شوند.')
            ->assertSee('در انتظار ارسال')
            ->assertSee('تصویر روی کارت ملی')
            ->assertSee('تصویر پشت کارت ملی')
            ->assertSee('کد پستی ۱۰ رقمی')
            ->assertSee('کد مالیاتی (اینتاکد)');

        // Also verify unauthenticated short-order tracking /o/{code} after phone verification displays the upload section
        auth('customer')->logout();
        $shortCode = strtoupper(substr($onlinePaidOrder->public_id, 0, 8));
        $this->post(route('account.orders.track', ['code' => $shortCode]), ['contact_phone' => '09124445566'])
            ->assertOk()
            ->assertSee('ارسال مدارک فعال‌سازی و ثبت در سامانه مالیاتی')
            ->assertSee('در انتظار ارسال');
    }

    public function test_aes256_zip_bundles_generic_names_sends_to_telegram_documents_topic_and_immediately_deletes_host_files(): void
    {
        [$order] = $this->createOrderWithDocument(settledOnline: true);
        $shortCode = strtoupper(substr($order->public_id, 0, 8));

        $capturedZipBytes = null;
        $capturedPayload = [];

        Http::fake(function (HttpRequest $request) use (&$capturedZipBytes, &$capturedPayload) {
            if (str_contains($request->url(), '/sendDocument')) {
                foreach ($request->data() as $part) {
                    if (($part['name'] ?? '') === 'document') {
                        $capturedZipBytes = $part['contents'];
                        $capturedPayload['filename'] = $part['filename'] ?? null;
                    } else {
                        $capturedPayload[$part['name'] ?? ''] = $part['contents'] ?? null;
                    }
                }

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => 774411,
                        'chat' => ['id' => -100555666777],
                    ],
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => $this->account->session_version]);

        $uploads = [
            'national_card_front' => UploadedFile::fake()->image('my-personal-mellicard-front-0012345678.jpg', 600, 400),
            'national_card_back' => UploadedFile::fake()->image('my-personal-mellicard-back-0012345678.jpg', 600, 400),
            'birth_certificate' => UploadedFile::fake()->image('shenasname-mahdi.png', 600, 400),
            'bank_card_1' => UploadedFile::fake()->create('sheba-certificate.pdf', 120, 'application/pdf'),
            'bank_card_2' => UploadedFile::fake()->image('bank-card-2.jpg', 500, 400),
            'tax_code' => UploadedFile::fake()->create('intacode.pdf', 90, 'application/pdf'),
        ];
        $extra = [
            UploadedFile::fake()->image('javaz-kasb-mahdi.jpg', 500, 400),
        ];

        $this->post(route('account.orders.documents.store', $order->public_id), [
            'postal_code' => '3351611828',
            'slots' => $uploads,
            'extra_files' => $extra,
        ])->assertRedirect(route('account.orders.show', $order->public_id));

        // 1. Immediate Zero Host Retention: private directory must NOT exist on disk after Telegram confirmation!
        $privateDir = storage_path('app/private/activation-docs/'.$order->public_id);
        $this->assertDirectoryDoesNotExist($privateDir);

        // 2. Database stores only Telegram metadata & status
        $order->refresh();
        $this->assertSame('pending_review', $order->documents_status);
        $this->assertSame('-100555666777', $order->telegram_chat_id);
        $this->assertSame(774411, $order->telegram_message_id);
        $this->assertNotNull($order->documents_submitted_at);
        $this->assertNull($order->documents_staging_dir);

        // 3. Telegram payload check (topic thread ID, caption, inline admin order button)
        $this->assertSame('88', (string) ($capturedPayload['message_thread_id'] ?? ''));
        $this->assertStringContainsString('#'.$shortCode, (string) ($capturedPayload['caption'] ?? ''));
        $this->assertStringContainsString('مهدی رضایی', (string) ($capturedPayload['caption'] ?? ''));
        $this->assertStringContainsString('PAX S910', (string) ($capturedPayload['caption'] ?? ''));
        $markup = json_decode((string) ($capturedPayload['reply_markup'] ?? '{}'), true);
        $this->assertSame(route('admin.orders.show', $order), $markup['inline_keyboard'][0][0]['url'] ?? null);

        // 4. Inspect actual uploaded ZIP: verify ZipArchive::EM_AES_256 on EVERY entry, generic non-PII names, and password protection
        $this->assertIsString($capturedZipBytes);
        $tmpZip = tempnam(sys_get_temp_dir(), 'np_aes_verify_');
        file_put_contents($tmpZip, $capturedZipBytes);
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($tmpZip) === true);

            $entryNames = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entryNames[] = $stat['name'];
                $this->assertSame(
                    ZipArchive::EM_AES_256,
                    $stat['encryption_method'],
                    "Entry [{$stat['name']}] must be encrypted with ZipArchive::EM_AES_256"
                );
                $this->assertStringNotContainsString('mahdi', strtolower($stat['name']));
                $this->assertStringNotContainsString('0012345678', $stat['name']);
            }

            $this->assertEqualsCanonicalizing([
                '01-kart-meli-roo.jpg',
                '02-kart-meli-posht.jpg',
                '03-shenasname.png',
                '04-kart-banki-1.pdf',
                '05-kart-banki-2.jpg',
                '06-kode-maliati.pdf',
                '07-takmili-1.jpg',
                'info.txt',
            ], $entryNames);

            // Wrong password must NOT decrypt info.txt
            $zip->setPassword('WrongPassword123');
            $this->assertFalse($zip->getFromName('info.txt'));

            // Configured DOCS_ZIP_PASSWORD decrypts info.txt cleanly
            $zip->setPassword('NovinPaySecret#2026');
            $infoText = $zip->getFromName('info.txt');
            $this->assertIsString($infoText);
            $this->assertStringContainsString('#'.$shortCode, $infoText);
            $this->assertStringContainsString('مهدی رضایی', $infoText);
            $this->assertStringContainsString('09124445566', $infoText);
            $this->assertStringContainsString('PAX S910', $infoText);
            $zip->close();
        } finally {
            @unlink($tmpZip);
        }
    }

    public function test_telegram_failure_retains_only_encrypted_zip_safely_alerts_admins_and_cleans_up_on_retry(): void
    {
        Mail::fake();
        config([
            'notifications.email.enabled' => true,
            'notifications.admin.emails' => ['ops@novinpaypos.com'],
        ]);

        [$order] = $this->createOrderWithDocument(settledOnline: true);
        $privateDir = storage_path('app/private/activation-docs/'.$order->public_id);

        try {
            Http::fake([
                'https://api.telegram.org/*/sendDocument' => Http::sequence()
                    ->push(['ok' => false, 'description' => 'Bad Gateway'], 502)
                    ->push(['ok' => true, 'result' => ['message_id' => 998877, 'chat' => ['id' => -100555666777]]], 200),
                '*' => Http::response(['ok' => true], 200),
            ]);

            $this->actingAs($this->account, 'customer')
                ->withSession(['customer_session_version' => $this->account->session_version]);

            $this->post(route('account.orders.documents.store', $order->public_id), [
                'postal_code' => '3351611828',
                'slots' => $this->sampleUploads(),
            ])->assertRedirect(route('account.orders.show', $order->public_id));

            $order->refresh();
            $this->assertSame('pending_review', $order->documents_status);
            $this->assertNull($order->telegram_message_id);
            $this->assertNotNull($order->documents_staging_dir);
            $this->assertDirectoryExists($privateDir);

            // Only the AES-256 encrypted .zip is retained; zero raw plaintext files remain on disk!
            $files = File::allFiles($privateDir);
            $this->assertCount(1, $files);
            $this->assertStringEndsWith('.zip', $files[0]->getFilename());

            Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->statusBadge === 'نگهداری اضطراری مدارک');

            // Admin retries Telegram once Telegram recovers -> delivers and immediately purges local file!
            auth('customer')->logout();
            $this->actingAs($this->admin, 'web')
                ->post(route('admin.orders.documents.retry', $order))
                ->assertRedirect(route('admin.orders.show', $order));

            $this->assertDirectoryDoesNotExist($privateDir);
            $order->refresh();
            $this->assertSame(998877, $order->telegram_message_id);
            $this->assertNull($order->documents_staging_dir);
        } finally {
            File::deleteDirectory($privateDir);
        }
    }

    public function test_admin_revision_approval_immediate_purge_scheduled_auto_purge_and_three_ippanel_patterns(): void
    {
        Cache::flush();
        config([
            'notifications.sms.patterns.documents_revision' => 'patDocRev01',
            'notifications.sms.patterns.documents_approved' => 'patDocApp02',
            'notifications.sms.patterns.consultation_submitted' => 'patConsult03',
        ]);

        Http::fake([
            'https://edge.ippanel.com/v1/api/send' => Http::response(['meta' => ['status' => true]], 200),
            'https://api.telegram.org/*/deleteMessage' => Http::response(['ok' => true], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 501, 'chat' => ['id' => -100555666777]]], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        [$order] = $this->createOrderWithDocument(settledOnline: true);
        $shortCode = strtoupper(substr($order->public_id, 0, 8));
        $order->forceFill([
            'documents_status' => 'pending_review',
            'telegram_chat_id' => '-100555666777',
            'telegram_message_id' => 55501,
            'documents_submitted_at' => now(),
        ])->save();

        // 1. Admin marks needs_revision -> triggers IPPanel documents_revision (order_code, reason)
        $this->actingAs($this->admin)
            ->post(route('admin.orders.documents.revision', $order), [
                'reason' => 'تصویر کارت ملی تار است؛ لطفاً واضح‌تر ارسال شود.',
            ])
            ->assertRedirect(route('admin.orders.show', $order));

        $order->refresh();
        $this->assertSame('needs_revision', $order->documents_status);
        $this->assertSame('تصویر کارت ملی تار است؛ لطفاً واضح‌تر ارسال شود.', $order->documents_revision_reason);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://edge.ippanel.com/v1/api/send'
            && ($r['code'] ?? null) === 'patDocRev01'
            && ($r['params']['code'] ?? null) === $shortCode
            && str_contains((string) ($r['params']['reason'] ?? ''), 'تصویر کارت ملی تار است'));

        // Customer sees needs_revision reason + re-upload form
        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => $this->account->session_version])
            ->get(route('account.orders.show', $order->public_id))
            ->assertOk()
            ->assertSee('نیاز به اصلاح')
            ->assertSee('تصویر کارت ملی تار است')
            ->assertSee('ارسال مجدد مدارک اصلاح‌شده');

        // 2. Admin marks approved -> triggers IPPanel documents_approved (code)
        auth('customer')->logout();
        $this->actingAs($this->admin, 'web')
            ->post(route('admin.orders.documents.approve', $order))
            ->assertRedirect(route('admin.orders.show', $order));

        $order->refresh();
        $this->assertSame('approved', $order->documents_status);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://edge.ippanel.com/v1/api/send'
            && ($r['code'] ?? null) === 'patDocApp02'
            && ($r['params'] ?? null) === ['code' => $shortCode]);

        // 3. Consultation form submission -> triggers IPPanel consultation_submitted (name)
        $consultationToken = bin2hex(random_bytes(32));
        $this->withSession(['consultation_token' => $consultationToken])
            ->post('/consultation', [
                'submission_token' => $consultationToken,
                'customer_name' => 'نگین کریمی',
                'contact_phone' => '09127778899',
                'topic' => 'purchase',
                'question' => 'نیازمند راهنمایی خرید کارتخوان سیار برای فروشگاه پوشاک هستم.',
            ])->assertRedirect(route('consultation.confirmation'));

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://edge.ippanel.com/v1/api/send'
            && ($r['code'] ?? null) === 'patConsult03'
            && ($r['params'] ?? null) === ['name' => 'نگین کریمی']);

        // Empty pattern code gracefully skips SMS without failing the request
        config(['notifications.sms.patterns.consultation_submitted' => '']);
        $secondToken = bin2hex(random_bytes(32));
        $this->withSession(['consultation_token' => $secondToken])
            ->post('/consultation', [
                'submission_token' => $secondToken,
                'customer_name' => 'سامان نادری',
                'contact_phone' => '09123334455',
                'topic' => 'service',
                'question' => 'درخواست راهنمایی درباره ثبت مالیاتی کارتخوان سیار دارم.',
            ])->assertRedirect(route('consultation.confirmation'));

        // 4. Admin Immediate Purge button ("پاکسازی فوری مدارک")
        $this->actingAs($this->admin, 'web')
            ->post(route('admin.orders.documents.purge', $order))
            ->assertRedirect(route('admin.orders.show', $order));

        $order->refresh();
        $this->assertNotNull($order->documents_purged_at);
        $this->assertNull($order->telegram_message_id);
        $this->assertSame('approved', $order->documents_status); // Order/approval history preserved!
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/deleteMessage') && ($r['message_id'] ?? null) === 55501);

        // 5. Scheduled 7-Day Delivered / Cancelled / >30-Day Abandoned Auto-Purge
        [$deliveredRecent] = $this->createOrderWithDocument(settledOnline: true);
        $deliveredRecent->forceFill(['status' => 'fulfilled', 'fulfilled_at' => now()->subDays(3), 'telegram_chat_id' => '-100555666777', 'telegram_message_id' => 601])->save();

        [$deliveredExpired] = $this->createOrderWithDocument(settledOnline: true);
        $deliveredExpired->forceFill(['status' => 'fulfilled', 'fulfilled_at' => now()->subDays(8), 'telegram_chat_id' => '-100555666777', 'telegram_message_id' => 602])->save();

        [$cancelledOrder] = $this->createOrderWithDocument(settledOnline: false);
        $cancelledOrder->forceFill(['status' => 'cancelled', 'telegram_chat_id' => '-100555666777', 'telegram_message_id' => 603])->save();

        [$abandonedOrder] = $this->createOrderWithDocument(settledOnline: true);
        $abandonedOrder->forceFill(['status' => 'confirmed', 'created_at' => now()->subDays(31), 'telegram_chat_id' => '-100555666777', 'telegram_message_id' => 604])->save();

        // Simulate Telegram returning "message to delete not found" (400) for one message to verify graceful non-throwing behavior!
        Http::fake([
            'https://api.telegram.org/*/deleteMessage' => Http::sequence()
                ->push(['ok' => true], 200)
                ->push(['ok' => false, 'description' => 'Bad Request: message to delete not found'], 400)
                ->push(['ok' => true], 200),
        ]);

        $this->artisan('novinpay:purge-activation-documents')
            ->expectsOutputToContain('purged=3')
            ->assertExitCode(0);

        $this->assertNull($deliveredRecent->fresh()->documents_purged_at);
        $this->assertSame(601, $deliveredRecent->fresh()->telegram_message_id);

        $this->assertNotNull($deliveredExpired->fresh()->documents_purged_at);
        $this->assertNotNull($cancelledOrder->fresh()->documents_purged_at);
        $this->assertNotNull($abandonedOrder->fresh()->documents_purged_at);
        $this->assertDatabaseHas('sales_orders', ['id' => $deliveredExpired->id]); // Order history preserved
    }

    public function test_tabular_telegram_daily_digest_and_refined_png_logo_rtl_email_template(): void
    {
        Cache::flush();
        $capturedTelegram = null;
        Http::fake(function (HttpRequest $request) use (&$capturedTelegram) {
            if (str_contains($request->url(), '/sendMessage')) {
                $capturedTelegram = $request->data();
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200);
        });

        $this->artisan('novinpay:telegram-daily-digest', ['--date' => '2026-11-26'])
            ->assertExitCode(0);

        $this->assertIsArray($capturedTelegram);
        $keyboard = $capturedTelegram['reply_markup']['inline_keyboard'] ?? null;
        $this->assertIsArray($keyboard);
        $this->assertCount(5, $keyboard);

        $allButtonLabels = implode(' | ', array_map(
            fn (array $row) => implode(' :: ', array_column($row, 'text')),
            $keyboard
        ));
        $this->assertStringContainsString('تعداد سفارش‌های موفق امروز', $allButtonLabels);
        $this->assertStringContainsString('تعداد درخواست‌های تعمیر جدید', $allButtonLabels);
        $this->assertStringContainsString('تعداد درخواست‌های مشاوره / تماس جدید', $allButtonLabels);
        $this->assertStringContainsString('مجموع فروش آنلاین (زیبال)', $allButtonLabels);
        $this->assertStringContainsString('وضعیت آخرین بکاپ دیتابیس', $allButtonLabels);

        // Verify refined PNG-logo RTL email template
        $this->assertFileExists(public_path('brand/novinpay-email-logo.png'));
        $mailable = new UnifiedNotificationMail(
            mailSubject: 'بررسی قالب جدید ایمیل نوین‌پی',
            heading: 'تأیید پرداخت آنلاین سفارش',
            intro: 'پرداخت شما با موفقیت ثبت شد.',
            fields: ['کد سفارش' => 'NP998877', 'مبلغ' => '۶٬۵۰۰٬۰۰۰ تومان'],
            actionLabel: 'مشاهده سفارش',
            actionUrl: 'https://novinpaypos.com/account',
            statusBadge: 'پرداخت موفق',
            statusTone: 'success',
        );
        $html = $mailable->render();
        $this->assertMatchesRegularExpression('/src="(?:cid:[^"]+|data:image\/png;base64,[^"]+|https:\/\/novinpaypos\.com\/brand\/novinpay-email-logo\.png)"/', $html);
        $this->assertStringContainsString('width="36" height="36"', $html);

        // Also send through the array transport so real Symfony Mailer CID embedding runs and verify inline CID PNG
        config(['mail.default' => 'array']);
        app('mail.manager')->forgetMailers();
        Mail::to('customer@example.com')->send($mailable);
        $sentMessages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertNotEmpty($sentMessages);
        $originalMessage = $sentMessages[0]->getOriginalMessage();
        $sentHtmlBody = (string) $originalMessage->getHtmlBody();
        $rawMime = $originalMessage->toString();
        $this->assertStringContainsString('src="cid:', $sentHtmlBody);
        $this->assertStringContainsString('Content-Type: image/png; name=novinpay-email-logo.png', $rawMime);
        $this->assertStringNotContainsString('.svg', $html);
        $this->assertStringNotContainsString('.webp', $html);
        $this->assertStringNotContainsString('.avif', $html);
        $this->assertStringContainsString('#098CF7', $html);
        $this->assertStringContainsString('این پیام به‌صورت خودکار ارسال شده است.', $html);

        // Badge must appear BEFORE <h1> (above title, not squeezed in header)
        $headerEndPos = strpos($html, 'np-mail-body');
        $badgePos = strpos($html, 'پرداخت موفق');
        $h1Pos = strpos($html, '<h1');
        $this->assertGreaterThan($headerEndPos, $badgePos);
        $this->assertLessThan($h1Pos, $badgePos);
    }

    /**
     * @return array{0: SalesOrder, 1: FinancialDocument}
     */
    private function createOrderWithDocument(bool $settledOnline = true, bool $manualPaid = false): array
    {
        $order = SalesOrder::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'customer_account_id' => $this->account->id,
            'checkout_key' => (string) Str::uuid(),
            'checkout_digest' => bin2hex(random_bytes(32)),
            'status' => ($settledOnline || $manualPaid) ? 'confirmed' : 'draft',
            'currency' => 'TOMAN',
            'total' => '6500000.00',
            'version' => 1,
        ]);

        SalesOrderLine::forceCreate([
            'sales_order_id' => $order->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'sku' => $this->inventoryItem->sku,
            'description' => $this->product->name,
            'condition' => ProductCondition::New->value,
            'quantity' => 1,
            'revision' => 1,
            'is_current' => true,
            'unit_price' => '6500000.00',
            'line_total' => '6500000.00',
        ]);

        $chargedMinor = 650_000_000;
        $paidMinor = ($settledOnline || $manualPaid) ? $chargedMinor : 0;

        $document = FinancialDocument::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'sales_order_id' => $order->id,
            'created_by' => $this->admin->id,
            'source_amount_minor' => $chargedMinor,
            'source_version' => 1,
            'charged_minor' => $chargedMinor,
            'paid_minor' => $paidMinor,
            'version' => 1,
            'created_at' => now(),
        ]);

        if ($settledOnline) {
            PaymentAttempt::forceCreate([
                'public_id' => (string) Str::uuid(),
                'financial_document_id' => $document->id,
                'customer_account_id' => $this->account->id,
                'operation_key' => (string) Str::uuid(),
                'amount_minor' => $chargedMinor,
                'amount_rial' => 65_000_000,
                'document_version' => 1,
                'merchant_digest' => bin2hex(random_bytes(32)),
                'track_id' => (string) random_int(100000000, 999999999),
                'state' => 'settled',
                'verified_at' => now(),
            ]);
        }

        return [$order, $document];
    }

    /**
     * @return array<string, UploadedFile>
     */
    private function sampleUploads(): array
    {
        return [
            'national_card_front' => UploadedFile::fake()->image('card-front.jpg', 500, 350),
            'national_card_back' => UploadedFile::fake()->image('card-back.jpg', 500, 350),
            'birth_certificate' => UploadedFile::fake()->image('birth.png', 500, 350),
            'bank_card_1' => UploadedFile::fake()->create('card1.pdf', 100, 'application/pdf'),
            'tax_code' => UploadedFile::fake()->create('tax.pdf', 100, 'application/pdf'),
        ];
    }
}
