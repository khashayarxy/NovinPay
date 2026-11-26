<?php

namespace Tests\Feature;

use App\Enums\RepairBrand;
use App\Models\Consultation;
use App\Models\CustomerAccount;
use App\Models\RepairRequest;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase4UiUxPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultation_page_uses_split_card_layout_removes_legacy_hero_and_quick_links_and_preserves_submission(): void
    {
        $response = $this->get('/consultation');
        $response->assertOk()
            ->assertSee('repair-split-card consultation-split-card', false)
            ->assertSee('درخواست مشاوره خرید و خدمات')
            ->assertSee('راهنمایی دقیق متناسب با کسب‌وکار شما')
            ->assertSee(config('business.phone_uri'), false)
            ->assertSee('data-public-submit-loading', false)
            ->assertSee('data-loading-label="در حال ارسال..."', false)
            ->assertSee('inputmode="numeric"', false)
            ->assertSee('autocomplete="tel"', false)
            ->assertDontSee('پیش از تصمیم، با ما مشورت کنید')
            ->assertDontSee('دسترسی سریع به خدمات')
            ->assertDontSee('←');

        $token = (string) session('consultation_token');
        $this->assertNotEmpty($token);

        $this->post('/consultation', [
            'submission_token' => $token,
            'customer_name' => 'سارا محمدی',
            'contact_phone' => '۰۹۱۲۳۴۵۶۷۸۹',
            'topic' => array_key_first(Consultation::TOPICS),
            'device_info' => 'PAX S910',
            'question' => 'برای سوپرمارکت پررفت‌وآمد کدام مدل باتری بهتری دارد؟',
        ])->assertRedirect(route('consultation.confirmation'));

        $this->assertDatabaseCount('consultations', 1);
    }

    public function test_mobile_hamburger_cart_badge_and_mobile_support_launcher_styles_are_unified(): void
    {
        $homeCss = (string) file_get_contents(resource_path('css/home.css'));
        $refinementCss = (string) file_get_contents(public_path('css/storefront-refinement.css'));
        $storeNavCss = (string) file_get_contents(public_path('css/store-navigation.css'));

        // 1. Mobile hamburger drawer cart badge uses exact same #b4232d red as floating bottom nav
        $this->assertMatchesRegularExpression(
            '/\.np-mobile-drawer__cart\s+\.store-cart-count\s*\{[^}]*background:\s*#b4232d/s',
            $homeCss
        );

        // 2. Mobile (< 768px) support launcher is compact 48px x 48px and hides when inputs are focused
        $this->assertStringContainsString('--support-launcher-size: 48px;', $refinementCss);
        $this->assertStringContainsString('button[data-support-launcher]', $storeNavCss);
    }

    public function test_pos_serial_field_enforces_uppercase_ltr_attributes_and_server_side_uppercase_normalization(): void
    {
        $repairHtml = $this->get('/repair')->assertOk()->getContent();

        $this->assertStringContainsString('id="device_serial"', $repairHtml);
        $this->assertStringContainsString('autocapitalize="characters"', $repairHtml);
        $this->assertStringContainsString('data-pos-serial', $repairHtml);
        $this->assertStringContainsString('pos-serial-input', $repairHtml);
        $this->assertStringNotContainsString('arrow-left', $repairHtml);
        $this->assertStringContainsString('np-chevron-inline', $repairHtml);

        $trackHtml = $this->get('/repair/track')->assertOk()->getContent();
        $this->assertStringContainsString('inputmode="numeric"', $trackHtml);
        $this->assertStringContainsString('autocomplete="tel"', $trackHtml);
        $this->assertStringContainsString('data-public-submit-loading', $trackHtml);
        $this->assertStringNotContainsString('arrow-left', $trackHtml);

        // Submit repair as verified customer with lowercase + Persian-digit serial -> stored uppercase ASCII
        $customer = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09123456789'),
            'phone' => '09123456789',
            'name' => 'رضا کاظمی',
            'verified_at' => now(),
        ])->fresh();

        $getRepair = $this->actingAs($customer, 'customer')
            ->withSession(['customer_session_version' => $customer->session_version])
            ->get('/repair')
            ->assertOk();

        preg_match('/name="submission_token"\s+value="([a-f0-9]{64})"/', $getRepair->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $this->actingAs($customer, 'customer')
            ->withSession(['customer_session_version' => $customer->session_version])
            ->post('/repair', [
                'submission_token' => $matches[1],
                'device_brand' => RepairBrand::Pax->value,
                'device_model' => 'S910',
                'device_serial' => 'pax-s910-۹۸۱۲۳ab',
                'issue' => 'دستگاه روشن می‌شود اما رسید چاپ نمی‌کند.',
                'customer_name' => 'رضا کاظمی',
                'contact_phone' => '09123456789',
                'province' => 'تهران',
                'city' => 'شهریار',
                'handover_method' => 'in_person',
            ])->assertRedirect(route('repair.confirmation'));

        $savedRepair = RepairRequest::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString('شماره سریال: PAX-S910-98123AB', (string) $savedRepair->issue);
    }
}
