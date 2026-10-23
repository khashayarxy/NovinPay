<?php

require dirname(__DIR__).'/Concurrency/bootstrap.php';

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Reconstructed from the owner's seven reported migrations; not a production dump.
$all = glob(base_path('database/migrations/*.php'));
sort($all);
$paths = array_map(fn ($path) => 'database/migrations/'.basename($path), array_slice($all, 0, 7));
auditCheck(str_ends_with($paths[6], 'renormalize_search_text.php'), 'Expected seven-migration baseline');
auditCheck(Artisan::call('migrate:fresh', ['--path' => $paths, '--force' => true]) === 0, 'Baseline installation succeeds');
User::factory()->create(['name' => 'Synthetic retained owner']);
Product::create(['name' => 'Synthetic retained product', 'slug' => 'synthetic-upgrade-product', 'category' => 'pos_terminal', 'condition' => 'used', 'is_published' => true]);
$before = [];
foreach (['users', 'products', 'migrations'] as $table) {
    $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
}
auditCheck(Artisan::call('migrate', ['--force' => true]) === 0, 'Additive upgrade succeeds');
foreach (['users', 'products'] as $table) {
    auditCheck($before[$table] == DB::table($table)->orderBy('id')->get()->toArray(), $table.' unchanged on upgrade');
}
auditCheck($before['migrations'] == DB::table('migrations')->orderBy('id')->limit(7)->get()->toArray(), 'Original migration records/batches unchanged');
auditCheck(DB::table('admin_accounts')->count() === 0, 'Migration grants no administrator');
auditCheck(Artisan::call('migrate:rollback', ['--force' => true]) === 0, 'Disposable new batch rollback succeeds');
foreach (['users', 'products', 'migrations'] as $table) {
    auditCheck($before[$table] == DB::table($table)->orderBy('id')->get()->toArray(), $table.' unchanged after disposable rollback');
}
auditCheck(Artisan::call('migrate', ['--force' => true]) === 0, 'Reapply succeeds');
auditCheck(DB::table('migrations')->count() === count($all), 'All migrations recorded after reapply');
echo json_encode(['driver' => $driver, 'server' => DB::selectOne('SELECT VERSION() AS v')->v, 'baseline' => 'owner-reported seven migrations (synthetic reconstruction)', 'checks' => 13], JSON_THROW_ON_ERROR).PHP_EOL;
