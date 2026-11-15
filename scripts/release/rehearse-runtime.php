<?php

declare(strict_types=1);
use NovinPay\Release\GateFailure;

// Local-only real filesystem rehearsal. No hosting API, HTTP server, provider or
// real database access. The exact transaction is shared with runtime-host.php.
require_once __DIR__.'/runtime-release.php';

use function NovinPay\Release\command;
use function NovinPay\Release\privateRead;
use function NovinPay\Release\requireGate;
use function NovinPay\Release\runtimeActivate;
use function NovinPay\Release\runtimeGit;
use function NovinPay\Release\runtimeInventory;
use function NovinPay\Release\runtimeVerify;
use function NovinPay\Release\runtimeWrite;
use function NovinPay\Release\writeNew;

try {
    requireGate(PHP_SAPI === 'cli' && count($argv) === 2, 'rehearsal_cli', 'private local configuration', [], 'Use the local rehearsal procedure.');
    $c = json_decode(privateRead($argv[1]), true, 32, JSON_THROW_ON_ERROR);
    $root = str_replace('\\', '/', $c['workspace']);
    $allowed = str_replace('\\', '/', getenv('LOCALAPPDATA') ?: (getenv('RUNNER_TEMP') ?: sys_get_temp_dir()));
    requireGate(str_starts_with($root, $allowed.'/NovinPay-SecureAccess/releases/') && ! file_exists($root)
        && ! str_contains($root, '..') && ! str_contains($root, '/home/novinpay/'), 'rehearsal_isolation', 'new private local disposable directory', [], 'Never target an existing application or database.');
    mkdir($root, 0700, true);
    $m = json_decode(privateRead($c['manifest']), true, 512, JSON_THROW_ON_ERROR);
    $m['_sha256'] = hash_file('sha256', $c['manifest']);
    $app = $root.'/app';
    $kit = $root.'/kit';
    mkdir($kit, 0700);
    runtimeGit($c['repository'], ['clone', '--local', '--no-checkout', '--no-hardlinks', $c['repository'], $app]);
    runtimeGit($app, ['config', 'core.autocrlf', 'false']);
    runtimeGit($app, ['checkout', '--detach', $m['baseline']]);
    foreach (runtimeInventory($c['baselineVendor']) as $path => $hash) {
        runtimeWrite($app.'/vendor/'.$path, file_get_contents($c['baselineVendor'].'/'.$path));
    }
    foreach ($m['archives'] as $a) {
        requireGate(copy($c['package'].'/'.$a['file'], $kit.'/'.$a['file']), 'rehearsal_package_copy', 'exact private artifacts copied', [], 'Inspect local filesystem.');
    }
    foreach (['storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache', 'storage/logs', 'bootstrap/cache', 'public/build'] as $dir) {
        if (! is_dir($app.'/'.$dir)) {
            mkdir($app.'/'.$dir, $dir === 'public/build' ? 0755 : 0700, true);
        }
    }
    $link = $root.'/public_html';
    requireGate(symlink($app.'/public', $link), 'rehearsal_public_symlink', 'real local symlink', [], 'Report missing local symlink capability; do not simulate a passing check.');
    // Synthetic private environment; never copied from owner/production .env.
    $key = 'base64:'.base64_encode(random_bytes(32));
    // Production already selects IPPanel; do not model an unauthorized provider
    // switch from the retired default while testing strict config preservation.
    $env = "APP_ENV=testing\nAPP_DEBUG=false\nAPP_URL=https://rehearsal.invalid\nSMS_PROVIDER=ippanel\nAPP_KEY=".$key."\nDB_CONNECTION=sqlite\nDB_DATABASE=".$root."/business.sqlite\nSESSION_DRIVER=array\nCACHE_STORE=array\nMAIL_MAILER=array\nQUEUE_CONNECTION=sync\n";
    runtimeWrite($app.'/.env', $env);
    runtimeWrite($app.'/public/.htaccess', file_get_contents($app.'/public/.htaccess')."\n# synthetic hosting-managed handler: preserve exactly\n");
    runtimeWrite($app.'/storage/app/private/support-chat/installation.env', "SYNTHETIC_REHEARSAL=true\n");
    runtimeWrite($app.'/storage/app/private/persistent-sentinel', random_bytes(32));
    runtimeWrite($app.'/storage/framework/site-maintenance/state.json', '{"synthetic":true}');
    runtimeWrite($app.'/storage/framework/site-maintenance/audit.jsonl', "{\"synthetic\":true}\n");
    $db = new PDO('sqlite:'.$root.'/business.sqlite');
    $db->exec('CREATE TABLE migrations (migration TEXT PRIMARY KEY); CREATE TABLE preservation (id INTEGER PRIMARY KEY, value TEXT); INSERT INTO preservation VALUES (1,\'synthetic unchanged record\')');
    $insert = $db->prepare('INSERT INTO migrations VALUES (?)');
    foreach ($m['migrations'] as $name) {
        $insert->execute([$name]);
    }
    $databaseHash = hash_file('sha256', $root.'/business.sqlite');
    $environment = array_merge(getenv(), ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://rehearsal.invalid', 'APP_KEY' => $key,
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $root.'/business.sqlite', 'DB_URL' => 'sqlite:///'.$root.'/business.sqlite',
        'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'APP_MAINTENANCE_DRIVER' => 'file', 'SMS_PROVIDER' => 'ippanel',
        'APP_CONFIG_CACHE' => $app.'/bootstrap/cache/config.php', 'APP_ROUTES_CACHE' => $app.'/bootstrap/cache/routes-v7.php', 'APP_PACKAGES_CACHE' => $app.'/bootstrap/cache/packages.php',
        'APP_SERVICES_CACHE' => $app.'/bootstrap/cache/services.php', 'APP_EVENTS_CACHE' => $app.'/bootstrap/cache/events.php']);
    $artisan = function (array $args) use ($app, $environment): void {
        $r = command(array_merge([PHP_BINARY, '-d', 'session.gc_divisor=100', $app.'/artisan'], $args, ['--no-interaction']), $app, 'rehearsal_artisan', 180, $environment);
        requireGate($r['exit'] === 0, 'rehearsal_artisan', 'real isolated Laravel command succeeds', ['command' => $args[0]], 'Inspect private rehearsal output without exposing environment values.');
    };
    $artisan(['config:cache']);
    $artisan(['route:cache']);
    $baselineView = 'storage/framework/views/'.str_repeat('a', 32).'.php';
    runtimeWrite($app.'/'.$baselineView, '<?php // private baseline compiled view', 0600);
    runtimeWrite($app.'/public/build/manifest.json', '{"old-entry":{"file":"assets/old-hash.js"}}');
    runtimeWrite($app.'/public/build/assets/old-hash.js', '/* retained open-tab asset */', 0644, 0755);
    runtimeWrite($app.'/public/build/support-chat/manifest.json', '{"version":"synthetic-baseline"}', 0644, 0755);
    // Hosted hashes are intentionally NOT reused for synthetic local configuration.
    $m['baselineFiles'] = [];
    foreach (['public/build/manifest.json', 'public/build/support-chat/manifest.json', 'bootstrap/cache/routes-v7.php'] as $p) {
        $m['baselineFiles'][$p] = hash_file('sha256', $app.'/'.$p);
    }
    $m['protectedFiles'] = [];
    foreach (['.env', 'public/.htaccess', 'bootstrap/cache/config.php', 'storage/app/private/support-chat/installation.env', 'storage/app/private/persistent-sentinel', 'storage/framework/site-maintenance/state.json', 'storage/framework/site-maintenance/audit.jsonl'] as $p) {
        $m['protectedFiles'][$p] = hash_file('sha256', $app.'/'.$p);
    }
    foreach (['state.json', 'audit.jsonl'] as $file) {
        $m['maintenanceHashes'][$file] = $m['protectedFiles']['storage/framework/site-maintenance/'.$file];
    }
    $baselineVendor = runtimeInventory($app.'/vendor');
    $hooks = [
        'preflight' => function () use ($db, $m): void {
            requireGate($db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN) === $m['migrations'], 'rehearsal_schema', 'unchanged 24 migration rows', [], 'No schema operations permitted.');
        },
        // cPanel quota, MariaDB trigger metadata and LiteSpeed OPcache are explicit
        // adapter simulations; they are NOT reported as hosted verification.
        'quota' => fn () => NovinPay\Release\runtimeInodes(['underInodeQuota' => true, 'inodeLimit' => 1000000, 'inodesRemaining' => 900000], 30000),
        'down' => fn () => $artisan(['down', '--retry=60']),
        'cache' => function () use ($artisan, $c, $m): void {
            if (NovinPay\Release\runtimeNeutral($m)) {
                $artisan(['config:cache']);
            }
            $artisan(['route:cache']);
            $artisan(['view:cache']);
            if (($c['forceFailure'] ?? false) === true) {
                throw new RuntimeException('synthetic_forced_failure');
            }
        },
        'web' => function () use ($app, $c): void {
            $failure = $c['forceFailure'] ?? false;
            $path = match ($failure) {
                'foreign-asset' => 'public/build/manifest.json',
                'foreign-cache' => 'bootstrap/cache/routes-v7.php',
                'foreign-view' => 'storage/framework/views/'.basename(glob($app.'/storage/framework/views/*.php')[0]),
                default => null,
            };
            if ($path !== null) {
                runtimeWrite($app.'/'.$path, 'unrelated-concurrent-writer');
                throw new RuntimeException('synthetic_foreign_write');
            }
        },
        'ready' => function () use ($app): void {
            $r = command([PHP_BINARY, '-r', 'require "vendor/autoload.php"; echo Illuminate\\Foundation\\Application::VERSION;'], $app, 'rehearsal_framework');
            requireGate($r['exit'] === 0 && $r['stdout'] === '13.40.0', 'rehearsal_candidate_vendor', 'real new framework autoloads', [], 'Reject mixed dependencies.');
        },
        'baselineReady' => function () use ($app, $baselineVendor, $m): void {
            runtimeVerify($app.'/vendor', $baselineVendor, true);
            $r = command([PHP_BINARY, '-r', 'require "vendor/autoload.php"; echo Illuminate\\Foundation\\Application::VERSION;'], $app, 'rehearsal_baseline_framework');
            requireGate($r['exit'] === 0 && $r['stdout'] === NovinPay\Release\runtimeFramework($m, true), 'rehearsal_baseline_vendor', 'real baseline framework restored', [], 'Keep maintenance on uncertainty.');
        },
        'up' => fn () => $artisan(['up']),
        'smoke' => function () use ($app): void {
            requireGate(! is_file($app.'/storage/framework/down'), 'rehearsal_online', 'maintenance off', [], 'Inspect the fixture.');
        },
    ];
    $negativeChecks = [];
    umask(0077);
    $heldLock = fopen($kit.'/.operation.lock', 'c+b');
    requireGate(flock($heldLock, LOCK_EX | LOCK_NB), 'rehearsal_lock_setup', 'fixture owns operation lock', [], 'Inspect local locking support.');
    try {
        runtimeActivate($app, $kit, $link, $m, $hooks);
        throw new RuntimeException('overlapping_operation_accepted');
    } catch (GateFailure $e) {
        requireGate($e->check === 'runtime_operation_locked', 'rehearsal_lock_rejection', 'second operation rejected by real flock', [], 'Preserve exclusive locking.');
        $negativeChecks['concurrent-operation'] = $e->check;
    } finally {
        flock($heldLock, LOCK_UN);
        fclose($heldLock);
    }
    if (! ($c['forceFailure'] ?? false)) {
        foreach (['corrupt', 'vendor-changed', 'missing-chunk', 'migration-change', 'wrong-candidate', 'stale-baseline', 'quota', 'interrupted'] as $scenario) {
            $bad = $m;
            $bad['releaseName'] .= '-'.$scenario;
            $badHooks = $hooks;
            if (in_array($scenario, ['corrupt', 'vendor-changed', 'missing-chunk', 'migration-change'], true)) {
                $kind = match ($scenario) {
                    'missing-chunk' => 'assets', 'migration-change' => 'source', default => 'vendor'
                };
                $old = $m['archives'][$kind]['file'];
                $file = $scenario.'.zip';
                copy($kit.'/'.$old, $kit.'/'.$file);
                $bad['archives'][$kind]['file'] = $file;
                if ($scenario === 'corrupt') {
                    file_put_contents($kit.'/'.$file, 'corrupt', FILE_APPEND);
                } else {
                    $z = new ZipArchive;
                    $z->open($kit.'/'.$file);
                    if ($scenario === 'vendor-changed') {
                        $z->addFromString('vendor/autoload.php', '<?php /* altered */');
                    } elseif ($scenario === 'migration-change') {
                        $path = 'database/migrations/'.$m['migrations'][0].'.php';
                        $bytes = $z->getFromName($path)."\n// synthetic unauthorized migration edit\n";
                        $z->addFromString($path, $bytes);
                        $bad['archives']['source']['files'][$path] = hash('sha256', $bytes);
                    } else {
                        // Select a real hashed JS chunk from the pinned complete inventory.
                        $path = array_values(array_filter(array_keys($bad['archives']['assets']['files']), fn ($p) => str_starts_with($p, 'assets/') && str_ends_with($p, '.js')))[0];
                        $z->deleteName($path);
                        unset($bad['archives']['assets']['files'][$path]);
                    }
                    $z->close();
                    $bad['archives'][$kind]['sha256'] = hash_file('sha256', $kit.'/'.$file);
                }
            } elseif ($scenario === 'wrong-candidate') {
                $bad['target'] = str_repeat('f', 40);
            } elseif ($scenario === 'stale-baseline') {
                runtimeGit($app, ['switch', '--detach', $m['target']]);
            } elseif ($scenario === 'quota') {
                $badHooks['quota'] = fn () => NovinPay\Release\runtimeInodes(['underInodeQuota' => true, 'inodeLimit' => 100000, 'inodesRemaining' => 1], 30000);
            } else {
                mkdir($kit.'/'.$bad['releaseName'].'-attempt', 0700);
                writeNew($kit.'/'.$bad['releaseName'].'-attempt/identity.json', ['interrupted' => true]);
            }
            try {
                runtimeActivate($app, $kit, $link, $bad, $badHooks);
                throw new RuntimeException('negative_scenario_was_accepted');
            } catch (GateFailure $e) {
                $expected = ['corrupt' => 'runtime_archive_hash', 'vendor-changed' => 'runtime_archive_file_hash', 'missing-chunk' => 'runtime_asset_dependency',
                    'migration-change' => 'runtime_source_blob', 'wrong-candidate' => NovinPay\Release\runtimeNeutral($m) ? 'runtime_neutral_identity' : 'runtime_git', 'stale-baseline' => 'runtime_baseline',
                    'quota' => 'runtime_inode_headroom', 'interrupted' => 'runtime_attempt_exists'];
                requireGate($e->check === $expected[$scenario], 'rehearsal_wrong_rejection', 'specific scenario failed at its intended guard', ['scenario' => $scenario, 'observedCheck' => $e->check], 'Fix the actual mismatch; do not accept an unrelated STOP as coverage.');
                $negativeChecks[$scenario] = $e->check;
            } finally {
                if ($scenario === 'stale-baseline') {
                    runtimeGit($app, ['switch', '--detach', $m['baseline']]);
                }
            }
            requireGate(! is_file($app.'/storage/framework/down') && trim(runtimeGit($app, ['rev-parse', 'HEAD'])) === $m['baseline'], 'rehearsal_negative_preservation', 'negative test did not activate', [], 'Inspect the fixture.');
        }
    }
    try {
        $receipt = runtimeActivate($app, $kit, $link, $m, $hooks);
        requireGate(! ($c['forceFailure'] ?? false), 'rehearsal_forced_failure_missing', 'forced failure occurs', [], 'Inspect test coverage.');
    } catch (Throwable $e) {
        if (! ($c['forceFailure'] ?? false)) {
            throw $e;
        }
        $journal = $kit.'/'.$m['releaseName'].'-attempt';
        if (is_string($c['forceFailure'] ?? null)) {
            $check = match ($c['forceFailure']) {
                'foreign-asset' => 'runtime_rollback_asset_changed',
                'foreign-cache' => 'runtime_rollback_cache_changed',
                'foreign-view' => 'runtime_rollback_view_ownership',
            };
            $stop = json_decode(privateRead($journal.'/rollback-stop.json'), true, 16, JSON_THROW_ON_ERROR);
            requireGate($stop['check'] === $check && is_file($app.'/storage/framework/down')
                && ! is_file($journal.'/rollback-complete.json') && hash_file('sha256', $root.'/business.sqlite') === $databaseHash,
                'rehearsal_foreign_preservation', 'unexpected data preserved; maintenance and journal retained; no SQL restore', [], 'Inspect rollback ownership guards.');
            $foreign = array_filter(array_merge(runtimeInventory($app.'/bootstrap/cache'), runtimeInventory($app.'/storage/framework/views'),
                ['asset-manifest' => hash_file('sha256', $app.'/public/build/manifest.json')]), fn ($hash) => $hash === hash('sha256', 'unrelated-concurrent-writer'));
            requireGate(count($foreign) === 1, 'rehearsal_foreign_not_overwritten', 'foreign file remains untouched', [], 'Never overwrite unrelated changes.');
            runtimeVerify($app, array_intersect_key($m['protectedFiles'], array_flip(['.env', 'storage/app/private/persistent-sentinel'])));
            echo json_encode(['result' => 'RUNTIME_PROTECTED_STOP_REHEARSAL_PASS', 'scenario' => $c['forceFailure'], 'check' => $check, 'databaseRestored' => false], JSON_THROW_ON_ERROR).PHP_EOL;
            exit(0);
        }
        if (is_file($journal.'/rollback-stop.json')) {
            echo json_encode(['result' => 'RUNTIME_REHEARSAL_ROLLBACK_DETAILS', 'details' => json_decode(privateRead($journal.'/rollback-stop.json'), true)], JSON_THROW_ON_ERROR).PHP_EOL;
        }
        requireGate(is_file($journal.'/rollback-complete.json') && ! is_file($app.'/storage/framework/down')
            && trim(runtimeGit($app, ['rev-parse', 'HEAD'])) === $m['baseline'], 'rehearsal_rollback', 'actual matched baseline recovered', [], 'Inspect the preserved failed rehearsal.');
        runtimeVerify($app.'/vendor', $baselineVendor, true);
        $receipt = ['result' => 'RUNTIME_FORCED_ROLLBACK_REHEARSAL_PASS'];
    }
    if (NovinPay\Release\runtimeNeutral($m) && ! ($c['forceFailure'] ?? false)) {
        NovinPay\Release\runtimeProtected($app, $m, $kit.'/'.$m['releaseName'].'-attempt/rollback-cache/config.php');
        NovinPay\Release\runtimeDeletedSource($app, $m);
    } else {
        runtimeVerify($app, $m['protectedFiles']);
    }
    if (NovinPay\Release\runtimeNeutral($m)) {
        $rollback = (bool) ($c['forceFailure'] ?? false);
        foreach (NovinPay\Release\runtimeSourceDelta($app, $m) as $path => $status) {
            if ($status === 'A' || $status === 'D') {
                requireGate(is_file($app.'/'.$path) === ($rollback ? $status === 'D' : $status === 'A'),
                    'rehearsal_source_addition_deletion', 'tracked additions/deletions reversed exactly on rollback', [], 'Inspect source rollback.');
            }
        }
        if ($rollback) {
            runtimeVerify($app, $m['baselineFiles']);
            requireGate(file_get_contents($app.'/'.$baselineView) === '<?php // private baseline compiled view'
                && (PHP_OS_FAMILY === 'Windows' || (fileperms($app.'/'.$baselineView) & 0777) === 0600),
                'rehearsal_private_view_mode', 'original private compiled-view bytes and mode restored', [], 'Do not broaden private view permissions.');
        }
    }
    requireGate(hash_file('sha256', $root.'/business.sqlite') === $databaseHash && is_file($app.'/public/build/assets/old-hash.js')
        && str_replace('\\', '/', readlink($link)) === $app.'/public', 'rehearsal_preservation', 'database, old assets, configuration and public link unchanged', [], 'Inspect the preserved fixture.');
    $before = runtimeInventory($kit.'/'.$m['releaseName'].'-attempt');
    try {
        runtimeActivate($app, $kit, $link, $m, $hooks);
        throw new RuntimeException('replay_was_accepted');
    } catch (GateFailure $e) {
        requireGate($e->check === 'runtime_attempt_exists' && runtimeInventory($kit.'/'.$m['releaseName'].'-attempt') === $before,
            'rehearsal_replay', 'attempt replay rejected without evidence changes', [], 'Preserve the journal.');
    }
    $receipt['hostingConditionsSimulated'] = ['quota', 'MariaDB trigger metadata', 'HTTPS', 'web OPcache'];
    $receipt['rejectedScenarios'] = $negativeChecks;
    $receipt['realChecks'] = ['baseline and candidate Git/source/vendor', 'all artifact hashes', 'Laravel caches and maintenance', 'unchanged synthetic SQLite', 'public symlink', 'protected configuration', 'old hashed asset', 'non-replayable journal'];
    writeNew($root.'/rehearsal-receipt.json', $receipt);
    echo json_encode($receipt, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['result' => 'RUNTIME_REHEARSAL_STOP', 'check' => $e instanceof GateFailure ? $e->check : 'operation_failure', 'retrySafe' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
