<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersionedAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_versioned_asset_appends_filemtime_and_falls_back_when_missing(): void
    {
        $mtime = filemtime(public_path('css/storefront-refinement.css'));

        $this->assertIsInt($mtime);
        $this->assertSame(
            asset('css/storefront-refinement.css').'?v='.$mtime,
            versioned_asset('css/storefront-refinement.css')
        );
        $this->assertSame(
            asset('css/storefront-refinement.css').'?v='.$mtime,
            versioned_asset('/css/storefront-refinement.css?v=legacy')
        );
        $this->assertSame(
            asset('css/does-not-exist-novinpay.css'),
            versioned_asset('css/does-not-exist-novinpay.css')
        );
    }

    public function test_storefront_and_login_render_mtime_versioned_static_assets_and_nocache_html_header(): void
    {
        $refinementMtime = filemtime(public_path('css/storefront-refinement.css'));
        $otpMtime = filemtime(public_path('js/customer-otp.js'));

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-LiteSpeed-Cache-Control', 'no-cache')
            ->assertSee('css/storefront-refinement.css?v='.$refinementMtime, false);

        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-LiteSpeed-Cache-Control', 'no-cache')
            ->assertSee('js/customer-otp.js?v='.$otpMtime, false);
    }

    public function test_static_css_js_htaccess_revalidates_while_root_htaccess_stays_unmodified(): void
    {
        foreach (['css/.htaccess', 'js/.htaccess', 'pay/assets/.htaccess'] as $file) {
            $contents = file_get_contents(public_path($file));
            $this->assertIsString($contents);
            $this->assertStringContainsString('Cache-Control "public, max-age=0, must-revalidate"', $contents);
        }

        $rootHtaccess = file_get_contents(public_path('.htaccess'));
        $this->assertIsString($rootHtaccess);
        $this->assertStringNotContainsString('novinpay_immutable', $rootHtaccess);
    }
}
