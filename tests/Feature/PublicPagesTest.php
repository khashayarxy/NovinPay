<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public static function pageProvider(): array
    {
        return [
            'home' => ['/'],
            'products' => ['/products'],
            'services' => ['/services'],
            'repair' => ['/repair'],
            'contact' => ['/contact'],
            'terms' => ['/terms'],
            'privacy' => ['/privacy'],
            'about' => ['/about'],
            'tracking' => ['/repair/track'],
        ];
    }

    #[DataProvider('pageProvider')]
    public function test_public_pages_render(string $uri): void
    {
        $this->get($uri)->assertOk();
    }

    #[DataProvider('pageProvider')]
    public function test_public_pages_use_persian_rtl_layout(string $uri): void
    {
        $response = $this->get($uri);

        $response->assertSee('lang="fa"', false);
        $response->assertSee('dir="rtl"', false);
        $response->assertSee(config('app.name'));
    }

    #[DataProvider('pageProvider')]
    public function test_public_pages_do_not_mention_legacy_brand(string $uri): void
    {
        $this->get($uri)->assertDontSee('Kartkhan');
    }

    public function test_home_page_shows_main_call_to_actions(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(route('products.index'));
        $response->assertSee(route('repair'));
    }

    public function test_repair_page_distinguishes_online_submission_from_physical_receipt(): void
    {
        $response = $this->get('/repair');

        $response->assertOk();
        $response->assertSee('به معنی دریافت دستگاه نیست');
        $response->assertSee('کد پیگیری');
        $response->assertSee('method="post"', false)->assertDontSee('fieldset disabled');
    }

    public function test_contact_page_displays_official_business_details(): void
    {
        $response = $this->get('/contact');

        $response->assertOk();
        $response->assertSee('09362896373');
        $response->assertSee('پاساژ الماس شهر');
        $response->assertDontSee('[در حال تکمیل]');
    }

    public function test_legal_pages_do_not_publish_final_policies(): void
    {
        $this->get('/terms')->assertOk()->assertSee('پیش‌نویس');
        $this->get('/privacy')->assertOk()->assertSee('پیش‌نویس');
    }
}
