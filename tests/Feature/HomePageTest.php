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

    public function test_service_cards_preserve_copy_icons_and_reach_existing_destinations(): void
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$this->get('/')->assertOk()->getContent());
        $xpath = new DOMXPath($document);
        $cards = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " np-service ")]');
        $expected = [
            ['انتقال مالکیت', 'راهنمایی و انجام مراحل', 'contact'],
            ['تغییر حساب', 'بررسی و راه‌اندازی مجدد', 'contact'],
            ['رفع مشکل پرینتر', 'بررسی چاپ و قطعات', 'repair'],
            ['رفع مشکل خاموشی', 'عیب‌یابی دستگاه', 'repair'],
            ['رفع مشکل آنتن‌دهی', 'بررسی ارتباط و آنتن', 'repair'],
            ['رفع مشکل شارژ', 'بررسی باتری و تغذیه', 'repair'],
            ['سایر خدمات', 'راه‌اندازی و پشتیبانی', 'contact'],
        ];
        $this->assertCount(count($expected), $cards);
        foreach ($expected as $index => [$title, $description, $route]) {
            $card = $cards->item($index);
            $this->assertSame($title, $xpath->query('./h3', $card)->item(0)->textContent);
            $this->assertSame($description, $xpath->query('./p', $card)->item(0)->textContent);
            $this->assertSame(1, $xpath->query('./span[contains(@class,"np-icon")]', $card)->length);
            $this->assertSame(route($route), $card->getAttribute('href'));
            $this->get($card->getAttribute('href'))->assertOk();
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

    public function test_homepage_uses_owner_supplied_illustration_without_verified_photograph_claims(): void
    {
        $response = $this->get('/')->assertOk();
        $response->assertSee('data-artwork-slot="hero"', false);
        $response->assertDontSee('تصویر در حال آماده‌سازی')->assertDontSee('ماسکات');
        $response->assertDontSee('np-promotions')->assertDontSee('np-why');
        $response->assertDontSee('np-model-selection')->assertDontSee('np-model-focus');
        $response->assertSee('np-location__directions')->assertDontSee('np-hero-signature');
        $response->assertSee('src="'.config('business.map_embed').'"', false);
        $response->assertSee('loading="lazy"', false);
        foreach (['PAX', 'NewPOS', 'Newland'] as $brand) {
            $response->assertSee(asset('brand/manufacturers/'.$brand.'.svg'));
        }
        $response->assertDontSee('mascot-newpos.png');
        $response->assertDontSee('file_00000000');
        $response->assertDontSee('تضمین اصالت')->assertDontSee('تحویل سریع');
    }

    public function test_hero_uses_exact_owner_copy_and_an_uncropped_eager_local_illustration(): void
    {
        $response = $this->get('/')->assertOk();
        $response->assertSee('فروش و تعمیر<br><span>کارتخوان</span>', false)
            ->assertDontSee('فروش و تعمیر تخصصی')
            ->assertSee('انواع دستگاه‌های کارتخوان از برندهای معتبر، همراه با راه‌اندازی، پشتیبانی و تعمیر تخصصی.')
            ->assertSee('تصویرسازی هوش مصنوعی، نه عکس تأییدشدهٔ محصول')
            ->assertSee('width="1360" height="941"', false)
            ->assertSee('fetchpriority="high"', false)
            ->assertSee(asset('images/home/novinpay-hero-terminal.webp'))
            ->assertSeeInOrder(['data-artwork-slot="hero"', 'class="np-eyebrow"', 'id="hero-title"', 'class="np-hero__description"', 'مشاهده محصولات', 'خدمات تعمیرات'], false);
        $this->assertSame([1360, 941], array_slice(getimagesize(public_path('images/home/novinpay-hero-terminal.webp')), 0, 2));
    }

    public function test_artwork_slot_can_load_a_local_asset_and_falls_back_for_a_missing_file(): void
    {
        config(['homepage.artwork.hero' => 'brand/novinpay-np-blue.svg']);
        $this->get('/')->assertOk()->assertSee('fetchpriority="high"', false);

        config(['homepage.artwork.hero' => 'images/not-supplied.png']);
        $this->get('/')->assertOk()->assertDontSee('fetchpriority="high"', false)
            ->assertDontSee('data-artwork-slot="hero"', false);
    }

    public function test_minimal_header_retains_navigation_without_search_or_price_controls(): void
    {
        $response = $this->get('/')->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertSame(4, $xpath->query('//header//nav[@aria-label="ناوبری اصلی"]/a')->length);
        $this->assertSame(4, $xpath->query('//header//details/nav[@aria-label="ناوبری موبایل"]/a')->length);
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
