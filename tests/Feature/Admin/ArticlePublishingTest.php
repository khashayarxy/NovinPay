<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminAction;
use App\Models\AdminAccount;
use App\Models\AdminActivity;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Services\ArticlePublishing;
use App\Services\StaffAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ArticlePublishingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private ArticleCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->superAdmin()->create();
        $this->actingAs($this->owner);
        $id = $this->command('category.create', ['name' => 'آموزش', 'slug' => 'guides', 'is_active' => true]);
        $this->category = ArticleCategory::findOrFail($id);
    }

    private function command(string $action, array $data, ?int $id = null, ?User $actor = null): int
    {
        return app(ArticlePublishing::class)->execute($actor ?? $this->owner, $action, $data, $id);
    }

    private function draft(array $data = []): array
    {
        return $data + ['slug' => 'terminal-guide', 'category_id' => $this->category->id, 'title' => 'PRIVATE-DRAFT', 'summary' => 'PRIVATE-SUMMARY', 'content' => "## آموزش\n\nمتن **پررنگ** و *تأکید* با [راهنما](https://example.com/help).\n\n- یک\n- دو"];
    }

    private function article(array $data = []): Article
    {
        return Article::findOrFail($this->command('create', $this->draft($data)));
    }

    private function publish(Article $article): void
    {
        $this->command('publish', ['version' => $article->version, 'reviewed_version_id' => $article->draft_version_id, 'confirmed' => true], $article->id);
    }

    private function conflict(callable $call, int $status = 409): void
    {
        try {
            $call();
            $this->fail('Expected denial');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        } catch (AuthorizationException) {
            $this->assertSame(403, $status);
        }
    }

    public function test_draft_and_published_snapshots_remain_independent_and_unpublication_retains_versions(): void
    {
        $article = $this->article();
        $first = $article->draft_version_id;
        $this->assertNull($article->published_version_id);
        $this->publish($article);
        $article->refresh();
        $publishedAt = $article->published_at->toIso8601String();
        $this->command('save', $this->draft(['version' => $article->version, 'title' => 'NEW PRIVATE TITLE']), $article->id);
        $article->refresh();
        $this->assertSame($first, $article->published_version_id);
        $this->assertNotSame($first, $article->draft_version_id);
        $this->assertSame('PRIVATE-DRAFT', $article->publishedVersion->title);
        $this->assertSame('NEW PRIVATE TITLE', $article->draftVersion->title);
        $this->assertSame($publishedAt, $article->published_at->toIso8601String());
        $this->command('unpublish', ['version' => $article->version, 'reviewed_version_id' => $first, 'confirmed' => true], $article->id);
        $article->refresh();
        $this->assertNull($article->published_version_id);
        $this->assertNull($article->published_at);
        $this->assertNotNull($article->unpublished_at);
        $this->assertSame(2, ArticleVersion::count());
    }

    public function test_stale_edit_publication_unpublication_and_duplicate_publication_fail(): void
    {
        $article = $this->article();
        $old = $article->version;
        $draft = $article->draft_version_id;
        $this->command('save', $this->draft(['version' => $old]), $article->id);
        $this->conflict(fn () => $this->command('save', $this->draft(['version' => $old]), $article->id));
        $this->conflict(fn () => $this->command('publish', ['version' => $old, 'reviewed_version_id' => $draft, 'confirmed' => true], $article->id));
        $article->refresh();
        $this->conflict(fn () => $this->command('publish', ['version' => $article->version, 'reviewed_version_id' => $draft, 'confirmed' => true], $article->id));
        $this->publish($article);
        $article->refresh();
        $this->conflict(fn () => $this->publish($article));
        $version = $article->version;
        $this->command('save', $this->draft(['version' => $version]), $article->id);
        $this->conflict(fn () => $this->command('unpublish', ['version' => $version, 'reviewed_version_id' => $article->published_version_id, 'confirmed' => true], $article->id));
        $this->assertSame(3, ArticleVersion::count());
    }

    public function test_slug_collisions_are_atomic_and_article_slug_cannot_be_changed_by_draft_payload(): void
    {
        $article = $this->article();
        $this->conflict(fn () => $this->article());
        $this->conflict(fn () => $this->command('category.create', ['name' => 'دیگر', 'slug' => 'guides', 'is_active' => true]));
        $this->assertSame(1, Article::count());
        $this->assertSame(1, ArticleVersion::count());
        $this->assertSame(1, ArticleCategory::count());
        $this->command('save', $this->draft(['version' => $article->version, 'slug' => 'forged-live-slug', 'published_version_id' => 999]), $article->id);
        $article->refresh();
        $this->assertSame('terminal-guide', $article->slug);
        $this->assertNull($article->published_version_id);
    }

    public function test_category_snapshots_constraints_and_stale_category_changes(): void
    {
        $article = $this->article();
        $this->publish($article);
        $this->conflict(fn () => $this->command('category.update', ['name' => 'Archive', 'slug' => 'guides', 'is_active' => false, 'version' => 1], $this->category->id));
        $other = ArticleCategory::findOrFail($this->command('category.create', ['name' => 'دوم', 'slug' => 'second', 'is_active' => true]));
        $article->refresh();
        $this->command('save', $this->draft(['category_id' => $other->id, 'version' => $article->version]), $article->id);
        $article->refresh();
        $this->assertSame($this->category->id, $article->publishedVersion->category_id);
        $this->assertSame($other->id, $article->draftVersion->category_id);
        $this->command('category.update', ['name' => 'دوم', 'slug' => 'second', 'is_active' => false, 'version' => 1], $other->id);
        $this->conflict(fn () => $this->publish($article));
        $this->conflict(fn () => $this->article(['slug' => 'inactive', 'category_id' => $other->id]), 422);
        $this->conflict(fn () => $this->command('category.update', ['name' => 'stale', 'slug' => 'second', 'is_active' => true, 'version' => 1], $other->id));
        $this->delete('/admin/article-categories/'.$other->id)->assertStatus(405);
    }

    public static function unsafe(): array
    {
        return array_map(fn ($s) => [$s], ['<script>alert(1)</script>', '<iframe src=x></iframe>', '<img src=x onerror=alert(1)>', '[x](javascript:alert)', '[x](JaVaScRiPt:alert)', '[x](data:text/html,x)', '[x](//example.com)', '[x](https://user:pass@example.com)', '[x](java&#x73;cript:alert)', '![image](https://example.com/x.png)', '[x](https://example.com/ bad)']);
    }

    #[DataProvider('unsafe')]
    public function test_malicious_source_is_rejected_without_persistence(string $source): void
    {
        try {
            $this->article(['content' => $source]);
            $this->fail('Unsafe content accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('content', $e->errors());
        }
        $this->assertSame(0, Article::count());
        $this->assertSame(0, ArticleVersion::count());
    }

    public function test_safe_rendering_private_headers_and_public_routes_absent(): void
    {
        $article = $this->article(['title' => '<script>PRIVATE-TITLE</script>', 'content' => "## عنوان\n\nمتن **قوی** و *تأکید* [پیوند](https://example.com/a?x=1&y=2) &lt;script&gt;\n\n1. نخست\n2. بعد"]);
        $this->get('/admin/articles/'.$article->public_id.'/preview')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('&lt;script&gt;PRIVATE-TITLE&lt;/script&gt;', false)->assertDontSee('<script>PRIVATE', false)->assertSee('<strong>قوی</strong>', false)->assertSee('<em>تأکید</em>', false)->assertSee('<ol>', false)->assertSee('rel="noopener noreferrer"', false)->assertSee('&amp;lt;script&amp;gt;', false);
        $this->get('/admin/articles/'.$article->public_id.'/preview?mode=published')->assertNotFound();
        $this->publish($article);
        foreach (['/articles', '/articles/terminal-guide', '/blog', '/sitemap.xml'] as $url) {
            $this->get($url)->assertNotFound()->assertDontSee('PRIVATE-TITLE');
        }
        $this->get('/admin/articles/'.$article->public_id.'/preview?mode=other')->assertSessionHasErrors('mode');
    }

    public function test_permission_boundaries_are_enforced_by_http_and_service_and_revocation(): void
    {
        $article = $this->article();
        $staff = User::factory()->staff([])->create();
        $this->actingAs($staff);
        $this->get('/admin/articles')->assertForbidden();
        $this->get('/admin/articles/'.$article->public_id.'/preview')->assertForbidden();
        $this->post('/admin/articles', $this->draft())->assertForbidden();
        $this->conflict(fn () => $this->command('create', $this->draft(), null, $staff), 403);
        $account = AdminAccount::where('user_id', $staff->id)->firstOrFail();
        $account->permissions = ['articles.view', 'articles.manage'];
        $account->save();
        $this->get('/admin/articles/create')->assertOk();
        $this->get('/admin/article-categories')->assertForbidden();
        $this->post('/admin/articles/'.$article->public_id.'/publish', ['version' => $article->version, 'reviewed_version_id' => $article->draft_version_id, 'confirmed' => true])->assertForbidden();
        $this->conflict(fn () => $this->command('publish', ['version' => $article->version, 'reviewed_version_id' => $article->draft_version_id, 'confirmed' => true], $article->id, $staff), 403);
        $account->permissions = ['articles.view', 'articles.publish'];
        $account->save();
        $this->get('/admin/articles/create')->assertForbidden();
        $this->put('/admin/articles/'.$article->public_id, $this->draft(['version' => $article->version]))->assertForbidden();
        $this->get('/admin/articles/'.$article->public_id.'/preview')->assertOk();
        $account->permissions = [];
        $account->session_version++;
        $account->save();
        $this->get('/admin/articles')->assertRedirect(route('admin.login'));
        $this->conflict(fn () => $this->command('publish', ['version' => $article->version, 'reviewed_version_id' => $article->draft_version_id, 'confirmed' => true], $article->id, $staff), 403);
    }

    public function test_category_only_staff_can_manage_categories_without_article_or_publication_access(): void
    {
        $staff = User::factory()->staff(['article_categories.manage'])->create();
        $this->actingAs($staff);
        $this->get('/admin/article-categories')->assertOk();
        $this->get('/admin/articles')->assertForbidden();
        $this->post('/admin/article-categories', ['name' => 'اختصاصی', 'slug' => 'staff-category', 'is_active' => 1])->assertRedirect();
        $this->get('/admin/article-categories/create')->assertOk();
        $this->get('/admin/article-categories/'.$this->category->id.'/edit')->assertOk();
    }

    public function test_audit_events_have_no_content_and_failures_roll_back_all_writes(): void
    {
        $article = $this->article();
        $this->publish($article);
        $log = AdminActivity::all()->toJson();
        foreach (['PRIVATE-DRAFT', 'PRIVATE-SUMMARY', 'آموزش', 'example.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        $this->assertSame(1, AdminActivity::where('action', AdminAction::ArticlePublished->value)->count());
        $event = AdminActivity::where('action', AdminAction::ArticlePublished->value)->firstOrFail();
        $this->assertSame('article_version', $event->target_type);
        $this->assertSame($article->draft_version_id, $event->target_id);
        Event::listen('articles.after-write', fn () => throw new \RuntimeException('synthetic failure'));
        $article->refresh();
        $before = ArticleVersion::count();
        $version = $article->version;
        $activities = AdminActivity::count();
        try {
            $this->command('save', $this->draft(['version' => $version]), $article->id);
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic failure', $e->getMessage());
        }
        $this->assertSame($before, ArticleVersion::count());
        $this->assertSame($version, $article->fresh()->version);
        $this->assertSame($activities, AdminActivity::count());
    }

    public function test_version_rows_and_cross_article_pointers_are_database_protected(): void
    {
        $one = $this->article();
        $two = $this->article(['slug' => 'another']);
        try {
            DB::table('articles')->where('id', $one->id)->update(['published_version_id' => $two->draft_version_id]);
            $this->fail('Foreign article pointer accepted');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        try {
            DB::table('article_versions')->where('id', $one->draft_version_id)->update(['title' => 'changed']);
            $this->fail('Version mutated');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $this->assertSame('PRIVATE-DRAFT', $one->fresh()->draftVersion->title);
    }

    public function test_http_validation_does_not_flash_private_source_and_editor_forms_render(): void
    {
        $this->from('/admin/articles/create')->post('/admin/articles', $this->draft(['content' => '<script>PRIVATE-INPUT</script>']))->assertSessionHasErrors('content')->assertSessionMissing('_old_input');
        $this->get('/admin/articles/create')->assertOk()->assertDontSee('PRIVATE-INPUT');
        $article = $this->article();
        $this->get('/admin/articles/'.$article->public_id.'/edit')->assertOk();
        $this->get('/admin/articles?q=PRIVATE&status=draft&category='.$this->category->id)->assertOk()->assertSee('PRIVATE-DRAFT');
        $this->get('/admin/articles?q=%25')->assertOk()->assertDontSee('PRIVATE-DRAFT');
    }

    public function test_deactivated_staff_and_missing_base_permission_cannot_preview_or_write(): void
    {
        $article = $this->article();
        $staff = User::factory()->staff(['articles.view', 'articles.manage', 'articles.publish'])->create();
        $this->actingAs($staff)->get('/admin/articles/'.$article->public_id.'/preview')->assertOk();
        $account = AdminAccount::where('user_id', $staff->id)->firstOrFail();
        $account->is_active = false;
        $account->session_version++;
        $account->save();
        $this->get('/admin/articles/'.$article->public_id.'/preview')->assertRedirect(route('admin.login'))->assertDontSee('PRIVATE-DRAFT');
        $this->conflict(fn () => $this->command('save', $this->draft(['version' => $article->version]), $article->id, $staff), 403);
        $account->is_active = true;
        $account->permissions = ['articles.manage'];
        $account->save();
        $this->actingAs($staff)->put('/admin/articles/'.$article->public_id, $this->draft(['version' => $article->version]))->assertForbidden();
        $this->conflict(fn () => $this->command('save', $this->draft(['version' => $article->version]), $article->id, $staff), 403);
    }

    public function test_content_grants_require_the_base_permission_during_provisioning(): void
    {
        foreach (['articles.manage', 'articles.publish'] as $permission) {
            try {
                app(StaffAdministration::class)->save($this->owner, ['current_password' => 'password', 'permissions' => [$permission]]);
                $this->fail('Missing base grant accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('permissions', $exception->errors());
            }
        }
    }

    public function test_guests_and_ordinary_users_cannot_obtain_private_content(): void
    {
        $article = $this->article();
        $ordinary = User::factory()->create();
        $this->withSession(['admin_session_version' => null])->actingAs($ordinary)->get('/admin/articles/'.$article->public_id.'/preview')->assertForbidden()->assertDontSee('PRIVATE-DRAFT');
        auth()->logout();
        $this->get('/admin/articles/'.$article->public_id.'/preview')->assertRedirect(route('admin.login'))->assertDontSee('PRIVATE-DRAFT');
    }
}
