<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\RepairBrand;
use App\Enums\RepairStage;
use App\Enums\StockStatus;
use App\Mail\UnifiedNotificationMail;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\FinancialDocument;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductFeedback;
use App\Models\RepairCase;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\CustomerDeviceRegistry;
use App\Services\Notifications\UnifiedNotifier;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase9DashboardReviewsOtpSupportUxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private CustomerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://novinpaypos.test',
            'payments.zibal.enabled' => true,
            'payments.zibal.merchant' => 'zibal-live-merchant-test',
            'payments.zibal.sandbox' => false,
            'payments.zibal.live_requests' => true,
            'notifications.email.enabled' => true,
            'notifications.admin.emails' => ['admin@novinpaypos.com'],
        ]);

        $this->admin = User::factory()->superAdmin()->create();
        $phone = '09125556677';
        $this->customer = Customer::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'مریم کاظمی',
            'contact_phone' => $phone,
            'phone_digest' => RepairIdentity::digest('customer-phone', $phone),
            'name_digest' => RepairIdentity::digest('customer-name', 'مریم کاظمی'),
            'identity_digest' => RepairIdentity::digest('customer-identity', 'مریم کاظمی:'.$phone),
            'is_active' => true,
            'version' => 1,
        ]);

        $this->account = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'name' => 'مریم کاظمی',
            'phone' => $phone,
            'phone_digest' => RepairIdentity::digest('customer-account-phone', $phone),
            'email' => 'maryam@example.com',
            'email_digest' => hash_hmac('sha256', 'customer-email:maryam@example.com', (string) config('app.key')),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);
    }

    public function test_dashboard_urgent_banner_my_devices_copy_receipts_addresses_and_horizontal_steppers(): void
    {
        $product = Product::forceCreate([
            'name' => 'کارتخوان سیار Newland ME31',
            'slug' => 'newland-me31-phase9',
            'brand' => 'Newland',
            'model' => 'ME31',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 6800000,
            'is_published' => true,
            'is_sample' => false,
        ]);
        $inv = InventoryItem::forceCreate([
            'public_id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'sku' => 'SKU-ME31-P9',
            'name' => $product->name,
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'on_hand' => 5,
            'reserved' => 0,
            'version' => 1,
        ]);

        // Paid POS order needing activation documents
        $order = SalesOrder::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'customer_account_id' => $this->account->id,
            'checkout_key' => (string) Str::uuid(),
            'checkout_digest' => bin2hex(random_bytes(32)),
            'status' => 'confirmed',
            'currency' => 'TOMAN',
            'total' => '6800000.00',
            'documents_status' => 'pending_submission',
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
            'unit_price' => '6800000.00',
            'line_total' => '6800000.00',
        ]);
        $orderDoc = FinancialDocument::forceCreate([
            'public_id' => (string) Str::uuid(),
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id,
            'source_version' => 1,
            'source_amount_minor' => 680000000,
            'charged_minor' => 680000000,
            'paid_minor' => 680000000,
            'version' => 1,
            'created_at' => now(),
        ]);
        $orderAttempt = PaymentAttempt::forceCreate([
            'public_id' => (string) Str::uuid(),
            'financial_document_id' => $orderDoc->id,
            'customer_account_id' => $this->account->id,
            'operation_key' => (string) Str::uuid(),
            'amount_minor' => 680000000,
            'amount_rial' => 68000000,
            'document_version' => 1,
            'merchant_digest' => bin2hex(random_bytes(32)),
            'track_id' => '998877665',
            'state' => 'settled',
            'attempts' => 1,
            'verified_at' => now(),
        ]);
        PaymentTransaction::forceCreate([
            'public_id' => (string) Str::uuid(),
            'financial_document_id' => $orderDoc->id,
            'payment_attempt_id' => $orderAttempt->id,
            'operation_key' => (string) Str::uuid(),
            'method' => 'gateway',
            'amount_minor' => 680000000,
            'allocated_minor' => 680000000,
            'reference_digest' => bin2hex(random_bytes(32)),
            'reference' => 'ZIBAL-ORD-9001',
            'created_at' => now(),
        ]);

        // Unpaid RepairCase with serial number
        $repairCase = RepairCase::forceCreate([
            'public_id' => (string) Str::uuid(),
            'tracking_reference' => '50918822',
            'tracking_digest' => RepairIdentity::digest('tracking', '50918822'),
            'intake_digest' => bin2hex(random_bytes(32)),
            'customer_id' => $this->customer->id,
            'customer_account_id' => $this->account->id,
            'received_by' => $this->admin->id,
            'received_at' => now(),
            'status' => RepairStage::Ready,
            'customer_name' => 'مریم کاظمی',
            'contact_phone' => '09125556677',
            'phone_digest' => RepairIdentity::digest('phone', '09125556677'),
            'device_brand' => RepairBrand::Pax,
            'device_model' => 'S910',
            'device_serial' => 'PAXSN998877',
            'issue' => 'تعویض باتری و سوکت شارژ',
            'physical_condition' => 'سالم',
            'accessories' => 'باتری',
            'repair_price_toman' => 950000,
            'simple_workflow' => true,
            'version' => 1,
        ]);
        $repairDoc = FinancialDocument::forceCreate([
            'public_id' => (string) Str::uuid(),
            'repair_case_id' => $repairCase->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id,
            'source_version' => 1,
            'source_amount_minor' => 95000000,
            'charged_minor' => 95000000,
            'paid_minor' => 0,
            'version' => 1,
            'created_at' => now(),
        ]);

        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1]);

        // 1. Overview shows Urgent Action Banner (both repair fee & activation docs), My Devices, Copy buttons, and Paid Order Receipt link
        $orderCode = strtoupper(substr($order->public_id, 0, 8));
        $overviewHtml = $this->get(route('account.index'))
            ->assertOk()
            ->assertSee('data-urgent-action-banner', false)
            ->assertSee('هزینه تعمیر دستگاه آماده پرداخت است')
            ->assertSee('ارسال مدارک فعال‌سازی دستگاه کارتخوان')
            ->assertSee('تکمیل مدارک فعال‌سازی')
            ->assertSee('data-my-devices', false)
            ->assertSee('دستگاه‌های من')
            ->assertSee('PAXSN998877')
            ->assertSee('data-copy-code="'.$orderCode.'"', false)
            ->assertSee('data-copy-code="50918822"', false)
            ->assertSee(route('account.orders.receipt', $order->public_id), false)
            ->getContent();

        $this->assertStringNotContainsString(route('account.repairs.receipt', $repairCase->public_id), $overviewHtml);

        // 2. Quick Repair Repeat pre-fills brand, model, and serial on /repair?device=...
        $paxDevice = app(CustomerDeviceRegistry::class)->forAccount($this->account)
            ->firstWhere('device_brand', 'PAX');
        $this->assertNotNull($paxDevice);

        $this->get(route('repair', ['device' => $paxDevice->public_id]))
            ->assertOk()
            ->assertDontSee('انتخاب سریع از دستگاه‌های شما')
            ->assertSee('<option value="PAX" selected>', false)
            ->assertSee('value="S910"', false)
            ->assertSee('value="PAXSN998877"', false);

        // 3. Order detail view has horizontal stepper, Persian upload UI, copy button, and printable merchant receipt
        $this->get(route('account.orders.show', $order->public_id))
            ->assertOk()
            ->assertSee('np-timeline--horizontal', false)
            ->assertSee('انتخاب تصویر یا PDF')
            ->assertSee('فایلی انتخاب نشده است')
            ->assertSee('data-copy-code="'.$orderCode.'"', false);

        $this->get(route('account.orders.receipt', $order->public_id))
            ->assertOk()
            ->assertSee('رسید رسمی خرید کالا')
            ->assertSee('NP-ORD-'.$orderCode)
            ->assertSee('ZIBAL-ORD-9001')
            ->assertSee('چاپ رسید / ذخیره PDF');

        // Unpaid repair receipt returns 404; once settled, returns 200 printable repair receipt
        $this->get(route('account.repairs.receipt', $repairCase->public_id))->assertNotFound();
        $repairDoc->paid_minor = 95000000;
        $repairDoc->save();

        $this->get(route('account.repairs.receipt', $repairCase->public_id))
            ->assertOk()
            ->assertSee('رسید خدمات و تعمیرات تخصصی')
            ->assertSee('NP-REP-50918822')
            ->assertSee('PAXSN998877');

        // 4. Addresses tab defaults saved addresses to compact collapsed cards (edit panels hidden)
        CustomerAddress::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_account_id' => $this->account->id,
            'recipient' => 'مریم کاظمی',
            'phone' => '09125556677',
            'province' => 'تهران',
            'city' => 'تهران',
            'address' => 'خیابان ولیعصر، بالاتر از میدان ونک، پلاک ۱۲۰',
            'postal_code' => '1967743111',
        ]);
        CustomerAddress::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_account_id' => $this->account->id,
            'recipient' => 'دفتر فروشگاه مرکزی',
            'phone' => '09125556677',
            'province' => 'تهران',
            'city' => 'شهریار',
            'address' => 'خیابان ولیعصر شهریار، پاساژ نور، طبقه اول، واحد ۴',
            'postal_code' => '3351611828',
        ]);

        $addressesHtml = $this->get(route('account.index', ['section' => 'addresses']))
            ->assertOk()
            ->assertSee('data-addresses-accordion', false)
            ->getContent();
        $this->assertSame(3, substr_count($addressesHtml, 'data-address-accordion-panel hidden'));
    }

    public function test_profile_email_verification_and_notification_toggle_respected_by_unified_notifier(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1]);

        // 1. Send signed profile email verification link & verify it
        $capturedVerifyUrl = null;
        $this->post(route('account.profile.email.send'))
            ->assertRedirect(route('account.index', ['section' => 'profile']));

        Mail::assertSent(UnifiedNotificationMail::class, function (UnifiedNotificationMail $mail) use (&$capturedVerifyUrl): bool {
            $capturedVerifyUrl = $mail->actionUrl;

            return $mail->hasTo('maryam@example.com') && is_string($capturedVerifyUrl) && str_contains($capturedVerifyUrl, '/account/profile/email-verify/');
        });

        $this->get($capturedVerifyUrl)->assertRedirect(route('account.index', ['section' => 'profile']));
        $this->assertNotNull($this->account->fresh()->email_verified_at);

        // 2. Disable email notifications via profile toggle switch
        $this->post(route('account.profile'), [
            'name' => 'مریم کاظمی',
            'email' => 'maryam@example.com',
            'email_notifications_present' => '1',
            // unchecked checkbox omits email_notifications_enabled
        ])->assertRedirect(route('account.index', ['section' => 'profile']));

        $this->assertFalse($this->account->fresh()->email_notifications_enabled);

        // 3. UnifiedNotifier skips customer email when email_notifications_enabled is false, while still emailing admin
        Mail::fake();
        $order = SalesOrder::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'customer_account_id' => $this->account->id,
            'checkout_key' => (string) Str::uuid(),
            'checkout_digest' => bin2hex(random_bytes(32)),
            'status' => 'confirmed',
            'currency' => 'TOMAN',
            'total' => '5200000.00',
            'version' => 1,
        ]);

        app(UnifiedNotifier::class)->orderPlaced($order);
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->hasTo('admin@novinpaypos.com'));
        Mail::assertNotSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->hasTo('maryam@example.com'));
    }

    public function test_reviews_clean_edit_verified_badge_helpful_vote_official_np_logo_and_streamlined_otp_screen(): void
    {
        $product = Product::forceCreate([
            'name' => 'کارتخوان PAX S910 4G',
            'slug' => 'pax-s910-phase9-reviews',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 7900000,
            'is_published' => true,
            'is_sample' => false,
        ]);

        $buyerReview = new ProductFeedback;
        $buyerReview->forceFill([
            'product_id' => $product->id,
            'customer_account_id' => $this->account->id,
            'review_owner_id' => $this->account->id,
            'kind' => 'review',
            'status' => 'approved',
            'body' => 'باتری فوق‌العاده قوی دارد و سرعت چاپ رسید عالی است.',
            'rating' => 5,
            'verified_buyer' => true,
            'staff_reply' => 'سپاس از همراهی و اعتماد شما به نوین‌پی.',
            'helpful_count' => 0,
        ])->save();

        // 1. Product page shows rating summary, green خریدار محصول badge, official پشتیبانی نوین‌پی reply with brand/novinpay-np-blue.svg (NOT favicon.ico), ring-2 cart badge, and keeps own edit form collapsed!
        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1]);

        $productHtml = $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('data-rating-summary', false)
            ->assertSee('خریدار محصول')
            ->assertSee('پشتیبانی نوین‌پی')
            ->assertSee('brand/novinpay-np-blue.svg', false)
            ->assertSee('مفید بود')
            ->assertSee('ring-2 ring-white', false)
            ->getContent();

        $reviewSection = substr($productHtml, (int) strpos($productHtml, 'id="product-review"'));
        $this->assertStringNotContainsString('favicon.ico', $reviewSection);
        $this->assertMatchesRegularExpression('/id="submit-review"[^>]*hidden/', $productHtml);
        $this->assertStringNotContainsString('product-entry__pending-banner', $productHtml);
        $this->assertStringNotContainsString('product-feedback-status-pill--pending', $productHtml);

        // 2. Helpful vote endpoint toggles count cleanly (1 -> 0) via JSON
        $voteUrl = route('products.feedback.helpful', [$product, $buyerReview]);
        $this->postJson($voteUrl)
            ->assertOk()
            ->assertJson(['ok' => true, 'voted' => true, 'helpful_count' => 1, 'helpful_count_fa' => '۱']);
        $this->assertSame(1, $buyerReview->fresh()->helpful_count);

        $this->postJson($voteUrl)
            ->assertOk()
            ->assertJson(['ok' => true, 'voted' => false, 'helpful_count' => 0, 'helpful_count_fa' => '۰']);
        $this->assertSame(0, $buyerReview->fresh()->helpful_count);

        // 3. Dashboard Reviews tab shows clean action buttons and official NP logo reply
        $this->get(route('account.index', ['section' => 'reviews']))
            ->assertOk()
            ->assertSee('پشتیبانی نوین‌پی')
            ->assertSee('brand/novinpay-np-blue.svg', false)
            ->assertSee('ویرایش دیدگاه')
            ->assertSee('مشاهده صفحه محصول');

        // 4. Streamlined OTP screen (guest): inline phone/email in subtitle, NO gray .np-auth__destination box, NO "اطلاعات فرم را بررسی کنید" heading, NO extra back row
        auth('customer')->logout();
        Mail::fake();
        config(['customer.email_auth_enabled' => true]);

        $this->post('/account/email/start', ['email' => 'otpcheck@example.com'])
            ->assertRedirect(route('account.login', ['channel' => 'email']));

        $challenge = session('customer_email_challenge');
        $this->post('/account/email/verify', [
            'challenge' => $challenge,
            'code' => '000000',
        ])->assertRedirect(route('account.login', ['channel' => 'email']));

        $otpHtml = $this->get(route('account.login', ['channel' => 'email']))
            ->assertOk()
            ->assertSee('data-otp-inline-target', false)
            ->assertSee('otpcheck@example.com')
            ->assertSee('تغییر ایمیل')
            ->assertSee('ارسال مجدد کد')
            ->assertSee('ورود با رمز عبور')
            ->assertDontSee('np-auth__destination', false)
            ->assertDontSee('اطلاعات فرم را بررسی کنید')
            ->assertDontSee('بازگشت به صفحه ورود | ثبت‌نام')
            ->assertDontSee('کد ارسال‌شده به ایمیل تا ۵ دقیقه معتبر است.')
            ->getContent();

        $this->assertStringContainsString('id="account-otp-error"', $otpHtml);
    }
}
