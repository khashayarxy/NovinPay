<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    public static function pages(): array
    {
        return PublicPagesTest::pageProvider();
    }

    #[DataProvider('pages')]
    public function test_public_navigation_has_correct_active_state_and_links(string $uri): void
    {
        $response = $this->get($uri)->assertOk()->assertSee('np-header__inner')->assertSee('np-footer__grid');
        foreach (['repair.track', 'contact', 'terms', 'privacy'] as $route) {
            $response->assertSee('href="'.route($route).'"', false);
        }
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//h1')->length);
        $expected = in_array($uri, ['/terms', '/privacy'], true) ? 0 : 1;
        $this->assertSame($expected, $xpath->query('//header//nav[@aria-label="ناوبری اصلی"]/a[@aria-current="page"]')->length);
    }

    public function test_retired_general_pages_are_not_redirected_or_linked(): void
    {
        foreach (['/about', '/services'] as $uri) {
            $this->get($uri)->assertNotFound()->assertHeaderMissing('Location');
            foreach (array_column(self::pages(), 0) as $page) {
                $this->get($page)->assertOk()->assertDontSee('href="'.url($uri).'"', false);
            }
        }
    }

    public function test_contact_uses_configured_business_data_without_invented_history(): void
    {
        config(['business.phone' => 'TEST-PHONE', 'business.phone_uri' => 'tel:TEST-PHONE', 'business.address' => 'TEST-ADDRESS']);
        $this->get('/contact')->assertOk()->assertSee('TEST-ADDRESS')->assertSee('tel:TEST-PHONE', false)
            ->assertDontSee('09362896373')->assertSee('PAX')->assertSee('NewPOS')->assertSee('Newland');
    }

    public function test_legal_drafts_describe_active_form_honestly_and_stay_unindexed(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('پیش‌نویس')->assertSee('نیازمند تأیید مالک')
            ->assertSee('نام، شمارهٔ همراه')->assertSee('مدت نگهداری')->assertSee('content="noindex, nofollow"', false)
            ->assertDontSee('داده‌ای از بازدیدکنندگان دریافت نمی‌شود');
        $this->get('/terms')->assertOk()->assertSee('نیازمند تأیید مالک')->assertSee('به معنی دریافت فیزیکی دستگاه');
    }
}
