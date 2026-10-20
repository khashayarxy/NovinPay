<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BusinessFoundationTest extends TestCase
{
    use RefreshDatabase;

    public static function publicPageProvider(): array
    {
        return PublicPagesTest::pageProvider();
    }

    #[DataProvider('publicPageProvider')]
    public function test_public_layout_shares_official_contacts_and_brand_assets(string $uri): void
    {
        $response = $this->get($uri)->assertOk();
        $response->assertSee('شهریار، بلوار رسول اکرم، خیابان مفتح، پاساژ الماس شهر، طبقه زیر همکف، واحد ۸۷');
        $response->assertSee('href="tel:09362896373"', false);
        $response->assertSee('شنبه تا پنجشنبه ۹ تا ۱۸')->assertSee('جمعه تعطیل');
        $response->assertSee(asset('brand/novinpay-np-blue.svg'));
        $response->assertSee(asset('brand/novinpay-np-white.svg'));
        $response->assertSee(asset('favicon.svg'));
        $response->assertSee('content="#098CF7"', false);
        $response->assertSee('alt="نوین‌پی"', false);
        $response->assertSee('aria-label="ناوبری اصلی"', false);
        $response->assertSee('href="#main-content"', false);

        foreach (['https://balad.ir/p/PtbHeIpomT37HO', 'https://nshn.ir/c4rbvYA0OxCadU', 'https://maps.app.goo.gl/URGQuZyfFAB46zZWA'] as $url) {
            $response->assertSee('href="'.$url.'"', false);
        }
        $response->assertSee('rel="noopener noreferrer"', false);
        $response->assertDontSee('اطلاعات تماس رسمی هنوز اعلام نشده است');
    }

    public function test_contact_consumers_follow_central_configuration(): void
    {
        config(['business.phone' => 'TEST-PHONE', 'business.phone_uri' => 'tel:TEST-PHONE', 'business.address' => 'TEST-ADDRESS']);

        foreach (['/', '/contact', '/repair'] as $uri) {
            $response = $this->get($uri)->assertOk();
            $response->assertSee('href="tel:TEST-PHONE"', false)->assertSee('TEST-ADDRESS');
            $response->assertDontSee('09362896373');
        }
    }

    public function test_admin_login_uses_official_logo_and_favicon(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee(asset('brand/novinpay-np-blue.svg'))
            ->assertSee(asset('favicon.svg'))
            ->assertSee('noindex, nofollow');
    }

    public function test_official_logo_and_favicon_bytes_are_preserved(): void
    {
        // SHA-256 fingerprints of the owner-supplied NovinPay-Logo-Package.zip
        // entries. These assets must never be redrawn or edited.
        foreach ([
            'brand/novinpay-np-blue.svg' => '856e86db915a4164fdbbe1f75909a087b77ace19261bb6c7e867dafbe623daef',
            'brand/novinpay-np-white.svg' => '142ca81871d903d9aea7f4d493f85b61e09257403777ecf7e6b823bbdfab8aec',
            'brand/novinpay-np-black.svg' => '510315c7a1b964f49ddbf81fd1503eef5b528be20f9421ccac0b9d2e482b6761',
            'favicon.ico' => 'dc4cab77ddf00dec5eb4f45e1feee3523b1b8f6d80491d7f7661ca5387d5261d',
        ] as $path => $fingerprint) {
            $this->assertSame($fingerprint, hash_file('sha256', public_path($path)), $path);
        }

        $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
    }
}
