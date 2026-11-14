<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Http\Middleware\PreviewAwareSession;
use App\Models\Product;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PublicPerformanceSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_uses_local_avif_with_original_webp_fallback_and_no_eager_map(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($doc);
        $this->assertSame(0, $xpath->query('//iframe')->length);
        $source = $xpath->query('//*[@data-artwork-slot="hero"]//source[@type="image/avif"]')->item(0);
        $this->assertNotNull($source);
        foreach ([400, 640, 853] as $width) {
            $path = 'images/home/novinpay-terminal-clean-'.$width.'.avif';
            $this->assertStringContainsString(asset($path), $source->getAttribute('srcset'));
            $this->assertFileExists(public_path($path));
        }
        $image = $xpath->query('//*[@data-artwork-slot="hero"]//img')->item(0);
        $this->assertSame('high', $image->getAttribute('fetchpriority'));
        $this->assertFalse($image->hasAttribute('loading'));
        $this->assertSame(asset('images/home/novinpay-terminal-clean.webp'), $image->getAttribute('src'));
    }

    public function test_public_social_and_structured_data_use_trusted_identity_without_fabricated_claims(): void
    {
        config(['app.url' => 'https://novinpaypos.com']);
        foreach (['/', '/contact'] as $path) {
            $response = $this->get($path.'?token=untrusted-secret')->assertOk();
            $doc = new DOMDocument;
            @$doc->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $xpath = new DOMXPath($doc);
            $this->assertSame('https://novinpaypos.com'.$path, $xpath->query('//meta[@property="og:url"]')->item(0)->getAttribute('content'));
            $json = $xpath->query('//script[@type="application/ld+json"]')->item(0)->textContent;
            $schema = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('https://schema.org', $schema['@context']);
            $this->assertStringNotContainsString('<?php', $json);
            $this->assertSame('Organization', $schema['@graph'][0]['@type']);
            $this->assertSame(config('business.name'), $schema['@graph'][0]['name']);
            $this->assertSame(config('business.address'), $schema['@graph'][0]['address']);
            foreach (['aggregateRating', 'review', 'offers', 'openingHours', 'untrusted-secret'] as $unverified) {
                $this->assertStringNotContainsString($unverified, $json);
            }
        }
    }

    public function test_private_login_has_no_public_social_or_structured_account_metadata(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('application/ld+json', false)
            ->assertDontSee('property="og:url"', false)->assertSee('noindex, nofollow');
    }

    public function test_product_schema_escapes_markup_and_never_fabricates_offers_or_indexes_samples(): void
    {
        $product = Product::create([
            'name' => 'دستگاه </script><script>alert(1)</script>', 'slug' => 'schema-product',
            'summary' => 'توضیحات واقعی', 'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New, 'stock_status' => StockStatus::InStock,
            'is_published' => true, 'is_sample' => false, 'price' => 100000,
        ]);
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$this->get('/products/'.$product->slug)->assertOk()->getContent());
        $xpath = new DOMXPath($doc);
        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        $this->assertSame(1, $scripts->length);
        $json = $scripts->item(0)->textContent;
        $schema = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('Product', $schema['@graph'][0]['@type']);
        $this->assertSame($product->name, $schema['@graph'][0]['name']);
        $this->assertSame('BreadcrumbList', $schema['@graph'][1]['@type']);
        $this->assertStringNotContainsString('</script>', $json);
        $this->assertArrayNotHasKey('offers', $schema['@graph'][0]);
        $this->assertArrayNotHasKey('aggregateRating', $schema['@graph'][0]);
        $product->update(['is_sample' => true]);
        $this->get('/products/'.$product->slug)->assertOk()->assertDontSee('application/ld+json', false)->assertSee('noindex, follow');
        $product->update(['is_published' => false]);
        $this->get('/products/'.$product->slug)->assertNotFound();
    }

    public function test_ai_navigation_references_only_existing_public_routes_on_configured_origin(): void
    {
        config(['app.url' => 'https://novinpaypos.com']);
        $response = $this->get('/llms.txt?phone=untrusted-secret')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $text = $response->getContent();
        foreach (['/account', '/admin', '/support', '.env', 'untrusted-secret'] as $private) {
            $this->assertStringNotContainsString($private, $text);
        }
        preg_match_all('/\]\(https:\/\/novinpaypos\.com([^)]*)\)/', $text, $matches);
        $this->assertCount(5, $matches[1]);
        foreach ($matches[1] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_crawler_documents_do_not_create_or_refresh_customer_sessions(): void
    {
        foreach (['/llms.txt', '/sitemap.xml', '/sitemap-pages-1.xml'] as $path) {
            $this->get($path)->assertOk()->assertHeaderMissing('Set-Cookie');
            $route = app('router')->getRoutes()->match(Request::create($path));
            $this->assertContains(PreviewAwareSession::class, $route->excludedMiddleware());
        }
    }
}
