<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_presents_all_approved_sections_and_seven_service_links(): void
    {
        $response = $this->get('/')->assertOk();
        foreach (['hero-title', 'popular-services-title', 'brands-title', 'location-title'] as $section) {
            $response->assertSee('id="'.$section.'"', false);
        }
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertSame(7, $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " np-service ")]')->length);
        $this->assertSame(4, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " np-trust__item ")]')->length);
        foreach ($xpath->query('//a') as $link) {
            $this->assertNotSame('', $link->getAttribute('href'));
            $this->assertNotSame('#', $link->getAttribute('href'));
        }
    }

    public function test_homepage_only_names_supported_brands_and_links_to_existing_catalogue_filters(): void
    {
        $response = $this->get('/')->assertOk();
        foreach (['PAX', 'NewPOS', 'Newland'] as $brand) {
            $response->assertSee(route('products.index', ['q' => $brand]), false);
            $this->get(route('products.index', ['q' => $brand]))->assertOk();
        }
        $response->assertDontSee('Verifone')->assertDontSee('Ingenico');
    }

    public function test_homepage_has_finished_fallback_compositions_without_unlicensed_or_fake_photographs(): void
    {
        $response = $this->get('/')->assertOk();
        $response->assertSee('data-artwork-slot="hero"', false);
        $response->assertDontSee('تصویر در حال آماده‌سازی')->assertDontSee('ماسکات');
        $response->assertDontSee('np-promotions')->assertDontSee('np-why');
        $response->assertDontSee('np-model-selection')->assertDontSee('np-model-focus');
        $response->assertSee('np-location__directions')->assertSee('np-hero-signature');
        $response->assertDontSee('<iframe', false);
        foreach (['PAX', 'NewPOS', 'Newland'] as $brand) {
            $response->assertSee(asset('brand/manufacturers/'.$brand.'.svg'));
        }
        $response->assertDontSee('mascot-newpos.png');
        $response->assertDontSee('file_00000000');
        $response->assertDontSee('تضمین اصالت')->assertDontSee('تحویل سریع');
    }

    public function test_artwork_slot_can_load_a_local_asset_and_falls_back_for_a_missing_file(): void
    {
        config(['homepage.artwork.hero' => 'brand/novinpay-np-blue.svg']);
        $this->get('/')->assertOk()->assertSee('fetchpriority="high"', false);

        config(['homepage.artwork.hero' => 'images/not-supplied.png']);
        $this->get('/')->assertOk()->assertDontSee('fetchpriority="high"', false)
            ->assertSee('np-hero-signature');
    }

    public function test_minimal_header_retains_navigation_without_search_or_price_controls(): void
    {
        $response = $this->get('/')->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertSame(6, $xpath->query('//header//nav[@aria-label="ناوبری اصلی"]/a')->length);
        $this->assertSame(6, $xpath->query('//header//details/nav[@aria-label="ناوبری موبایل"]/a')->length);
        $this->assertSame(0, $xpath->query('//header//a[contains(@class,"np-search") or contains(@class,"np-inquiry")]')->length);
        $this->assertSame(5, $xpath->query('//main/section')->length);
        $response->assertSeeInOrder(['id="hero-title"', 'np-trust np-container', 'id="popular-services-title"', 'id="brands-title"', 'id="location-title"'], false);
    }

    public function test_public_pages_share_homepage_chrome_while_admin_remains_separate(): void
    {
        $this->get('/contact')->assertOk()->assertSee('np-header__inner')
            ->assertSee('np-footer__grid');
        $this->get('/admin/login')->assertOk()->assertDontSee('np-header__inner');
    }
}
