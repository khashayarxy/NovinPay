<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\RepairBrand;
use App\Enums\RepairStage;
use App\Enums\StockStatus;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\FinancialDocument;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\RepairCase;
use App\Models\RepairDelivery;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\ActivationDocumentsService;
use App\Support\FaNumber;
use App\Support\QrCodeSvg;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase11UxPrintDocsAndPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function createCustomerAccount(string $phone = '09123456789', string $name = 'مشتری تست'): CustomerAccount
    {
        $customer = new Customer;
        $customer->public_id = (string) Str::uuid();
        $customer->name = $name;
        $customer->contact_phone = $phone;
        $customer->name_digest = hash_hmac('sha256', mb_strtolower($name), config('app.key'));
        $customer->phone_digest = RepairIdentity::digest('phone', $phone);
        $customer->identity_digest = hash_hmac('sha256', $customer->public_id.'|'.$customer->phone_digest, config('app.key'));
        $customer->is_active = true;
        $customer->save();

        $account = new CustomerAccount;
        $account->public_id = (string) Str::uuid();
        $account->customer_id = $customer->id;
        $account->name = $name;
        $account->phone = $phone;
        $account->phone_digest = RepairIdentity::digest('customer-account-phone', $phone);
        $account->verified_at = now();
        $account->is_active = true;
        $account->session_version = 1;
        $account->save();

        return $account;
    }

    private function createSimpleRepairCase(User $owner, ?CustomerAccount $account = null, RepairStage $stage = RepairStage::Received): RepairCase
    {
        $phone = $account?->phone ?? '09125551234';
        $case = new RepairCase;
        $case->public_id = (string) Str::uuid();
        $case->intake_digest = hash('sha256', (string) Str::uuid());
        $case->tracking_reference = (string) random_int(10000000, 99999999);
        $case->tracking_digest = RepairIdentity::digest('tracking', $case->tracking_reference);
        $case->customer_id = $account?->customer_id;
        $case->customer_account_id = $account?->id;
        $case->customer_name = $account?->name ?? 'علی محمدی';
        $case->contact_phone = $phone;
        $case->phone_digest = RepairIdentity::digest('phone', $phone);
        $case->device_brand = RepairBrand::Newpos;
        $case->device_model = '9220';
        $case->device_serial = 'NP922012345';
        $case->accessories = 'شارژر، باتری';
        $case->issue = 'صفحه نمایش خاموش است';
        $case->physical_condition = 'بدون شکستگی، خط و خش جزئی';
        $case->status = $stage;
        $case->simple_workflow = true;
        $case->repair_price_toman = 450000;
        $case->received_by = $owner->id;
        $case->received_at = now()->subHours(3);
        $case->version = 1;
        $case->save();

        return $case;
    }

    public function test_performance_timeouts_and_lock_free_checkout_draft(): void
    {
        $this->assertSame(5, config('mail.mailers.smtp.timeout'));

        $account = $this->createCustomerAccount();
        $this->actingAs($account, 'customer')
            ->withSession(['customer_session_version' => $account->session_version]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->postJson(route('cart.checkout.draft'), [
            'full_name' => 'مشتری جدید',
            'province' => 'تهران',
            'city' => 'تهران',
        ])->assertOk()->assertJson(['ok' => true]);

        foreach ($queries as $sql) {
            if (str_contains($sql, 'customer_accounts')) {
                $this->assertStringNotContainsString('for update', $sql);
            }
        }
    }

    public function test_repair_form_stores_activation_and_tax_file_statuses_cleanly(): void
    {
        $account = $this->createCustomerAccount();
        $token = bin2hex(random_bytes(32));

        $this->actingAs($account, 'customer')
            ->withSession([
                'customer_session_version' => $account->session_version,
                'repair_submission_token' => $token,
            ])
            ->post(route('repair.store'), [
                'submission_token' => $token,
                'customer_name' => 'سارا رضایی',
                'device_brand' => RepairBrand::Newpos->value,
                'device_model' => '9220',
                'issue' => 'دستگاه هنگام کشیدن کارت خطای اتصال می‌دهد',
                'activation_status' => 'disconnected',
                'tax_file_status' => 'needs_setup',
                'province' => 'تهران',
                'city' => 'شهریار',
                'handover_method' => 'in_person',
            ])
            ->assertRedirect(route('repair.confirmation'));

        $req = RepairRequest::query()->latest('id')->firstOrFail();
        $parsed = $req->parsedIssue();
        $this->assertSame('دستگاه هنگام کشیدن کارت خطای اتصال می‌دهد', $parsed['problem']);
        $this->assertSame(RepairRequest::ACTIVATION_STATUSES['disconnected'], $parsed['activation_status']);
        $this->assertSame(RepairRequest::TAX_FILE_STATUSES['needs_setup'], $parsed['tax_file_status']);
    }

    public function test_account_activation_banner_only_shows_for_pos_terminals_not_accessories(): void
    {
        $owner = $this->createOwner();
        $account = $this->createCustomerAccount();
        $service = app(ActivationDocumentsService::class);

        $makeSettledOrder = function (ProductCategory $category, string $slug, string $name) use ($owner, $account): array {
            $product = new Product;
            $product->slug = $slug;
            $product->name = $name;
            $product->category = $category;
            $product->condition = ProductCondition::New;
            $product->stock_status = StockStatus::InStock;
            $product->price = 500000;
            $product->summary = 'توضیح کوتاه محصول';
            $product->is_published = true;
            $product->save();
            $inv = new InventoryItem;
            $inv->public_id = (string) Str::uuid();
            $inv->product_id = $product->id;
            $inv->sku = 'SKU-'.strtoupper(Str::random(6));
            $inv->name = $name;
            $inv->condition = 'new';
            $inv->serialized = false;
            $inv->on_hand = 10;
            $inv->save();

            $order = new SalesOrder;
            $order->public_id = (string) Str::uuid();
            $order->customer_id = $account->customer_id;
            $order->customer_account_id = $account->id;
            $order->checkout_key = (string) Str::uuid();
            $order->checkout_digest = hash('sha256', $slug);
            $order->status = 'confirmed';
            $order->currency = 'TOMAN';
            $order->total = '500000.00';
            $order->version = 1;
            $order->save();

            $line = new SalesOrderLine;
            $line->sales_order_id = $order->id;
            $line->inventory_item_id = $inv->id;
            $line->sku = $inv->sku;
            $line->description = $name;
            $line->condition = 'new';
            $line->quantity = 1;
            $line->revision = 1;
            $line->unit_price = '500000.00';
            $line->line_total = '500000.00';
            $line->save();

            $doc = new FinancialDocument;
            $doc->public_id = (string) Str::uuid();
            $doc->customer_id = $account->customer_id;
            $doc->sales_order_id = $order->id;
            $doc->source_version = 1;
            $doc->source_amount_minor = 50000000;
            $doc->charged_minor = 50000000;
            $doc->paid_minor = 50000000;
            $doc->version = 1;
            $doc->created_by = $owner->id;
            $doc->created_at = now();
            $doc->save();

            $attempt = new PaymentAttempt;
            $attempt->public_id = (string) Str::uuid();
            $attempt->financial_document_id = $doc->id;
            $attempt->customer_account_id = $account->id;
            $attempt->operation_key = (string) Str::uuid();
            $attempt->amount_minor = 50000000;
            $attempt->amount_rial = 5000000;
            $attempt->document_version = 1;
            $attempt->merchant_digest = hash('sha256', 'merchant');
            $attempt->state = 'settled';
            $attempt->save();

            return [$order, $doc];
        };

        [$rollOrder, $rollDoc] = $makeSettledOrder(ProductCategory::Consumable, 'paper-roll-10', 'رول کاغذ حرارتی');
        [$posOrder, $posDoc] = $makeSettledOrder(ProductCategory::PosTerminal, 'newpos-9220-pos', 'کارتخوان سیار NewPOS 9220');

        $this->assertFalse($service->isEligibleForDocuments($rollOrder, $rollDoc));
        $this->assertTrue($service->isEligibleForDocuments($posOrder, $posDoc));
    }

    public function test_admin_can_request_repair_activation_docs_and_customer_can_submit_with_10_digit_postal_code(): void
    {
        Config::set('novinpay.docs_zip_password', 'TestSecretPass123!');
        Http::fake(['*' => Http::response(['ok' => false], 500)]);

        $owner = $this->createOwner();
        $account = $this->createCustomerAccount();
        $case = $this->createSimpleRepairCase($owner, $account, RepairStage::Repairing);

        $this->actingAs($owner)
            ->withSession(['admin_session_version' => 1])
            ->post(route('admin.repair-cases.request-activation-docs', $case))
            ->assertRedirect(route('admin.repair-cases.show', $case));

        $case->refresh();
        $this->assertSame('pending_submission', $case->activation_docs_status);
        $this->assertNotNull($case->activation_docs_requested_at);

        // Customer sees the urgent banner in /account
        $this->actingAs($account, 'customer')
            ->withSession(['customer_session_version' => $account->session_version])
            ->get(route('account.index'))
            ->assertOk()
            ->assertSee('تکمیل مدارک فعال‌سازی');

        // Reject invalid postal code
        $this->actingAs($account, 'customer')
            ->withSession(['customer_session_version' => $account->session_version])
            ->post(route('account.repairs.documents.store', $case->public_id), [
                'postal_code' => '12345',
                'slots' => [
                    'national_card_front' => UploadedFile::fake()->image('front.jpg'),
                    'national_card_back' => UploadedFile::fake()->image('back.jpg'),
                    'birth_certificate' => UploadedFile::fake()->image('birth.jpg'),
                    'bank_card_1' => UploadedFile::fake()->image('card1.jpg'),
                    'tax_code' => UploadedFile::fake()->image('tax.jpg'),
                ],
            ])
            ->assertSessionHasErrors('postal_code');

        // Accept valid 10-digit postal code + new split slots
        $this->actingAs($account, 'customer')
            ->withSession(['customer_session_version' => $account->session_version])
            ->post(route('account.repairs.documents.store', $case->public_id), [
                'postal_code' => '۳۳۵۱۶۱۱۸۲۸',
                'slots' => [
                    'national_card_front' => UploadedFile::fake()->image('front.jpg'),
                    'national_card_back' => UploadedFile::fake()->image('back.jpg'),
                    'birth_certificate' => UploadedFile::fake()->image('birth.jpg'),
                    'bank_card_1' => UploadedFile::fake()->image('card1.jpg'),
                    'bank_card_2' => UploadedFile::fake()->image('card2.jpg'),
                    'tax_code' => UploadedFile::fake()->image('tax.jpg'),
                ],
            ])
            ->assertRedirect(route('account.repairs.show', $case->public_id).'#activation-documents')
            ->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame('pending_review', $case->activation_docs_status);
    }

    public function test_simple_record_step4_done_layout_and_monochrome_prints_with_signed_qr_tracking(): void
    {
        $owner = $this->createOwner();
        $account = $this->createCustomerAccount('09129998877', 'رضا کریمی');
        $case = $this->createSimpleRepairCase($owner, $account, RepairStage::Delivered);

        $delivery = new RepairDelivery;
        $delivery->repair_case_id = $case->id;
        $delivery->actor_id = $owner->id;
        $delivery->outcome = 'delivered';
        $delivery->recipient_name = 'رضا کریمی';
        $delivery->delivered_at = now();
        $delivery->confirmation_method = 'staff_observation';
        $delivery->confirmation_reference = 'POST-987654';
        $delivery->outcome_notes = 'تحویل انجام شد';
        $delivery->created_at = now();
        $delivery->save();

        // 1. 4-step simple-record page checks
        $recordRes = $this->actingAs($owner)->withSession(['admin_session_version' => 1])->get(route('admin.repair-cases.show', $case));
        $recordRes->assertOk();
        $recordRes->assertSee('data-step-done="1"', false);
        $recordRes->assertSee('زمان پذیرش');
        $recordRes->assertDontSee('مانده قابل پرداخت');
        $recordRes->assertDontSee('سند مالی ←');

        // 2. Intake thermal receipt: monochrome logo, no store phone, SVG QR, updated footer note
        $storePhoneFa = FaNumber::digits((string) config('business.phone'));
        $intakeRes = $this->actingAs($owner)->withSession(['admin_session_version' => 1])->get(route('admin.repair-cases.document', [$case, 'intake']));
        $intakeRes->assertOk();
        $intakeRes->assertSee('logo-wordmark-black-on-white.svg');
        $intakeRes->assertDontSee($storePhoneFa);
        $intakeRes->assertSee('data-repair-qr', false);
        $intakeRes->assertSee('اسکن برای پیگیری آنلاین تعمیر');
        $intakeRes->assertSee('برای تحویل دستگاه، ارائهٔ این رسید یا کد پیگیری کافی است.');

        // 3. Delivery thermal receipt: monochrome logo, no store phone, signature line, no intake-only note
        $deliveryRes = $this->actingAs($owner)->withSession(['admin_session_version' => 1])->get(route('admin.repair-cases.document', [$case, 'delivery']));
        $deliveryRes->assertOk();
        $deliveryRes->assertSee('logo-wordmark-black-on-white.svg');
        $deliveryRes->assertDontSee($storePhoneFa);
        $deliveryRes->assertSee('امضای تحویل‌گیرنده');
        $deliveryRes->assertDontSee('ارائهٔ این رسید یا کد پیگیری');

        // 4. Monochrome summary invoice: pure mono class, no store phone, issued/settled date, no remaining balance row
        $summaryRes = $this->actingAs($owner)->withSession(['admin_session_version' => 1])->get(route('admin.repair-cases.document', [$case, 'summary']));
        $summaryRes->assertOk();
        $summaryRes->assertSee('repair-formal-invoice--mono');
        $summaryRes->assertSee('تاریخ صدور / تسویه');
        $summaryRes->assertDontSee($storePhoneFa);
        $summaryRes->assertDontSee('مانده قابل پرداخت');

        // 5. Signed QR URL on /repair/track: read-only, no login, zero PII leak, rejects tampered signature
        $signedUrl = URL::signedRoute('repair.track', ['ref' => $case->tracking_reference]);
        $trackRes = $this->get($signedUrl);
        $trackRes->assertOk();
        $trackRes->assertSee('NewPOS');
        $trackRes->assertSee('9220');
        $trackRes->assertDontSee('09129998877');
        $trackRes->assertDontSee('۰۹۱۲۹۹۹۸۸۷۷');
        $trackRes->assertDontSee('رضا کریمی');
        $trackRes->assertDontSee('تغییر نحوهٔ تحویل');

        $tamperedUrl = $signedUrl.'tampered';
        $this->get($tamperedUrl)->assertForbidden();

        // 6. Offline SVG QR generator produces valid monochrome SVG
        $svg = QrCodeSvg::svg($signedUrl, 128);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('fill="#000000"', $svg);
    }
}
