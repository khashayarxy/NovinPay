<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\RepairBrand;
use App\Enums\RepairRequestStatus;
use App\Enums\RepairStage;
use App\Enums\StockStatus;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\ShoppingCart;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase10AdminRedesignAndStorefrontPolishTest extends TestCase
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
            'app.url' => 'https://novinpaypos.test',
            'payments.zibal.enabled' => true,
            'payments.zibal.merchant' => 'zibal-live-merchant-test',
            'payments.zibal.sandbox' => false,
            'payments.zibal.live_requests' => true,
            'payments.zibal.callback_url' => 'https://novinpaypos.test/payments/zibal/callback',
        ]);

        $this->admin = User::factory()->superAdmin()->create();

        $phone = '09124445566';
        $this->customer = Customer::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'رضا نادری',
            'contact_phone' => $phone,
            'phone_digest' => RepairIdentity::digest('customer-phone', $phone),
            'name_digest' => RepairIdentity::digest('customer-name', 'رضا نادری'),
            'identity_digest' => RepairIdentity::digest('customer-identity', 'رضا نادری:'.$phone),
            'is_active' => true,
            'version' => 1,
        ]);

        $this->account = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'name' => 'رضا نادری',
            'phone' => $phone,
            'phone_digest' => RepairIdentity::digest('customer-account-phone', $phone),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);

        $this->product = Product::forceCreate([
            'name' => 'کارتخوان سیار PAX S910',
            'slug' => 'pax-s910-phase10',
            'brand' => 'PAX',
            'model' => 'S910',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 4800000,
            'is_published' => true,
            'is_sample' => false,
        ]);

        $this->inventoryItem = InventoryItem::forceCreate([
            'public_id' => (string) Str::uuid(),
            'product_id' => $this->product->id,
            'sku' => 'NP-S910-P10',
            'name' => $this->product->name,
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'on_hand' => 6,
            'reserved' => 0,
            'version' => 1,
        ]);
    }

    public function test_redesigned_admin_views_render_cleanly_with_kpi_cards_and_compact_ids(): void
    {
        $repairRequest = RepairRequest::forceCreate([
            'tracking_reference' => '77889900',
            'submission_digest' => bin2hex(random_bytes(32)),
            'customer_name' => 'رضا نادری',
            'contact_phone' => '09124445566',
            'phone_digest' => RepairIdentity::digest('phone', '09124445566'),
            'device_brand' => 'PAX',
            'device_model' => 'S910',
            'issue' => "روشن نمی‌شود و شارژ نمی‌گیرد\nاستان و شهر: تهران، شهریار\nنحوه تحویل دستگاه: خودم حضوری میارم",
            'status' => RepairRequestStatus::OnlineSubmitted,
        ]);

        $repairCase = RepairCase::forceCreate([
            'public_id' => (string) Str::uuid(),
            'tracking_reference' => '77889901',
            'tracking_digest' => RepairIdentity::digest('tracking', '77889901'),
            'intake_digest' => bin2hex(random_bytes(32)),
            'customer_id' => $this->customer->id,
            'customer_account_id' => $this->account->id,
            'received_by' => $this->admin->id,
            'received_at' => now(),
            'status' => RepairStage::Received,
            'customer_name' => 'رضا نادری',
            'contact_phone' => '09124445566',
            'phone_digest' => RepairIdentity::digest('phone', '09124445566'),
            'device_brand' => RepairBrand::Pax,
            'device_model' => 'S910',
            'device_serial' => 'PAXSN778899',
            'issue' => 'روشن نمی‌شود و شارژ نمی‌گیرد',
            'physical_condition' => 'سالم',
            'accessories' => 'باتری',
            'simple_workflow' => true,
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
            'total' => '4800000.00',
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
            'unit_price' => '4800000.00',
            'line_total' => '4800000.00',
        ]);

        $this->get('/admin')->assertRedirect('/admin/login');

        $this->admin->refresh();
        $adminSession = ['admin_session_version' => 1];

        $dashboard = $this->actingAs($this->admin)
            ->withSession($adminSession)
            ->get('/admin')
            ->assertOk()
            ->assertSee('admin-stat-grid', false)
            ->assertSee('رضا نادری')
            ->assertDontSee('class="admin-nav-group" open', false);
        $this->assertSame(0, preg_match_all('/class="admin-nav-group"\s+open\b/', $dashboard->getContent()));

        $ordersPage = $this->actingAs($this->admin)
            ->withSession($adminSession)
            ->get('/admin/orders')
            ->assertOk();
        $this->assertSame(1, preg_match_all('/class="admin-nav-group"\s+open\b/', $ordersPage->getContent()));

        $adminPages = [
            '/admin/repair-requests',
            route('admin.repairs.show', $repairRequest, false),
            '/admin/repairs',
            '/admin/repairs/intake',
            '/admin/repairs/'.$repairCase->public_id,
            '/admin/orders',
            '/admin/orders/'.$order->public_id,
            '/admin/deliveries',
            '/admin/customers',
            '/admin/customers/'.$this->customer->public_id,
            '/admin/payments',
            '/admin/finance',
            '/admin/finance/reports',
            '/admin/products',
            '/admin/products/create',
            route('admin.products.feedback.index', $this->product, false),
            '/admin/inventory',
            '/admin/inventory/movements',
        ];

        foreach ($adminPages as $uri) {
            $this->actingAs($this->admin)
                ->withSession($adminSession)
                ->get($uri)
                ->assertOk();
        }

        $this->actingAs($this->admin)
            ->withSession($adminSession)
            ->get('/admin/repairs/'.$repairCase->public_id)
            ->assertSee('admin-step-strip', false)
            ->assertSee('data-repair-cost  hidden', false);

        $this->actingAs($this->admin)
            ->withSession($adminSession)
            ->get('/admin/orders/'.$order->public_id)
            ->assertSee('admin-id-chip', false);
    }

    public function test_checkout_renders_zibal_only_step4_and_rejects_request_payment_method(): void
    {
        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => $this->account->session_version])
            ->withUnencryptedCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('b', 64));

        $cart = $this->get('https://novinpaypos.test/cart')->assertOk();

        $this->post('https://novinpaypos.test/cart/products/'.$this->product->slug, [
            'cart_context' => $cart->viewData('proof'),
            'cart_revision' => $cart->viewData('revision'),
            'quantity' => 1,
        ])->assertRedirect();

        $checkout = $this->get('https://novinpaypos.test/cart/checkout')
            ->assertOk()
            ->assertSee('پرداخت آنلاین با درگاه زیبال')
            ->assertSee('width="35" height="42"', false)
            ->assertDontSee('value="request"', false)
            ->assertDontSee('کارت‌به‌کارت')
            ->assertDontSee('کلیه کارت‌های عضو شتاب');

        $this->from('https://novinpaypos.test/cart/checkout')
            ->post('https://novinpaypos.test/cart/checkout', [
                'cart_context' => $checkout->viewData('proof'),
                'cart_revision' => $checkout->viewData('revision'),
                'quote' => $checkout->viewData('quote'),
                'full_name' => 'رضا نادری',
                'mobile' => '09124445566',
                'national_code' => '0013520849',
                'address_mode' => 'new',
                'method' => 'pickup',
                'payment_method' => 'request',
            ])
            ->assertRedirect('https://novinpaypos.test/cart/checkout')
            ->assertSessionHasErrors('payment_method');
    }

    public function test_repair_form_uses_brand_select_dropdown_and_omits_saved_devices_and_step1_heading(): void
    {
        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => $this->account->session_version])
            ->get('/repair')
            ->assertOk()
            ->assertSee('<select id="device_brand" name="device_brand"', false)
            ->assertSee('<option value="" disabled selected hidden></option>', false)
            ->assertSee('google-maps-2026-28.webp', false)
            ->assertDontSee('انتخاب برند دستگاه…')
            ->assertDontSee('انتخاب سریع از دستگاه‌های شما')
            ->assertDontSee('<h2>مشخصات دستگاه</h2>', false)
            ->assertDontSee('repair-brand-grid', false);
    }

    public function test_repair_tracking_supports_inline_handover_change_without_redirect_or_pii_leak(): void
    {
        $phone = '09124445566';
        $code = '44556677';

        $req = RepairRequest::forceCreate([
            'tracking_reference' => $code,
            'submission_digest' => bin2hex(random_bytes(32)),
            'customer_name' => 'رضا نادری',
            'contact_phone' => $phone,
            'phone_digest' => RepairIdentity::digest('phone', $phone),
            'device_brand' => 'PAX',
            'device_model' => 'S910',
            'issue' => "مشکل پرینتر حرارتی\nاستان و شهر: تهران، شهریار\nنحوه تحویل دستگاه: خودم حضوری میارم",
            'status' => RepairRequestStatus::OnlineSubmitted,
        ]);

        $tracked = $this->post('/repair/track', [
            'tracking_reference' => $code,
            'contact_phone' => $phone,
        ])
            ->assertOk()
            ->assertSee('تغییر نحوهٔ تحویل')
            ->assertSee('repair-track-action-btn', false)
            ->assertSee('id="repair-track-handover-panel"', false)
            ->assertDontSee('رضا نادری')
            ->assertDontSee($phone);

        $this->assertNull(session('_old_input'));

        preg_match('/name="track_grant"\s+value="([^"]+)"/', $tracked->getContent(), $matches);
        $grant = html_entity_decode($matches[1] ?? '', ENT_QUOTES);
        $this->assertNotEmpty($grant);

        $this->patch('/repair/track/handover', [
            'track_grant' => $grant,
            'handover_method' => 'self_ship',
        ])
            ->assertOk()
            ->assertSee('نحوهٔ تحویل دستگاه با موفقیت تغییر کرد.')
            ->assertDontSee('رضا نادری')
            ->assertDontSee($phone);

        $this->assertNull(session('_old_input'));
        $this->assertSame('پستی', $req->fresh()->handoverBadge()['label'] ?? null);

        // Tampered grant must redirect safely without mutating handover
        $this->patch('/repair/track/handover', [
            'track_grant' => 'tampered-invalid-grant',
            'handover_method' => 'in_person',
        ])->assertRedirect('/repair/track');

        $this->assertSame('پستی', $req->fresh()->handoverBadge()['label'] ?? null);

        // Once physical case exists, inline handover change is blocked
        RepairCase::forceCreate([
            'public_id' => (string) Str::uuid(),
            'tracking_reference' => $code,
            'tracking_digest' => RepairIdentity::digest('tracking', $code),
            'intake_digest' => bin2hex(random_bytes(32)),
            'repair_request_id' => $req->id,
            'customer_id' => $this->customer->id,
            'received_by' => $this->admin->id,
            'received_at' => now(),
            'status' => RepairStage::Received,
            'customer_name' => 'رضا نادری',
            'contact_phone' => $phone,
            'phone_digest' => RepairIdentity::digest('phone', $phone),
            'device_brand' => RepairBrand::Pax,
            'device_model' => 'S910',
            'issue' => 'مشکل پرینتر حرارتی',
            'physical_condition' => 'سالم',
            'accessories' => 'باتری',
            'simple_workflow' => true,
            'version' => 1,
        ]);

        $this->patch('/repair/track/handover', [
            'track_grant' => $grant,
            'handover_method' => 'in_person',
        ])->assertRedirect('/repair/track');

        $this->assertSame('پستی', $req->fresh()->handoverBadge()['label'] ?? null);
    }

    public function test_consultation_dropdown_product_placeholder_and_hero_responsive_sizes(): void
    {
        $this->get('/consultation')
            ->assertOk()
            ->assertSee('<option value="" disabled selected hidden></option>', false)
            ->assertDontSee('انتخاب موضوع مشاوره…')
            ->assertSee('موضوع مشاوره');

        $this->get('/products/'.$this->product->slug)
            ->assertOk()
            ->assertSee('product-empty-photo__art', false)
            ->assertSee('review-empty__icon', false);

        $this->get('/')
            ->assertOk()
            ->assertSee('(max-width: 380px) 195px, (max-width: 850px) 215px, 300px', false);
    }

    public function test_admin_deep_ux_simplification_microcopy_autofill_trap_file_picker_and_409_error_state(): void
    {
        $this->admin->refresh();
        $adminSession = ['admin_session_version' => 1];

        // 1. Walk-in intake uses clean brand <select> (editable, not disabled) instead of 6-button pill grid
        $walkIn = $this->actingAs($this->admin)
            ->withSession($adminSession)
            ->get('/admin/repairs/intake')
            ->assertOk()
            ->assertSee('data-intake-brand-select', false)
            ->assertSee('admin-intake-row--4', false)
            ->assertDontSee('admin-brand-pills', false);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*id="intake-brand-select"[^>]*disabled/', $walkIn->getContent());

        // 1b. Pre-filled intake from online request separates استان/شهر, locks customer-submitted values, keeps empty fields editable
        $onlineReq = RepairRequest::forceCreate([
            'tracking_reference' => '55667788',
            'submission_digest' => bin2hex(random_bytes(32)),
            'customer_name' => 'سارا محمدی',
            'contact_phone' => '09123334455',
            'phone_digest' => RepairIdentity::digest('phone', '09123334455'),
            'device_brand' => 'PAX',
            'device_model' => 'PAX S910',
            'issue' => "دستگاه شارژ نمی‌شود و خاموش می‌شود\n\nلوازم همراه: شارژر، باتری\nاستان و شهر: تهران - شهریار\nنحوه تحویل دستگاه: خودم حضوری میارم",
            'status' => RepairRequestStatus::OnlineSubmitted,
        ]);

        $intakeFromOnline = $this->actingAs($this->admin)
            ->withSession($adminSession)
            ->get('/admin/repairs/intake?request='.$onlineReq->id)
            ->assertOk()
            ->assertSee('admin-intake-row--customer', false)
            ->assertSee('admin-intake-row--4', false)
            ->assertSee('id="intake-province"', false)
            ->assertSee('id="intake-city"', false)
            ->assertSee('value="تهران"', false)
            ->assertSee('value="شهریار"', false)
            ->assertSee('value="خودم حضوری میارم"', false)
            ->assertSee('value="S910"', false)
            ->assertDontSee('value="PAX S910"', false)
            ->assertSee('value="شارژر، باتری"', false);

        $html = $intakeFromOnline->getContent();
        $this->assertMatchesRegularExpression('/<select[^>]*id="intake-brand-select"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="customer_name"[^>]*readonly/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="contact_phone"[^>]*readonly/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="device_model"[^>]*readonly/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="accessories"[^>]*readonly/', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="issue"[^>]*readonly/', $html);
        // Empty serial & physical_condition remain editable for the intake operator
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="device_serial"[^>]*readonly/', $html);
        $this->assertDoesNotMatchRegularExpression('/<textarea[^>]*name="physical_condition"[^>]*readonly/', $html);

        preg_match('/<textarea[^>]*name="issue"[^>]*>(.*?)<\/textarea>/s', $html, $issueMatch);
        $issueTextarea = trim(html_entity_decode($issueMatch[1] ?? '', ENT_QUOTES, 'UTF-8'));
        $this->assertSame('دستگاه شارژ نمی‌شود و خاموش می‌شود', $issueTextarea);
        $this->assertStringNotContainsString('لوازم همراه:', $issueTextarea);
        $this->assertStringNotContainsString('استان و شهر:', $issueTextarea);
        $this->assertStringNotContainsString('نحوه تحویل دستگاه:', $issueTextarea);
        $this->assertStringNotContainsString('شماره سریال:', $issueTextarea);

        // Submitting pre-filled simple_intake preserves accessories, brand & operator-entered serial cleanly
        $intakeToken = session('repair_intake_token');
        $this->actingAs($this->admin)
            ->withSession($adminSession + ['repair_intake_token' => $intakeToken])
            ->post('/admin/repairs/intake', [
                'simple_intake' => '1',
                'submission_token' => $intakeToken,
                'repair_request_id' => $onlineReq->id,
                'customer_name' => 'سارا محمدی',
                'contact_phone' => '09123334455',
                'device_model' => 'S910',
                'device_serial' => 'SN99887766',
                'accessories' => 'شارژر، باتری',
                'issue' => 'دستگاه شارژ نمی‌شود و خاموش می‌شود',
                'physical_condition' => 'بدون شکستگی ظاهری',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $createdCase = RepairCase::query()->where('repair_request_id', $onlineReq->id)->sole();
        $this->assertSame('شارژر، باتری', $createdCase->accessories);
        $this->assertSame('S910', $createdCase->device_model);
        $this->assertSame(RepairBrand::Pax, $createdCase->device_brand);
        $this->assertSame('SN99887766', $createdCase->device_serial);
        $this->assertSame('دستگاه شارژ نمی‌شود و خاموش می‌شود', $createdCase->issue);

        // 2. Ready repair case offers compact print menu + inline invoice & in-person settlement without page-hopping
        $readyCase = RepairCase::forceCreate([
            'public_id' => (string) Str::uuid(),
            'tracking_reference' => '88990011',
            'tracking_digest' => RepairIdentity::digest('tracking', '88990011'),
            'intake_digest' => bin2hex(random_bytes(32)),
            'customer_id' => $this->customer->id,
            'customer_account_id' => $this->account->id,
            'received_by' => $this->admin->id,
            'received_at' => now(),
            'status' => RepairStage::Ready,
            'repair_price_toman' => 420000,
            'customer_name' => 'رضا نادری',
            'contact_phone' => '09124445566',
            'phone_digest' => RepairIdentity::digest('phone', '09124445566'),
            'device_brand' => RepairBrand::Pax,
            'device_model' => 'S910',
            'issue' => 'تعویض باتری',
            'physical_condition' => 'سالم',
            'accessories' => 'آداپتور',
            'simple_workflow' => true,
            'version' => 1,
        ]);

        $this->actingAs($this->admin)
            ->withSession($adminSession)
            ->get('/admin/repairs/'.$readyCase->public_id)
            ->assertOk()
            ->assertSee('چاپ و اسناد')
            ->assertSee('صدور فاکتور تعمیر')
            ->assertSee(route('admin.finance.issue.store'), false);

        // 3. Customer forms & profile use plain Persian microcopy, tabs, accordions, and autofill traps
        $this->actingAs($this->admin)
            ->withSession($adminSession)
            ->get('/admin/customers/create')
            ->assertOk()
            ->assertSee('شماره موبایل')
            ->assertDontSee('شماره موبایل ایرانی');

        $this->actingAs($this->admin)
            ->withSession($adminSession)
            ->get('/admin/customers/'.$this->customer->public_id)
            ->assertOk()
            ->assertSee('data-admin-tabs', false)
            ->assertSee('مدیریت سوابق متصل')
            ->assertSee('ادغام پروفایل تکراری')
            ->assertSee('admin-autofill-trap', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertDontSee('اتصال یا جداسازی سابقه')
            ->assertDontSee('تطبیق پروفایل تکراری');

        // 4. Finance & Payments use natural Persian business language
        $this->actingAs($this->admin)
            ->withSession($adminSession)
            ->get('/admin/finance')
            ->assertOk()
            ->assertSee('+ صدور فاکتور')
            ->assertSee('ثبت دریافت / هزینه')
            ->assertDontSee('صدور سند مطالبه');

        $this->actingAs($this->admin)
            ->withSession($adminSession)
            ->get('/admin/finance/new/receipt')
            ->assertOk()
            ->assertSee('شماره پیگیری یا قبض (بدون درج شماره کارت)')
            ->assertDontSee('شناسهٔ یکتای مدرک با پیشوند');

        // 5. Product create/edit uses Persian .np-file-picker and progressive accordions (zero English raw file input)
        $this->actingAs($this->admin)
            ->withSession($adminSession)
            ->get('/admin/products/create')
            ->assertOk()
            ->assertSee('data-np-file-picker', false)
            ->assertSee('انتخاب تصویر')
            ->assertSee('فایلی انتخاب نشده است')
            ->assertDontSee('class="input" type="file"', false);

        // 6. Delivery progress 409 renders styled Persian admin error card instead of English Symfony error
        $order = SalesOrder::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'customer_account_id' => $this->account->id,
            'checkout_key' => (string) Str::uuid(),
            'checkout_digest' => bin2hex(random_bytes(32)),
            'status' => 'confirmed',
            'currency' => 'TOMAN',
            'total' => '4800000.00',
            'version' => 1,
        ]);

        $doc = FinancialDocument::forceCreate([
            'public_id' => (string) Str::uuid(),
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id,
            'source_version' => 1,
            'source_amount_minor' => 480000000,
            'charged_minor' => 480000000,
            'paid_minor' => 0,
            'version' => 1,
            'created_at' => now(),
        ]);

        $plan = DeliveryPlan::forceCreate([
            'public_id' => (string) Str::uuid(),
            'financial_document_id' => $doc->id,
            'customer_account_id' => $this->account->id,
            'method' => 'tipax',
            'shipping_minor' => 0,
            'version' => 1,
        ]);

        $this->actingAs($this->admin)
            ->withSession($adminSession)
            ->post(route('admin.deliveries.order-progress', $plan->public_id), [
                'version' => $plan->version,
                'progress' => 'pickup_ready',
            ])
            ->assertConflict()
            ->assertSee('عملیات انجام نشد')
            ->assertSee('بازگشت به ارسال و تحویل')
            ->assertDontSee('Oops! An Error Occurred');
    }
}
