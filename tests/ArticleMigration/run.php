<?php

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Product;
use App\Models\User;
use App\Services\ArticlePublishing;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, 'Article audit failed: '.get_class($exception).PHP_EOL);
    exit(1);
});

$driver = DB::getDriverName();
$database = str_replace('\\', '/', DB::connection()->getDatabaseName());
$artifacts = str_replace('\\', '/', getenv('NOVINPAY_COMMERCE_ARTIFACTS') ?: '');
$disposable = $driver === 'sqlite' ? ($database === ':memory:' || ($artifacts !== '' && str_starts_with($database, $artifacts.'/disposable-'))) : ($driver === 'mysql' && preg_match('/\Anovinpay_phase6c(?:_[a-z0-9_]+)?\z/', $database) && config('database.connections.mysql.host') === '127.0.0.1' && (int) config('database.connections.mysql.port') > 1024 && (int) config('database.connections.mysql.port') !== 3306);
if (! $app->environment('testing') || $app->configurationIsCached() || ! $disposable) {
    throw new RuntimeException('Refusing non-disposable migration target');
}
$paths = [];
foreach (glob(base_path('database/migrations/*.php')) as $path) {
    if (! str_ends_with($path, '000004_create_article_tables.php')) {
        $paths[] = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }
}
Artisan::call('migrate:fresh', ['--path' => $paths, '--force' => true]);
$owner = User::factory()->superAdmin()->create();
Product::create(['name' => 'Synthetic retained product', 'slug' => 'synthetic-retained', 'category' => 'pos_terminal', 'condition' => 'new', 'is_published' => false]);
$tables = array_filter(Schema::getTableListing(), fn ($table) => ! in_array(preg_replace('/^.*\./', '', $table), ['migrations', 'sqlite_sequence'], true));
$before = [];
foreach ($tables as $table) {
    $before[$table] = hash('sha256', DB::table($table)->get()->toJson());
}
$migrations = DB::table('migrations')->orderBy('id')->get()->toJson();
$checks = [];
function verifyArticleMigration(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException($label);
    } $GLOBALS['checks'][] = $label;
}
Artisan::call('migrate', ['--force' => true]);
foreach ($before as $table => $hash) {
    verifyArticleMigration(hash('sha256', DB::table($table)->get()->toJson()) === $hash, $table.' preserved by additive upgrade');
}
$service = app(ArticlePublishing::class);
$category = $service->execute($owner, 'category.create', ['name' => 'Synthetic category', 'slug' => 'synthetic', 'is_active' => true]);
$article = Article::findOrFail($service->execute($owner, 'create', ['slug' => 'synthetic', 'title' => 'Synthetic', 'summary' => 'Synthetic summary', 'content' => 'Synthetic body', 'category_id' => $category]));
$service->execute($owner, 'publish', ['version' => $article->version, 'reviewed_version_id' => $article->draft_version_id, 'confirmed' => true], $article->id);
$after = [];
foreach ($tables as $table) {
    $after[$table] = hash('sha256', DB::table($table)->get()->toJson());
}
Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
verifyArticleMigration(! Schema::hasTable('articles') && ! Schema::hasTable('article_versions') && ! Schema::hasTable('article_categories'), 'content tables removed only on explicit disposable rollback');
foreach ($after as $table => $hash) {
    verifyArticleMigration(hash('sha256', DB::table($table)->get()->toJson()) === $hash, $table.' preserved by rollback');
}
verifyArticleMigration(DB::table('migrations')->orderBy('id')->get()->toJson() === $migrations, 'prior migration records preserved');
Artisan::call('migrate', ['--force' => true]);
verifyArticleMigration(Article::count() === 0 && ArticleCategory::count() === 0, 'reapply is empty, no invented history');
echo json_encode(['driver' => $driver, 'passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
