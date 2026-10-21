<?php

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Services\ArticlePublishing;
use Illuminate\Support\Facades\Artisan;

try {
    require dirname(__DIR__).'/Concurrency/bootstrap.php';
} catch (Throwable $exception) {
    fwrite(STDERR, 'Disposable bootstrap failed'.PHP_EOL);
    exit(1);
}
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, 'Article audit failed: '.get_class($exception).PHP_EOL);
    exit(1);
});

Artisan::call('migrate:fresh', ['--force' => true]);
$one = User::factory()->superAdmin()->create();
$two = User::factory()->staff(['articles.view', 'articles.manage', 'articles.publish', 'article_categories.manage'])->create();
$service = app(ArticlePublishing::class);
$category = ArticleCategory::findOrFail($service->execute($one, 'category.create', ['name' => 'Synthetic', 'slug' => 'synthetic', 'is_active' => true]));
function articleData(string $slug): array
{
    return ['slug' => $slug, 'category_id' => $GLOBALS['category']->id, 'title' => 'Synthetic title', 'summary' => 'Synthetic summary', 'content' => 'Synthetic paragraph'];
}
function articleFixture(string $slug): Article
{
    return Article::findOrFail($GLOBALS['service']->execute($GLOBALS['one'], 'create', articleData($slug)));
}
function articleCommand(User $actor, string $action, array $data, ?int $id = null): array
{
    return ['actor' => $actor->id, 'action' => $action, 'data' => $data, 'id' => $id];
}
function articleRace(string $label, array $first, array $second, array $expected): array
{
    $base = $GLOBALS['auditDirectory'].'/article-'.$label.'-'.bin2hex(random_bytes(3));
    $first += ['hold' => true, 'ready' => $base.'.ready', 'output' => $base.'-first.json'];
    $second += ['hold' => false, 'ready' => $base.'.ready', 'output' => $base.'-second.json'];
    file_put_contents($base.'-first-command.json', json_encode($first));
    file_put_contents($base.'-second-command.json', json_encode($second));
    $pipes = [];
    $processOne = proc_open([PHP_BINARY, __DIR__.'/worker.php', $base.'-first-command.json'], [0 => ['pipe', 'r'], 1 => ['file', $base.'-one.log', 'w'], 2 => ['file', $base.'-one-error.log', 'w']], $pipes);
    $deadline = microtime(true) + 20;
    while (! is_file($base.'.ready') && microtime(true) < $deadline) {
        usleep(10000);
    }
    auditCheck(is_file($base.'.ready'), 'first article worker reached protected write');
    $processTwo = proc_open([PHP_BINARY, __DIR__.'/worker.php', $base.'-second-command.json'], [0 => ['pipe', 'r'], 1 => ['file', $base.'-two.log', 'w'], 2 => ['file', $base.'-two-error.log', 'w']], $pipes);
    auditCheck(proc_close($processOne) === 0 && proc_close($processTwo) === 0, 'article workers completed');
    $a = json_decode(file_get_contents($first['output']), true, flags: JSON_THROW_ON_ERROR);
    $b = json_decode(file_get_contents($second['output']), true, flags: JSON_THROW_ON_ERROR);
    auditCheck([$a['status'], $b['status']] === $expected, $label.' expected outcomes');
    auditCheck($a['pid'] !== $b['pid'] && $a['connection_id'] !== $b['connection_id'] && $b['elapsed_ms'] > 500, 'distinct competing article connections actually waited');

    return ['scenario' => $label, 'first' => $a, 'second' => $b];
}

$reports = [];
$article = articleFixture('edits');
$data = articleData('ignored') + ['version' => $article->version];
$reports[] = articleRace('edit-edit', articleCommand($one, 'save', $data, $article->id), articleCommand($two, 'save', $data, $article->id), [200, 409]);
auditCheck(ArticleVersion::where('article_id', $article->id)->count() === 2, 'only one new draft');
$article = articleFixture('edit-publish');
$draft = $article->draft_version_id;
$reports[] = articleRace('edit-publish', articleCommand($one, 'save', articleData('ignored') + ['version' => $article->version], $article->id), articleCommand($two, 'publish', ['version' => $article->version, 'reviewed_version_id' => $draft, 'confirmed' => true], $article->id), [200, 409]);
auditCheck($article->fresh()->published_version_id === null, 'stale reviewed draft not published');
$article = articleFixture('publish-edit');
$draft = $article->draft_version_id;
$reports[] = articleRace('publish-edit', articleCommand($one, 'publish', ['version' => $article->version, 'reviewed_version_id' => $draft, 'confirmed' => true], $article->id), articleCommand($two, 'save', articleData('ignored') + ['version' => $article->version], $article->id), [200, 409]);
auditCheck($article->fresh()->published_version_id === $draft, 'reviewed published snapshot retained');
$article->refresh();
$old = $article->published_version_id;
$service->execute($one, 'save', articleData('ignored') + ['version' => $article->version], $article->id);
$article->refresh();
$draft = $article->draft_version_id;
$reports[] = articleRace('publish-unpublish', articleCommand($one, 'publish', ['version' => $article->version, 'reviewed_version_id' => $draft, 'confirmed' => true], $article->id), articleCommand($two, 'unpublish', ['version' => $article->version, 'reviewed_version_id' => $old, 'confirmed' => true], $article->id), [200, 409]);
auditCheck($article->fresh()->published_version_id === $draft, 'stale unpublish cannot undo new publication');
$reports[] = articleRace('slug-collision', articleCommand($one, 'create', articleData('unique-race')), articleCommand($two, 'create', articleData('unique-race')), [200, 409]);
auditCheck(Article::where('slug', 'unique-race')->count() === 1, 'one slug and no orphan create');
$category = ArticleCategory::findOrFail($service->execute($one, 'category.create', ['name' => 'Archive race', 'slug' => 'archive-race', 'is_active' => true]));
$article = articleFixture('category-publish');
$reports[] = articleRace('category-publish', articleCommand($one, 'category.update', ['name' => $category->name, 'slug' => $category->slug, 'is_active' => false, 'version' => $category->version], $category->id), articleCommand($two, 'publish', ['version' => $article->version, 'reviewed_version_id' => $article->draft_version_id, 'confirmed' => true], $article->id), [200, 409]);
auditCheck($article->fresh()->published_version_id === null && ! $category->fresh()->is_active, 'inactive category cannot race publication');
file_put_contents($auditDirectory.'/article-concurrency-report.json', json_encode($reports, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Passed '.count($reports)." actual competing article-connection scenarios\n";
