<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\RepairBrand;
use App\Enums\RepairRequestStatus;
use App\Enums\RepairStage;
use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\RepairRequest;
use App\Models\User;
use App\Services\RepairOperations;
use App\Support\LatinText;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Phase1StorefrontAndRepairOverhaulTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_includes_repairs_dropdown_submenu(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('data-desktop-repair-dropdown', false);
        $response->assertSee('ثبت درخواست تعمیر');
        $response->assertSee('پیگیری تعمیر');
    }

    public function test_product_title_strips_color_and_preserves_ltr_network_tag_and_hides_empty_related(): void
    {
        $terminal = Product::create([
            'name' => '(4G) Newland ME31 Black',
            'slug' => 'newland-me31-4g-black',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'brand' => 'Newland',
            'model' => 'ME31',
            'color' => 'Black',
            'price' => 4500000,
            'is_published' => true,
            'is_sample' => false,
            'product_details' => [
                'groups' => [
                    'connectivity' => 'شبکهٔ سلولی در کاتالوگ: 4G / 3G / 2G',
                    'payment' => 'کارت‌خوان در کاتالوگ: مگنت، چیپ، NFC',
                ],
            ],
        ]);

        $this->assertSame('Newland ME31 (4G)', $terminal->displayName());
        $this->assertStringContainsString('<bdi class="np-latin" dir="ltr">Newland ME31 (4G)</bdi>', (string) LatinText::html($terminal->displayName()));

        $response = $this->get(route('products.show', $terminal));
        $response->assertOk();
        $response->assertSee('Newland ME31 (4G)');
        $response->assertDontSee('محصول مشابهی برای نمایش وجود ندارد.');
        $response->assertSee('شبکه‌های ارتباطی');
        $response->assertSee('روش‌های خواندن کارت');
        $response->assertSee('spec-groups--table', false);
    }

    public function test_product_comparison_filters_by_same_category_and_renders_sticky_cards_and_diff_toggle(): void
    {
        $pos1 = Product::create([
            'name' => 'Newland ME31 (4G) Black',
            'slug' => 'newland-me31-4g',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'brand' => 'Newland',
            'model' => 'ME31',
            'color' => 'Black',
            'price' => 4500000,
            'is_published' => true,
            'is_sample' => false,
            'product_details' => [
                'groups' => [
                    'hardware' => 'پردازنده: ARM Cortex-A7',
                    'connectivity' => 'شبکهٔ سلولی در کاتالوگ: 4G / 3G / 2G',
                ],
            ],
        ]);

        $pos2 = Product::create([
            'name' => 'PAX S910 Mini',
            'slug' => 'pax-s910-mini',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'brand' => 'PAX',
            'model' => 'S910',
            'price' => 3800000,
            'is_published' => true,
            'is_sample' => false,
            'product_details' => [
                'groups' => [
                    'hardware' => 'پردازنده: ARM11',
                    'connectivity' => 'شبکهٔ سلولی در کاتالوگ: 4G',
                ],
            ],
        ]);

        $paperRoll = Product::create([
            'name' => 'رول کاغذ حرارتی 57 میلیمتری',
            'slug' => 'thermal-paper-roll-57mm',
            'category' => ProductCategory::Consumable,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 25000,
            'is_published' => true,
            'is_sample' => false,
        ]);

        $response = $this->get(route('products.compare', ['products' => [$pos1->slug], 'add' => $paperRoll->slug]));
        $response->assertOk();
        $response->assertSee('Newland ME31 (4G)');
        $response->assertSee($pos2->displayName());
        $response->assertDontSee($paperRoll->name);
        $response->assertSee('شبکه‌های ارتباطی');
        $response->assertSee('ARM Cortex-A7');
        $response->assertSee('مشاهده و خرید');

        $twoResponse = $this->get(route('products.compare', ['products' => [$pos1->slug, $pos2->slug]]));
        $twoResponse->assertOk();
        $twoResponse->assertSee('فقط تفاوت‌ها را نشان بده');
        $twoResponse->assertSee('data-compare-diff-toggle', false);
    }

    public function test_repair_page_renders_four_clickable_steps_and_confirmation_has_two_actions(): void
    {
        $repairPage = $this->get(route('repair'));
        $repairPage->assertOk();
        $repairPage->assertSee('repair-split-card', false);
        $repairPage->assertSee('repair-split-form', false);
        $repairPage->assertSee('repair-split-trust', false);
        $repairPage->assertSee('repair-split-trust__hero-svg', false);
        $repairPage->assertDontSee('repair-trust-aside', false);
        $repairPage->assertSee('repair-progress--4', false);
        $repairPage->assertSeeInOrder([
            'مشخصات دستگاه',
            'مشکل دستگاه',
            'تماس و تحویل',
            'بازبینی و ثبت',
        ]);
        $repairPage->assertSee('اطلاعات تماس و تحویل');
        $repairPage->assertSee('بازبینی و ثبت نهایی');
        $repairPage->assertSee('data-custom-select data-repair-province', false);
        $repairPage->assertSee('repair-handover-card__svg', false);

        $confirmPage = $this->withSession([
            'repair_reference' => '12345678',
            'repair_handover' => 'self_ship',
        ])->get(route('repair.confirmation'));
        $confirmPage->assertOk();
        $confirmPage->assertSee('پیگیری وضعیت تعمیر');
        $confirmPage->assertSee(route('account.repairs.request', '12345678'), false);
        $confirmPage->assertSee('تماس با نوین‌پی');
        $confirmPage->assertDontSee('تغییر نحوهٔ تحویل');
        $confirmPage->assertDontSee('تعمیرات در حساب کاربری');
    }

    public function test_admin_intake_of_online_repair_request_sends_received_status_sms_instead_of_created_sms(): void
    {
        config([
            'notifications.pattern_sms.enabled' => true,
            'notifications.pattern_sms.provider' => 'ippanel',
            'notifications.pattern_sms.patterns' => [],
            'customer.sms_provider' => 'ippanel',
            'customer.ippanel.enabled' => true,
            'customer.ippanel.api_key' => 'test-ippanel-key',
            'customer.ippanel.sender' => '+983000505',
            'customer.ippanel.pattern_code' => 'otpOnly',
        ]);
        Http::fake(['https://edge.ippanel.com/v1/api/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [900]]])]);

        $owner = User::factory()->superAdmin()->create();
        $onlineRequest = new RepairRequest;
        $onlineRequest->tracking_reference = '44556677';
        $onlineRequest->submission_digest = RepairIdentity::digest('submission', bin2hex(random_bytes(32)));
        $onlineRequest->customer_name = 'مشتری تست آنلاین';
        $onlineRequest->contact_phone = '09123456789';
        $onlineRequest->phone_digest = RepairIdentity::digest('phone', '09123456789');
        $onlineRequest->device_brand = RepairBrand::Newland;
        $onlineRequest->device_model = 'ME31';
        $onlineRequest->issue = 'چاپگر دستگاه کاغذ را بیرون نمی‌دهد.';
        $onlineRequest->status = RepairRequestStatus::OnlineSubmitted;
        $onlineRequest->save();

        app(RepairOperations::class)->intake($owner, [
            'simple_intake' => '1',
            'repair_request_id' => $onlineRequest->id,
            'submission_token' => bin2hex(random_bytes(32)),
            'customer_name' => 'مشتری تست آنلاین',
            'contact_phone' => '09123456789',
            'device_brand' => 'Newland',
            'device_model' => 'ME31',
            'issue' => 'چاپگر دستگاه کاغذ را بیرون نمی‌دهد.',
            'physical_condition' => 'سالم',
        ]);

        // Must send repair_status (hn7sgk679tfdxvt) with status=پذیرش‌شده, NOT repair_created (309p3u2jo5b7qds).
        Http::assertSent(fn ($request) => $request['code'] === 'hn7sgk679tfdxvt'
            && ($request['params']['status'] ?? null) === RepairStage::Received->label());
        Http::assertNotSent(fn ($request) => $request['code'] === '309p3u2jo5b7qds');
    }
}
