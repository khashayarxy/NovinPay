<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\RepairStage;
use App\Enums\StockStatus;
use App\Mail\UnifiedNotificationMail;
use App\Models\Consultation;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\FinancialDocument;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\RepairCase;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\ActivationDocumentsService;
use App\Services\Notifications\PatternSms;
use App\Services\ShoppingCart;
use App\Services\SimpleRepairRecords;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase8UiRepairPaymentEmailSmsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private CustomerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://novinpaypos.com',
            'payments.zibal.enabled' => true,
            'payments.zibal.live_requests' => true,
            'payments.zibal.merchant' => 'zibal-merchant-test',
            'payments.zibal.callback_url' => 'https://novinpaypos.com/payments/zibal/callback',
            'customer.ippanel.enabled' => true,
            'customer.ippanel.api_key' => 'ippanel-phase8-key',
            'customer.ippanel.sender' => '+983000505',
            'customer.ippanel.pattern_code' => 'otp123',
            'customer.ippanel.parameter' => 'code',
            'notifications.email.enabled' => true,
            'notifications.admin.emails' => ['admin@novinpaypos.com'],
        ]);

        $this->admin = User::factory()->superAdmin()->create();

        $this->customer = Customer::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'سارا محمدی',
            'contact_phone' => '09126667788',
            'name_digest' => RepairIdentity::digest('customer-name', 'سارا محمدی'),
            'phone_digest' => RepairIdentity::digest('phone', '09126667788'),
            'identity_digest' => bin2hex(random_bytes(32)),
            'is_active' => true,
            'version' => 1,
        ]);

        $this->account = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'name' => 'سارا محمدی',
            'phone' => '09126667788',
            'phone_digest' => RepairIdentity::digest('customer-phone', '09126667788'),
            'email' => 'sara.mohammadi@example.com',
            'email_digest' => hash_hmac('sha256', 'sara.mohammadi@example.com', (string) config('app.key')),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);
    }

    public function test_floating_labels_navigation_renaming_and_recovery_card_layout_across_storefront_forms(): void
    {
        // 1. Homepage: desktop header dropdown, mobile hamburger menu, and footer navigation labels
        $homeHtml = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('درخواست مشاوره', $homeHtml);
        $this->assertStringContainsString('href="'.route('consultation').'"', $homeHtml);
        $this->assertStringContainsString('درخواست تعمیر', $homeHtml);
        $this->assertStringContainsString('پیگیری تعمیر', $homeHtml);
        $this->assertStringNotContainsString('<span>تعمیرات کارتخوان</span>', $homeHtml);
        $this->assertStringNotContainsString('<span>پیگیری تعمیرات</span>', $homeHtml);

        // 2. /login, /consultation, /repair, /repair/track, /repair/track/recovery use RTL floating labels
        foreach (['/login', '/consultation', '/repair', '/repair/track', '/repair/track/recovery'] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();
            $this->assertStringContainsString('np-float-field', $html, "Missing .np-float-field on {$uri}");
            $this->assertStringContainsString('data-float-field', $html, "Missing data-float-field on {$uri}");
        }

        // 3. /repair/track/recovery heading & shortened helper sit INSIDE .public-panel and full-width CTA class is applied
        $recoveryHtml = $this->get('/repair/track/recovery')->assertOk()->getContent();
        $panelPos = strpos($recoveryHtml, 'repair-recovery-panel');
        $headingPos = strpos($recoveryHtml, 'بازیابی کد پیگیری تعمیر');
        $helperPos = strpos($recoveryHtml, 'شماره موبایل ثبت‌شده را وارد کنید تا کد تأیید پیامک شود.');
        $this->assertNotFalse($panelPos);
        $this->assertNotFalse($headingPos);
        $this->assertNotFalse($helperPos);
        $this->assertGreaterThan($panelPos, $headingPos, 'Recovery heading must be inside the white .public-panel card');
        $this->assertGreaterThan($panelPos, $helperPos, 'Shortened recovery helper text must be inside the white .public-panel card');
        $this->assertStringContainsString('repair-recovery-submit', $recoveryHtml);

        // 4. Authenticated /account profile, addresses, security, and /cart/checkout forms use floating labels
        $product = Product::forceCreate([
            'name' => 'کارتخوان سیار Newland ME31',
            'slug' => 'newland-me31-p8',
            'summary' => 'نسل چهارم',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'is_published' => true,
            'is_sample' => false,
            'price' => 7_200_000,
        ]);
        InventoryItem::forceCreate([
            'public_id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'sku' => 'NP-ME31-P8',
            'name' => $product->name,
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'on_hand' => 5,
            'reserved' => 0,
            'version' => 1,
        ]);

        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->withUnencryptedCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('b', 64));

        foreach (['/account?section=profile', '/account?section=addresses', '/account?section=security'] as $accountUri) {
            $accHtml = $this->get('https://novinpaypos.com'.$accountUri)->assertOk()->getContent();
            $this->assertStringContainsString('np-float-field', $accHtml, "Missing .np-float-field on {$accountUri}");
        }

        $cartView = $this->get('https://novinpaypos.com/cart')->assertOk();
        $this->post('https://novinpaypos.com/cart/products/'.$product->slug, [
            'cart_context' => $cartView->viewData('proof'),
            'cart_revision' => $cartView->viewData('revision'),
            'quantity' => 1,
        ])->assertRedirect();
        $checkoutHtml = $this->get('https://novinpaypos.com/cart/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('np-float-field', $checkoutHtml);
    }

    public function test_direct_zibal_repair_payment_from_user_dashboard_with_idempotency_callback_receipt_and_notifications(): void
    {
        Mail::fake();
        Cache::flush();

        $capturedOrderId = null;
        Http::fake(function (HttpRequest $req) use (&$capturedOrderId) {
            if ($req->url() === 'https://gateway.zibal.ir/v1/request') {
                $capturedOrderId = $req['orderId'] ?? null;

                return Http::response(['result' => 100, 'trackId' => 8877665544], 200);
            }
            if ($req->url() === 'https://gateway.zibal.ir/v1/verify') {
                return Http::response([
                    'result' => 100,
                    'status' => 1,
                    'amount' => 4_500_000,
                    'orderId' => $capturedOrderId,
                    'refNumber' => 'ZBL-REP-998877',
                    'paidAt' => '2026-11-27T05:30:00',
                ], 200);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200);
        });

        // Create repair case via official intake + unpaid financial document (450,000 Toman = 45,000,000 minor)
        $intakeToken = bin2hex(random_bytes(32));
        $this->actingAs($this->admin, 'web')
            ->withSession(['repair_intake_token' => $intakeToken])
            ->post('/admin/repairs/intake', [
                'simple_intake' => '1',
                'submission_token' => $intakeToken,
                'customer_name' => $this->customer->name,
                'contact_phone' => $this->customer->contact_phone,
                'device_model' => 'PAX S910',
                'device_serial' => '',
                'issue' => 'تعویض باتری و تعمیر سوکت شارژ',
                'physical_condition' => 'سالم',
            ])->assertRedirect();

        $case = RepairCase::query()->sole();
        $case->forceFill([
            'customer_id' => $this->customer->id,
            'customer_account_id' => $this->account->id,
            'status' => RepairStage::Ready->value,
            'repair_price_toman' => 450_000,
        ])->save();
        $expectedRef = (string) $case->tracking_reference;

        $document = FinancialDocument::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'repair_case_id' => $case->id,
            'source_version' => 1,
            'source_amount_minor' => 45_000_000,
            'charged_minor' => 45_000_000,
            'paid_minor' => 0,
            'version' => 1,
            'created_by' => $this->admin->id,
            'created_at' => now(),
        ]);
        $opId = DB::table('financial_operations')->insertGetId([
            'operation_key' => (string) Str::uuid(),
            'actor_id' => $this->admin->id,
            'action' => 'issue',
            'payload_digest' => bin2hex(random_bytes(32)),
            'created_at' => now(),
        ]);
        $entryId = DB::table('financial_entries')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'financial_operation_id' => $opId,
            'financial_document_id' => $document->id,
            'actor_id' => $this->admin->id,
            'kind' => 'charge',
            'category' => 'repair',
            'charge_delta' => 45_000_000,
            'receipt_delta' => 0,
            'expense_delta' => 0,
            'note' => 'هزینه تعمیر اعلام‌شده',
            'created_at' => now(),
        ]);
        DB::table('financial_operations')->where('id', $opId)->update(['result_id' => $entryId]);

        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1]);

        // 1. Both /account?section=repairs and /account/repairs/{record} display the direct Zibal payment button
        $this->get(route('account.index', ['section' => 'repairs']))
            ->assertOk()
            ->assertSee(route('account.repairs.pay', $case->public_id), false)
            ->assertSee('پرداخت آنلاین با زیبال');

        $this->get(route('account.repairs.show', $case->public_id))
            ->assertOk()
            ->assertSee(route('account.repairs.pay', $case->public_id), false)
            ->assertSee('پرداخت آنلاین با زیبال');

        // 2. Initiate Zibal payment directly from dashboard + verify idempotency with same operation_key
        $opKey = (string) Str::uuid();
        $firstResp = $this->post(route('account.repairs.pay', $case->public_id), [
            'operation_key' => $opKey,
        ]);
        $attempt = PaymentAttempt::query()->where('financial_document_id', $document->id)->sole();
        $firstResp->assertRedirect(route('payments.connect', $attempt->public_id));
        $this->assertSame('pending', $attempt->state);
        $this->assertSame('8877665544', $attempt->track_id);
        $this->assertSame(4_500_000, $attempt->amount_rial);

        // Idempotent replay with same operation_key returns the exact same attempt without calling Zibal twice
        $this->post(route('account.repairs.pay', $case->public_id), [
            'operation_key' => $opKey,
        ])->assertRedirect(route('payments.connect', $attempt->public_id));
        $this->assertSame(1, PaymentAttempt::query()->where('financial_document_id', $document->id)->count());

        // 3. Zibal callback verifies, settles document, dispatches admin + customer receipt emails, and shows receipt on dashboard
        $this->get(route('payments.callback', [
            'trackId' => '8877665544',
            'success' => '1',
            'status' => '2',
            'orderId' => $attempt->public_id,
        ]))->assertRedirect(route('payments.result', $attempt->public_id));

        $document->refresh();
        $this->assertSame(45_000_000, (int) $document->paid_minor);
        $this->assertSame('settled', $document->settlement());

        $this->get(route('payments.result', $attempt->public_id))
            ->assertOk()
            ->assertSee($expectedRef);

        $this->get(route('account.repairs.show', $case->public_id))
            ->assertOk()
            ->assertSee('پرداخت‌شده')
            ->assertSee('رسید پرداخت')
            ->assertDontSee(route('account.repairs.pay', $case->public_id), false);

        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->hasTo('admin@novinpaypos.com') && str_contains($m->mailSubject, 'پرداخت هزینه تعمیر'));
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->hasTo('sara.mohammadi@example.com') && str_contains($m->mailSubject, 'رسید پرداخت هزینه تعمیر'));
    }

    public function test_generic_repair_created_sms_removed_and_ultra_minimal_white_customer_emails_fire_on_all_events(): void
    {
        Mail::fake();
        Cache::flush();
        Cache::store('database')->flush();

        // 1. Generic repair_created key is removed from PatternSms::PATTERNS; blank flow-specific pattern sends ZERO SMS
        $this->assertArrayNotHasKey('repair_created', PatternSms::PATTERNS);

        config([
            'notifications.pattern_sms.enabled' => true,
            'notifications.pattern_sms.provider' => 'ippanel',
            'notifications.pattern_sms.patterns.repair_created_in_person' => '',
            'notifications.pattern_sms.patterns.repair_created_postal' => 'patPostalOnly99',
            'notifications.pattern_sms.patterns.repair_created_courier' => '',
            'notifications.sms.enabled' => true,
            'notifications.admin.phones' => ['09120001122'],
            'notifications.sms.patterns.admin_repair_submitted' => '',
        ]);

        Http::fake([
            'https://edge.ippanel.com/v1/api/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [501]]], 200),
            '*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);

        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1]);

        // In-person repair with blank repair_created_in_person -> 0 SMS sent, NO generic fallback, NO duplicate SMS, BUT customer email IS sent!
        $this->get(route('repair'));
        $this->post(route('repair.store'), [
            'customer_name' => 'سارا محمدی',
            'device_brand' => 'PAX',
            'device_model' => 'S910',
            'issue' => 'دستگاه هنگام چاپ رسید خاموش می‌شود.',
            'province' => 'تهران',
            'city' => 'شهریار',
            'handover_method' => 'in_person',
            'submission_token' => session('repair_submission_token'),
        ])->assertRedirect(route('repair.confirmation'));

        $smsCalls = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'ippanel.com'));
        $this->assertCount(0, $smsCalls, 'Blank flow-specific repair pattern must gracefully skip without any generic fallback SMS');
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->hasTo('sara.mohammadi@example.com') && str_contains($m->mailSubject, 'درخواست تعمیر شما ثبت شد'));

        // Postal repair with configured repair_created_postal -> sends EXACTLY ONE SMS with patPostalOnly99 (never duplicate)
        Cache::flush();
        Cache::store('database')->flush();
        $this->get(route('repair'));
        $this->post(route('repair.store'), [
            'customer_name' => 'سارا محمدی',
            'device_brand' => 'Newland',
            'device_model' => 'ME31',
            'issue' => 'صفحه کلید عدد ۵ و ۸ کار نمی‌کند.',
            'province' => 'فارس',
            'city' => 'شیراز',
            'handover_method' => 'self_ship',
            'submission_token' => session('repair_submission_token'),
        ])->assertRedirect(route('repair.confirmation'));

        $postalSmsCalls = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'ippanel.com'))->values();
        $this->assertCount(1, $postalSmsCalls);
        $this->assertSame('patPostalOnly99', $postalSmsCalls[0][0]['code']);

        // 2. Repair status update -> sends customer email
        $intakeToken = bin2hex(random_bytes(32));
        $this->actingAs($this->admin, 'web')
            ->withSession(['repair_intake_token' => $intakeToken])
            ->post('/admin/repairs/intake', [
                'simple_intake' => '1',
                'submission_token' => $intakeToken,
                'customer_name' => 'سارا محمدی',
                'contact_phone' => '09126667788',
                'device_model' => 'PAX S910',
                'device_serial' => '',
                'issue' => 'خرابی چاپگر',
                'physical_condition' => 'سالم',
            ])->assertRedirect();

        $case = RepairCase::query()->latest('id')->firstOrFail();
        app(SimpleRepairRecords::class)->save($this->admin, $case, [
            'version' => $case->version,
            'status' => 'ready',
            'repair_cost' => '380000',
            'delivery_method' => 'in_person',
        ]);
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->hasTo('sara.mohammadi@example.com') && str_contains($m->mailSubject, 'به‌روزرسانی وضعیت تعمیر'));

        // 3. Activation documents revision & approval -> send customer emails
        $product = Product::forceCreate([
            'name' => 'کارتخوان سیار PAX S910',
            'slug' => 'pax-s910-p8',
            'summary' => 'سیار',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'is_published' => true,
            'is_sample' => false,
            'price' => 6_500_000,
        ]);
        $inv = InventoryItem::forceCreate([
            'public_id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'sku' => 'NP-S910-P8',
            'name' => $product->name,
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'on_hand' => 5,
            'reserved' => 1,
            'version' => 1,
        ]);
        $order = SalesOrder::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'customer_account_id' => $this->account->id,
            'checkout_key' => (string) Str::uuid(),
            'checkout_digest' => bin2hex(random_bytes(32)),
            'status' => 'confirmed',
            'currency' => 'TOMAN',
            'total' => '6500000.00',
            'documents_status' => 'pending_review',
            'version' => 1,
        ]);
        SalesOrderLine::forceCreate([
            'sales_order_id' => $order->id,
            'inventory_item_id' => $inv->id,
            'sku' => $inv->sku,
            'description' => $product->name,
            'condition' => ProductCondition::New->value,
            'quantity' => 1,
            'revision' => 1,
            'unit_price' => '6500000.00',
            'line_total' => '6500000.00',
        ]);

        app(ActivationDocumentsService::class)->requestRevision($order, $this->admin, 'تصویر شناسنامه ناخوانا است.');
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->hasTo('sara.mohammadi@example.com') && str_contains($m->mailSubject, 'نیاز به اصلاح مدارک'));

        app(ActivationDocumentsService::class)->approve($order, $this->admin);
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->hasTo('sara.mohammadi@example.com') && str_contains($m->mailSubject, 'تأیید مدارک فعال‌سازی'));

        // 4. Consultation submitted (even as guest via phone_digest lookup) -> sends customer email when matching account email exists
        auth('customer')->logout();
        $consultToken = bin2hex(random_bytes(32));
        $this->withSession(['consultation_token' => $consultToken])
            ->post('/consultation', [
                'submission_token' => $consultToken,
                'customer_name' => 'سارا محمدی',
                'contact_phone' => '09126667788',
                'topic' => array_key_first(Consultation::TOPICS),
                'question' => 'کدام کارتخوان سیار برای پیک موتوری مناسب‌تر است؟',
            ])->assertRedirect(route('consultation.confirmation'));

        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->hasTo('sara.mohammadi@example.com') && str_contains($m->mailSubject, 'درخواست مشاوره شما'));

        // 5. Verify Ultra-Minimal White Email Template markup (pure #ffffff, hairline #f1f5f9, no heavy card border or 4px top bar)
        $mailable = new UnifiedNotificationMail(
            mailSubject: 'تست قالب سفید مینیمال',
            heading: 'پرداخت سفارش شما با موفقیت انجام شد',
            intro: 'جزئیات سفارش شما در ادامه آمده است.',
            fields: ['کد سفارش' => 'NP889900', 'مبلغ' => '۶٬۵۰۰٬۰۰۰ تومان'],
            actionLabel: 'مشاهده سفارش',
            actionUrl: 'https://novinpaypos.com/account',
            statusBadge: 'پرداخت موفق',
            statusTone: 'success',
        );
        $rendered = $mailable->render();
        $this->assertStringContainsString('background-color:#ffffff', $rendered);
        $this->assertStringContainsString('1px solid #f1f5f9', $rendered);
        $this->assertStringNotContainsString('border-top:4px solid', $rendered);
        $this->assertStringNotContainsString('#dde8f3', $rendered);
    }
}
