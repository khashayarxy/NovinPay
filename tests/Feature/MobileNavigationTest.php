<?php

namespace Tests\Feature;

use App\Models\Product;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MobileNavigationTest extends TestCase
{
    use RefreshDatabase;

    public static function sections(): array
    {
        return [
            'home' => ['/', ['products.index', 'repair'], 'home'],
            'home query' => ['/?from=menu', ['products.index', 'repair'], 'home'],
            'catalogue' => ['/products', ['home', 'repair'], 'products.index'],
            'filtered paginated catalogue' => ['/products?q=PAX&page=2', ['home', 'repair'], 'products.index'],
            'catalogue trailing slash' => ['/products/', ['home', 'repair'], 'products.index'],
            'repair' => ['/repair', ['home', 'products.index'], 'repair'],
            'repair query and trailing slash' => ['/repair/?from=menu', ['home', 'products.index'], 'repair'],
            'confirmation' => ['/repair/confirmation', ['home', 'products.index'], 'repair'],
            'tracking' => ['/repair/track', ['home', 'products.index'], 'repair.track'],
            'tracking query' => ['/repair/track?from=menu', ['home', 'products.index'], 'repair.track'],
            'contact' => ['/contact', ['home', 'products.index', 'repair'], 'contact'],
            'consultation has repair CTA but is unrelated' => ['/consultation', ['home', 'products.index', 'repair'], null],
            'privacy' => ['/privacy', ['home', 'products.index', 'repair'], null],
            'terms' => ['/terms', ['home', 'products.index', 'repair'], null],
        ];
    }

    #[DataProvider('sections')]
    public function test_navigation_is_server_route_aware(string $uri, array $quickRoutes, ?string $current): void
    {
        $html = $this->withSession(['repair_reference' => 'NP-'.str_repeat('A', 32)])
            ->get($uri)->assertOk()->getContent();
        $this->assertNavigation($html, $quickRoutes, $current);
    }

    public function test_published_product_slugs_and_queries_remain_in_shopping_section(): void
    {
        $product = Product::create([
            'name' => 'کارتخوان آزمایشی', 'slug' => 'navigation-pos-123',
            'summary' => 'آزمون ناوبری', 'description' => 'آزمون جزئیات محصول',
            'category' => 'pos_terminal', 'condition' => 'new', 'stock_status' => 'in_stock',
            'is_published' => true, 'is_sample' => true,
        ]);
        foreach (['', '?from=menu', '/'] as $suffix) {
            $html = $this->get(route('products.show', $product).$suffix)->assertOk()->getContent();
            $this->assertNavigation($html, ['home', 'repair'], 'products.index');
        }
    }

    private function assertNavigation(string $html, array $quickRoutes, ?string $current): void
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $quick = $xpath->query('//header/nav[@aria-label="دسترسی سریع"]/a');
        $this->assertSame(array_map(fn ($name) => route($name), $quickRoutes), array_map(fn ($link) => $link->getAttribute('href'), iterator_to_array($quick)));
        $labels = ['home' => 'صفحه اصلی', 'products.index' => 'خرید کارتخوان', 'repair' => 'خدمات و تعمیرات'];
        $this->assertSame(array_map(fn ($name) => $labels[$name], $quickRoutes), array_map(fn ($link) => trim($link->textContent), iterator_to_array($quick)));
        $this->assertSame(0, $xpath->query('//header/nav[@aria-label="دسترسی سریع"]/a[@aria-current]')->length);

        $menu = $xpath->query('//nav[@id="mobile-navigation"]')->item(0);
        $links = $xpath->query('./a', $menu);
        $this->assertSame(['صفحه اصلی', 'خرید کارتخوان', 'خدمات و تعمیرات', 'پیگیری تعمیرات', 'تماس با ما'], array_map(fn ($link) => trim($link->textContent), iterator_to_array($links)));
        $this->assertSame(array_map(fn ($name) => route($name), ['home', 'products.index', 'repair', 'repair.track', 'contact']), array_map(fn ($link) => $link->getAttribute('href'), iterator_to_array($links)));
        $active = $xpath->query('./a[@aria-current="page"]', $menu);
        $this->assertSame($current ? 1 : 0, $active->length);
        if ($current) {
            $this->assertSame(route($current), $active->item(0)->getAttribute('href'));
        }
        $this->assertSame($current === 'repair.track' ? 1 : 0, $xpath->query('./a[@aria-current="location"]', $menu)->length);
        // Without JS, summary exposes its native expanded state. The enhancement
        // adds aria-expanded at runtime rather than leaving a stale false value.
        $this->assertSame(1, $xpath->query('//summary[@aria-controls="mobile-navigation" and not(@aria-expanded)]')->length);
        $this->assertSame(0, $xpath->query('//header//*[@role="menu" or @role="menuitem"]')->length);
        $this->assertSame(0, $xpath->query('//header//a[contains(@href,"/account") or contains(@href,"/login") or @href="#"]')->length);
        foreach ($links as $link) {
            $this->get($link->getAttribute('href'))->assertOk();
        }
    }
}
