<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Services\ArticlePublishing;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PublicArticlesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private ArticleCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->superAdmin()->create();
        $this->category = ArticleCategory::findOrFail($this->command('category.create', ['name' => 'راهنمای کارتخوان', 'slug' => 'guides', 'is_active' => true]));
    }

    private function command(string $action, array $data, ?int $id = null): int
    {
        return app(ArticlePublishing::class)->execute($this->owner, $action, $data, $id);
    }

    private function draft(array $data = []): array
    {
        return $data + ['slug' => 'pos-guide', 'category_id' => $this->category->id, 'title' => 'راهنمای عمومی کارتخوان', 'summary' => 'خلاصهٔ منتشرشده', 'content' => "## نگهداری\n\nمتن **مهم** و *تأکید* با [راهنمای سازنده](https://example.com/help?a=1&b=2).\n\n- نخست\n- دوم"];
    }

    private function article(array $data = [], bool $publish = true): Article
    {
        $article = Article::findOrFail($this->command('create', $this->draft($data)));
        if ($publish) {
            $this->command('publish', ['version' => $article->version, 'reviewed_version_id' => $article->draft_version_id, 'confirmed' => true], $article->id);
        }

        return $article->refresh();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    public function test_guest_can_read_only_the_current_published_snapshot_and_safe_renderer(): void
    {
        $article = $this->article();
        $this->command('save', $this->draft(['version' => $article->version, 'title' => 'PRIVATE NEW TITLE', 'summary' => 'PRIVATE SUMMARY', 'content' => 'PRIVATE DRAFT SOURCE']), $article->id);
        $article->refresh();
        foreach (['/articles', '/articles/category/guides', '/articles/pos-guide', '/articles/pos-guide?mode=draft&version='.$article->draft_version_id] as $uri) {
            $this->get($uri)->assertOk()->assertSee('راهنمای عمومی کارتخوان')->assertDontSee('PRIVATE NEW TITLE')->assertDontSee('PRIVATE SUMMARY')->assertDontSee('PRIVATE DRAFT SOURCE')->assertDontSee($this->owner->email);
        }
        $this->get('/articles/pos-guide')->assertSee('<strong>مهم</strong>', false)->assertSee('<em>تأکید</em>', false)->assertSee('<ul>', false)->assertSee('https://example.com/help?a=1&amp;b=2', false)->assertSee('rel="noopener noreferrer"', false);
        $this->assertSame('public_id', $article->getRouteKeyName());
    }

    public function test_draft_only_and_unpublished_articles_are_not_public_even_for_owner(): void
    {
        $draft = $this->article(['title' => 'NEVER PUBLIC'], false);
        $this->actingAs($this->owner);
        $this->get('/articles')->assertOk()->assertDontSee('NEVER PUBLIC');
        $this->get('/articles/category/guides')->assertOk()->assertDontSee('NEVER PUBLIC');
        foreach ([$draft->slug, $draft->public_id, $draft->id] as $identifier) {
            $this->get('/articles/'.$identifier)->assertNotFound()->assertDontSee('NEVER PUBLIC');
        }
        $this->get('/admin/articles/'.$draft->public_id.'/preview')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('NEVER PUBLIC');
    }

    public function test_unpublication_immediately_invalidates_all_public_views_and_conditional_requests(): void
    {
        $article = $this->article(['title' => 'VISIBLE SNAPSHOT']);
        $this->get('/articles/pos-guide')->assertOk()->assertHeaderMissing('ETag')->assertHeaderMissing('Last-Modified');
        $this->get('/articles')->assertSee('VISIBLE SNAPSHOT');
        $this->command('unpublish', ['version' => $article->version, 'reviewed_version_id' => $article->published_version_id, 'confirmed' => true], $article->id);
        $this->get('/articles')->assertOk()->assertDontSee('VISIBLE SNAPSHOT')->assertHeader('X-Robots-Tag', 'noindex, follow');
        $this->get('/articles/category/guides')->assertOk()->assertDontSee('VISIBLE SNAPSHOT');
        $response = $this->withHeaders(['If-None-Match' => '"old-version"', 'If-Modified-Since' => 'Wed, 01 Jan 2098 00:00:00 GMT'])->get('/articles/pos-guide');
        $response->assertNotFound()->assertDontSee('VISIBLE SNAPSHOT')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeaderMissing('ETag')->assertHeaderMissing('Last-Modified');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(1, ArticleVersion::count());
    }

    public function test_publication_replacement_and_category_follow_the_published_version_not_the_draft(): void
    {
        $article = $this->article();
        $other = $this->command('category.create', ['name' => 'دستهٔ تازه', 'slug' => 'other', 'is_active' => true]);
        $this->command('save', $this->draft(['version' => $article->version, 'category_id' => $other, 'title' => 'NEXT VERSION']), $article->id);
        $article->refresh();
        $this->get('/articles/category/guides')->assertSee('راهنمای عمومی کارتخوان');
        $this->get('/articles/category/other')->assertDontSee('NEXT VERSION');
        $this->command('publish', ['version' => $article->version, 'reviewed_version_id' => $article->draft_version_id, 'confirmed' => true], $article->id);
        $this->get('/articles/category/guides')->assertDontSee('NEXT VERSION');
        $this->get('/articles/category/other')->assertSee('NEXT VERSION');
        $this->get('/articles/pos-guide')->assertOk()->assertSee('NEXT VERSION')->assertDontSee('راهنمای عمومی کارتخوان');
    }

    public function test_pagination_is_stable_and_does_not_propagate_unknown_or_private_query_parameters(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->article(['slug' => 'guide-'.$i, 'title' => 'CARD-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }
        $first = $this->get('/articles?private_token=DO-NOT-ECHO')->assertOk()->assertDontSee('DO-NOT-ECHO');
        $xpath = $this->xpath($first->getContent());
        $this->assertSame(12, $xpath->query('//article[contains(@class,"public-article-card")]')->length);
        $first->assertSee('CARD-14')->assertDontSee('CARD-01')->assertHeader('X-Robots-Tag', 'noindex, follow');
        $second = $this->get('/articles?page=2')->assertOk()->assertSee('CARD-02')->assertSee('CARD-01')->assertDontSee('CARD-03');
        $this->assertSame(2, $this->xpath($second->getContent())->query('//article[contains(@class,"public-article-card")]')->length);
        $second->assertSee('href="'.rtrim(config('app.url'), '/').'/articles?page=2"', false)->assertHeader('X-Robots-Tag', 'index, follow');
        $this->get('/articles/category/guides?page=2')->assertOk()->assertSee('CARD-01');
        $this->get('/articles?page=3')->assertNotFound();
    }

    public static function invalidPages(): array
    {
        return array_map(fn ($page) => [$page], ['0', '-1', '1.5', 'x', '100001', '01', '1e3', 'page%5B%5D=1']);
    }

    #[DataProvider('invalidPages')]
    public function test_invalid_pagination_is_a_non_indexable_404(string $page): void
    {
        $query = str_starts_with($page, 'page') ? $page : 'page='.$page;
        $this->get('/articles?'.$query)->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_inactive_categories_and_future_publication_are_hidden(): void
    {
        $article = $this->article();
        DB::table('article_categories')->where('id', $this->category->id)->update(['is_active' => false]);
        $this->get('/articles')->assertDontSee('خلاصهٔ منتشرشده');
        $this->get('/articles/category/guides')->assertNotFound();
        $this->get('/articles/pos-guide')->assertNotFound();
        DB::table('article_categories')->where('id', $this->category->id)->update(['is_active' => true]);
        DB::table('articles')->where('id', $article->id)->update(['published_at' => now()->addDay()]);
        $this->get('/articles/pos-guide')->assertNotFound();
    }

    public function test_metadata_canonical_and_breadcrumbs_are_escaped_and_ignore_host_and_query_input(): void
    {
        $title = '"><script>alert(1)</script>';
        $summary = '" onload="alert(2)';
        $this->article(['slug' => 'راهنمای-کارتخوان', 'title' => $title, 'summary' => $summary]);
        $response = $this->get(route('articles.show', ['slug' => 'راهنمای-کارتخوان'], false).'?preview=1')->assertOk()->assertDontSee('<script>alert', false)->assertHeader('X-Robots-Tag', 'noindex, follow');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame($summary, $xpath->query('//meta[@name="description"]')->item(0)->getAttribute('content'));
        $this->assertSame(0, $xpath->query('//script[not(@src)]|//*[@onload or @onerror]')->length);
        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertSame($title, trim($xpath->query('//nav[@aria-label="مسیر صفحه"]//*[@aria-current="page"]')->item(0)->textContent));
        $canonical = $xpath->query('//link[@rel="canonical"]')->item(0)->getAttribute('href');
        $this->assertSame(rtrim(config('app.url'), '/').route('articles.show', ['slug' => 'راهنمای-کارتخوان'], false), $canonical);
        $this->assertStringNotContainsString('preview', $canonical);
        $this->withServerVariables(['HTTP_HOST' => 'untrusted.example'])->get('/articles')->assertOk()->assertSee('href="'.rtrim(config('app.url'), '/').'/articles"', false);
    }

    public function test_private_previews_historical_versions_and_write_routes_remain_inaccessible(): void
    {
        $article = $this->article();
        $this->get('/admin/articles/'.$article->public_id.'/preview')->assertRedirect(route('admin.login'));
        foreach (['/articles/pos-guide/preview', '/articles/pos-guide/versions/'.$article->published_version_id, '/articles/'.$article->public_id, '/blog', '/sitemap.xml'] as $uri) {
            $this->get($uri)->assertNotFound();
        }
        $this->post('/admin/articles/'.$article->public_id.'/publish', ['confirmed' => true])->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->staff([])->create());
        $this->get('/admin/articles/'.$article->public_id.'/preview')->assertForbidden();
        $this->post('/admin/articles/'.$article->public_id.'/unpublish', [])->assertForbidden();
        $this->get('/articles/pos-guide')->assertOk();
    }

    public function test_draft_writes_cannot_change_the_stable_public_slug(): void
    {
        $article = $this->article();
        $this->command('save', $this->draft(['version' => $article->version, 'slug' => 'attempted-change']), $article->id);
        $this->assertSame('pos-guide', $article->refresh()->slug);
        $this->get('/articles/pos-guide')->assertOk();
        $this->get('/articles/attempted-change')->assertNotFound();
    }

    public function test_malicious_markup_and_executable_link_schemes_remain_rejected(): void
    {
        foreach (['<script>alert(1)</script>', '[x](javascript:alert)', '[x](data:text/html,test)', '<iframe src="https://example.com"></iframe>'] as $source) {
            try {
                $this->command('create', $this->draft(['content' => $source]));
                $this->fail('Unsafe source accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('content', $exception->errors());
            }
        }
        $this->assertSame(0, ArticleVersion::count());
    }

    public function test_empty_index_and_active_empty_category_have_accessible_noindex_states(): void
    {
        foreach (['/articles', '/articles/category/guides'] as $uri) {
            $response = $this->get($uri)->assertOk()->assertSee('هنوز مقاله‌ای در این بخش منتشر نشده است')->assertHeader('X-Robots-Tag', 'noindex, follow');
            $this->assertSame(1, $this->xpath($response->getContent())->query('//h1')->length);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }

    public function test_a_prior_404_is_not_cached_when_article_is_published_or_republished(): void
    {
        $article = $this->article([], false);
        $this->get('/articles/pos-guide')->assertNotFound();
        $this->command('publish', ['version' => $article->version, 'reviewed_version_id' => $article->draft_version_id, 'confirmed' => true], $article->id);
        $this->get('/articles/pos-guide')->assertOk();
        $article->refresh();
        $this->command('unpublish', ['version' => $article->version, 'reviewed_version_id' => $article->published_version_id, 'confirmed' => true], $article->id);
        $this->head('/articles/pos-guide')->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $article->refresh();
        $this->command('publish', ['version' => $article->version, 'reviewed_version_id' => $article->draft_version_id, 'confirmed' => true], $article->id);
        $this->get('/articles/pos-guide')->assertOk()->assertHeader('X-Robots-Tag', 'index, follow');
    }

    public function test_public_listing_queries_stay_bounded_without_loading_private_source_or_drafts(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->article(['slug' => 'bounded-'.$i]);
        }
        DB::enableQueryLog();
        try {
            $this->get('/articles')->assertOk();
            $queries = DB::getQueryLog();
            $this->assertLessThanOrEqual(6, count($queries));
            foreach ($queries as $query) {
                $sql = strtolower($query['query']);
                $this->assertStringNotContainsString('"source"', $sql);
                $this->assertStringNotContainsString('`source`', $sql);
                $this->assertStringNotContainsString('draft_version_id', $sql);
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
