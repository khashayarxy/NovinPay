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
            'home' => ['/', 'home'],
            'home query' => ['/?from=menu', 'home'],
            'catalogue' => ['/products', 'products.index'],
            'filtered paginated catalogue' => ['/products?q=PAX&page=2', 'products.index'],
            'NewPOS filter' => ['/products?q=NewPOS', 'products.index'],
            'Newland filter' => ['/products?q=Newland', 'products.index'],
            'catalogue trailing slash' => ['/products/', 'products.index'],
            'repair' => ['/repair', 'repair'],
            'repair query and trailing slash' => ['/repair/?from=menu', 'repair'],
            'confirmation' => ['/repair/confirmation', 'repair'],
            'confirmation query' => ['/repair/confirmation/?from=menu', 'repair'],
            'tracking' => ['/repair/track', 'repair.track'],
            'tracking query' => ['/repair/track?from=menu', 'repair.track'],
            'tracking trailing slash' => ['/repair/track/', 'repair.track'],
            'contact' => ['/contact', 'contact'],
            'consultation has repair CTA but is unrelated' => ['/consultation', null],
            'privacy' => ['/privacy', null],
            'terms' => ['/terms', null],
            'articles' => ['/articles', 'articles.index'],
        ];
    }

    #[DataProvider('sections')]
    public function test_navigation_is_server_route_aware(string $uri, ?string $current): void
    {
        $html = $this->withSession(['repair_reference' => 'NP-'.str_repeat('A', 32)])
            ->get($uri)->assertOk()->getContent();
        $this->assertNavigation($html, $current);
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
            $document = new DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new DOMXPath($document);
            $active = $xpath->query('//nav[@data-mobile-bottom-nav]/a[@aria-current]');
            $this->assertSame(1, $active->length);
            $this->assertSame(route('products.index'), $active->item(0)->getAttribute('href'));
            $this->assertSame('location', $active->item(0)->getAttribute('aria-current'));
            $this->assertSame(1, $xpath->query('//header//button[@aria-controls="category-dialog" and @aria-haspopup="dialog"]')->length);
            $this->assertSame(1, $xpath->query('//dialog[@id="category-dialog"]//a[@href="'.route('products.index').'"]')->length);
        }
    }

    public function test_published_article_and_category_routes_highlight_the_articles_link(): void
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
                $this->assertNavigation($this->get($url.$suffix)->assertOk()->getContent(), 'articles.index');
            }
        }
    }

    private function assertNavigation(string $html, ?string $current): void
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $this->assertSame(0, $xpath->query('//header/nav[@aria-label="دسترسی سریع"]')->length);
        $drawerLogo = $xpath->query('//dialog[@id="mobile-drawer"]//a[contains(@class,"np-mobile-drawer__logo") and @href="'.route('home').'"]/img');
        $this->assertSame(1, $drawerLogo->length);
        $this->assertSame($xpath->query('//header//a[contains(@class,"np-logo-link")]/img')->item(0)->getAttribute('src'), $drawerLogo->item(0)->getAttribute('src'));

        $menu = $xpath->query('//nav[@id="mobile-navigation"]')->item(0);
        $links = $xpath->query('./a', $menu);
        $this->assertSame(['صفحه اصلی', 'فروشگاه کارتخوان', 'تعمیرات کارتخوان', 'پیگیری تعمیرات', 'مقالات و آموزش‌ها', 'تماس با ما'], array_map(fn ($link) => trim($link->textContent), iterator_to_array($links)));
        $this->assertSame(0, $xpath->query('//a[normalize-space(.)="تعمیرگاه" or normalize-space(.)="خدمات و تعمیرات"]')->length);
        $this->assertSame(array_map(fn ($name) => route($name), ['home', 'products.index', 'repair', 'repair.track', 'articles.index', 'contact']), array_map(fn ($link) => $link->getAttribute('href'), iterator_to_array($links)));
        $this->assertSame(6, $xpath->query('./a/*[@aria-hidden="true"]', $menu)->length);
        $active = $xpath->query('./a[@aria-current="page"]', $menu);
        $this->assertSame($current ? 1 : 0, $active->length);
        if ($current) {
            $this->assertSame(route($current), $active->item(0)->getAttribute('href'));
        }
        $this->assertSame(0, $xpath->query('./a[@aria-current="location"]', $menu)->length);
        $this->assertSame(1, $xpath->query('//button[@aria-controls="mobile-drawer" and @aria-expanded="false" and @aria-haspopup="dialog"]')->length);
        $this->assertSame(1, $xpath->query('//dialog[@id="mobile-drawer" and @aria-label="ناوبری موبایل" and not(@open)]')->length);
        $this->assertSame(0, $xpath->query('//header//*[@role="menu" or @role="menuitem"]')->length);
        $this->assertSame(0, $xpath->query('//header//a[@href="'.route('account.index').'"]')->length);
        $this->assertSame(1, $xpath->query('//header//a[@data-customer-guest and @href="'.route('account.login').'"]')->length);
        $this->assertSame(0, $xpath->query('//header//a[(contains(@href,"/login") and not(@data-customer-guest)) or @href="#"]')->length);
        foreach ($links as $link) {
            $this->get($link->getAttribute('href'))->assertOk();
        }
    }
}
