<?php

namespace Tests\Feature;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class MaintenancePageTest extends TestCase
{
    public function test_maintenance_is_standalone_503_without_database_queries(): void
    {
        $mode = Mockery::mock(MaintenanceMode::class);
        $mode->shouldReceive('active')->andReturnTrue();
        $mode->shouldReceive('data')->andReturn(['retry' => 120, 'status' => 503]);
        $this->app->instance(MaintenanceMode::class, $mode);
        DB::shouldReceive('connection')->never();

        $response = $this->get('/products');
        $response->assertStatus(503)->assertHeader('Retry-After', '120');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertSee('در حال تعمیرات و به‌روزرسانی سایت نوین پی هستیم');
        $response->assertSee('tel:09362896373', false)->assertSee('tel:09384144021', false);
        $response->assertSee('۰۹۳۸۴۱۴۴۰۲۱')->assertSee('۰۹۳۶۲۸۹۶۳۷۳');
        foreach (['np-header', 'mobile-navigation', '<footer', '<script', '/build/', 'مشاهده محصولات'] as $forbidden) {
            $response->assertDontSee($forbidden, false);
        }
        $response->assertSee('data:font/woff2;base64,', false)->assertSee('data:image/webp;base64,', false);
        $this->assertSame(1, substr_count($response->getContent(), '<h1 '));
        $this->assertStringContainsString('<html lang="fa" dir="rtl">', $response->getContent());
    }

    public function test_json_maintenance_is_still_503_and_does_not_disclose_internal_details(): void
    {
        $mode = Mockery::mock(MaintenanceMode::class);
        $mode->shouldReceive('active')->andReturnTrue();
        $mode->shouldReceive('data')->andReturn(['retry' => 60, 'status' => 503]);
        $this->app->instance(MaintenanceMode::class, $mode);
        config(['app.debug' => false]);
        $this->getJson('/products')->assertStatus(503)->assertHeader('Retry-After', '60')
            ->assertJsonMissingPath('trace')->assertJsonMissingPath('file');
    }

    public function test_rendered_template_uses_only_inline_assets_and_configured_contacts(): void
    {
        $html = view('errors.503')->render();
        preg_match_all('/\b(?:src|href)="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $url) {
            $this->assertTrue(str_starts_with($url, 'data:') || in_array($url, ['tel:'.config('business.phone'), 'tel:'.config('business.secondary_phone')], true));
        }
        foreach (['از صبر و شکیبایی شما صمیمانه سپاسگزاریم.', 'برای ارتباط با ما با شماره‌های زیر تماس بگیرید.'] as $copy) {
            $this->assertStringContainsString($copy, $html);
        }
        foreach (['بهبود عملکرد', 'افزایش امنیت', 'خدمات بهتر'] as $removed) {
            $this->assertStringNotContainsString($removed, $html);
        }
        $this->assertStringContainsString(base64_encode(file_get_contents(public_path('brand/lockups/logo-wordmark-black-on-white.svg'))), $html);
        $this->assertStringContainsString(base64_encode(file_get_contents(resource_path('maintenance/assets/pos-repair.webp'))), $html);
        $this->assertLessThan(230000, strlen($html));
    }
}
