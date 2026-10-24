<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Product;
use App\Models\User;
use App\Services\ArticlePublishing;
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
            'NewPOS filter' => ['/products?q=NewPOS', ['home', 'repair'], 'products.index'],
            'Newland filter' => ['/products?q=Newland', ['home', 'repair'], 'products.index'],
            'catalogue trailing slash' => ['/products/', ['home', 'repair'], 'products.index'],
            'repair' => ['/repair', ['home', 'products.index'], 'repair'],
            'repair query and trailing slash' => ['/repair/?from=menu', ['home', 'products.index'], 'repair'],
            'confirmation' => ['/repair/confirmation', ['home', 'products.index'], 'repair'],
            'confirmation query' => ['/repair/confirmation/?from=menu', ['home', 'products.index'], 'repair'],
            'tracking' => ['/repair/track', ['products.index', 'repair'], 'repair.track'],
            'tracking query' => ['/repair/track?from=menu', ['products.index', 'repair'], 'repair.track'],
            'tracking trailing slash' => ['/repair/track/', ['products.index', 'repair'], 'repair.track'],
            'contact' => ['/contact', ['products.index', 'repair'], 'contact'],
            'consultation has repair CTA but is unrelated' => ['/consultation', ['products.index', 'repair'], null],
            'privacy' => ['/privacy', ['products.index', 'repair'], null],
            'terms' => ['/terms', ['products.index', 'repair'], null],
            'articles' => ['/articles', ['products.index', 'repair'], 'articles.index'],
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

    public function test_published_article_and_category_routes_keep_default_shortcuts_and_article_highlight(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $publishing = app(ArticlePublishing::class);
        $categoryId = $publishing->execute($owner, 'category.create', ['name' => 'راهنما', 'slug' => 'navigation-guides', 'is_active' => true]);
        $articleId = $publishing->execute($owner, 'create', [
            'slug' => 'navigation-guide', 'category_id' => $categoryId,
            'title' => 'راهنمای کارتخوان', 'summary' => 'خلاصهٔ عمومی', 'content' => 'راهنمای نگهداری دستگاه.',
        ]);
        $article = Article::findOrFail($articleId);
        $publishing->execute($owner, 'publish', ['version' => $article->version, 'reviewed_version_id' => $article->draft_version_id, 'confirmed' => true], $articleId);

        foreach ([route('articles.show', $article->slug), route('articles.category', ArticleCategory::findOrFail($categoryId)->slug)] as $url) {
            foreach (['', '?from=menu', '/'] as $suffix) {
                $this->assertNavigation($this->get($url.$suffix)->assertOk()->getContent(), ['products.index', 'repair'], 'articles.index');
            }
        }
    }

    private function assertNavigation(string $html, array $quickRoutes, ?string $current): void
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $quick = $xpath->query('//header/nav[@aria-label="دسترسی سریع"]/a');
        $this->assertCount(2, $quick);
        $this->assertSame(array_map(fn ($name) => route($name), $quickRoutes), array_map(fn ($link) => $link->getAttribute('href'), iterator_to_array($quick)));
        $labels = ['home' => 'صفحه اصلی', 'products.index' => 'فروشگاه', 'repair' => 'تعمیرات'];
        $this->assertSame(array_map(fn ($name) => $labels[$name], $quickRoutes), array_map(fn ($link) => trim($link->textContent), iterator_to_array($quick)));
        $this->assertSame(0, $xpath->query('//header/nav[@aria-label="دسترسی سریع"]/a[@aria-current]')->length);

        $menu = $xpath->query('//nav[@id="mobile-navigation"]')->item(0);
        $links = $xpath->query('./a', $menu);
        $this->assertSame(['پیگیری تعمیرات', 'مقالات', 'تماس با ما'], array_map(fn ($link) => trim($link->textContent), iterator_to_array($links)));
        $this->assertSame(0, $xpath->query('//a[normalize-space(.)="تعمیرگاه" or normalize-space(.)="خدمات و تعمیرات"]')->length);
        $this->assertSame(array_map(fn ($name) => route($name), ['repair.track', 'articles.index', 'contact']), array_map(fn ($link) => $link->getAttribute('href'), iterator_to_array($links)));
        $this->assertSame(0, $xpath->query('./a[normalize-space(.)="صفحه اصلی" or normalize-space(.)="خرید کارتخوان" or normalize-space(.)="تعمیرات"]', $menu)->length);
        $active = $xpath->query('./a[@aria-current="page"]', $menu);
        $verticalCurrent = in_array($current, ['repair.track', 'articles.index', 'contact'], true) ? $current : null;
        $this->assertSame($verticalCurrent ? 1 : 0, $active->length);
        if ($verticalCurrent) {
            $this->assertSame(route($verticalCurrent), $active->item(0)->getAttribute('href'));
        }
        $this->assertSame(0, $xpath->query('./a[@aria-current="location"]', $menu)->length);
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
