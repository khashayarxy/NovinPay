<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/pr13-activation-contract.php';
require_once __DIR__.'/pr13-schema-contract.php';
require_once is_file(__DIR__.'/pr13-rehearsal-v2.php') ? __DIR__.'/pr13-rehearsal-v2.php' : __DIR__.'/pr13-rehearsal.php';

use function NovinPay\Release\Pr13\need;
use function NovinPay\Release\privateRead;

if (realpath(($_SERVER['SCRIPT_FILENAME'] ?? '') ?: ($argv[0] ?? '')) !== __FILE__) {
    return;
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
$phase = 'identity';
try {
    need(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && posix_geteuid() === 1321 && count($argv) === 4, 'live_child_scope');
    need(! (bool) ini_get('opcache.enable_cli') && ! (bool) ini_get('opcache.file_cache_only') && ini_get('opcache.file_cache') === '', 'live_child_release_opcode_cache_must_be_off');
    $text = privateRead($argv[1]);
    need(hash_equals($argv[2], hash('sha256', $text)), 'manifest_pin');
    $m = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    $kit = '/home/novinpay/novinpay-release-pipeline-v1';
    need($kit.'/pr13-activation-72h-v3' === __DIR__, 'live_child_tool_directory');
    $journal = $kit.'/pipeline-pr13-activation-r3';
    $permit = json_decode(privateRead($journal.'/child-permit.json'), true, flags: JSON_THROW_ON_ERROR);
    need($permit['parentPid'] === posix_getppid() && $permit['manifest'] === $argv[2]
        && hash_equals($permit['ticketSha256'], hash('sha256', getenv('NOVINPAY_PR13_TICKET') ?: '')),
        'live_child_parent_permit');
    need(hash_equals(hash('sha256', NovinPay\Release\Pr13\cliFence()), hash_file('sha256', '/home/novinpay/novinpay/artisan'))
        && is_file('/home/novinpay/novinpay/storage/framework/down'), 'both_fences_required');
    $migration = $argv[3] === 'migrate';
    need($migration || $argv[3] === 'ready', 'live_child_action');
    $root = $migration ? $kit.'/pr13-activation-app-r3' : '/home/novinpay/novinpay';
    need(realpath($root) === $root && ! is_link($root), 'live_child_root');
    if ($migration) {
        need(is_file($journal.'/ddl.started.json') && ! file_exists($journal.'/ddl.complete.json'), 'ddl_single_use');
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->afterBootstrapping(LoadConfiguration::class, static function ($app) use ($root, $migration): void {
        $cfg = (new ConfigurationUrlParser)->parseConfiguration($app['config']['database.connections.mariadb']);
        need($app->basePath() === $root && $cfg['database'] === 'novinpay_live1' && $cfg['host'] === 'localhost'
            && $cfg['username'] === ($migration ? 'novinpay_mig1' : 'novinpay_app1') && $cfg['strict'] === true
            && $cfg['timezone'] === '+00:00' && $app['config']['app.env'] === 'production' && $app['config']['app.url'] === 'https://novinpaypos.com', 'before_provider_database_identity');
    });
    $app->make(Kernel::class)->bootstrap();
    $db = DB::connection()->getPdo();
    $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    need($db->query('SELECT DATABASE()')->fetchColumn() === 'novinpay_live1'
        && $db->query('SELECT CURRENT_USER()')->fetchColumn() === ($migration ? 'novinpay_mig1@localhost' : 'novinpay_app1@localhost'), 'live_connected_identity');
    $settings = $db->query('SELECT @@session.time_zone zone,@@session.explicit_defaults_for_timestamp ts,@@session.foreign_key_checks fk,@@session.check_constraint_checks ck')->fetch(PDO::FETCH_ASSOC);
    need($settings['zone'] === '+00:00' && (int) $settings['ts'] === 1 && (int) $settings['fk'] === 1 && (int) $settings['ck'] === 1, 'live_connection_integrity_settings');
    foreach (['payments.zibal.enabled', 'payments.zibal.live_requests', 'customer.sms_enabled', 'customer.notifications_enabled', 'postex.enabled', 'postex.independent_postage_verified'] as $flag) {
        need(config($flag) === false, 'live_providers_must_remain_disabled');
    }
    if (! $migration) {
        $phase = 'runtime_readiness';
        $db->exec('SET SESSION TRANSACTION READ ONLY');
        $expected = $m['candidateMigrations'];
        sort($expected);
        need($db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN) === $expected, 'ready_22_migrations');
        foreach (['support.panel', 'account.login', 'admin.login', 'admin.maintenance.index', 'admin.repairs.index', 'products.index'] as $name) {
            need(Route::has($name), 'ready_required_routes');
        }
        need(config('support_chat.enabled') === true, 'chat_enabled_configuration');
        // The production-only provisioner is deliberately a private release tool;
        // the application's staging/testing-only initialize command is unchanged.
        require __DIR__.'/pr13-chat-provision.php';
        pr13ProvisionChat($app, $journal);
        foreach (['config:cache', 'route:cache', 'view:cache'] as $command) {
            need(Artisan::call($command, ['--no-interaction' => true]) === 0, 'ready_cache_generation');
        }
        echo json_encode(['result' => 'PR13_RUNTIME_READINESS_PASS', 'migrations' => 22, 'databaseWrites' => false], JSON_THROW_ON_ERROR).PHP_EOL;
        exit;
    }
    $phase = 'current_business_backup_coverage';
    $baseline = $m['baselineMigrations'];
    sort($baseline);
    need($db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN) === $baseline, 'exact_baseline_before_ddl');
    $triggerRows = static function () use ($db): array {
        $rows = $db->query('SELECT TRIGGER_NAME name,EVENT_OBJECT_TABLE tableName,EVENT_MANIPULATION eventName,ACTION_TIMING timing,ACTION_STATEMENT body,DEFINER definer FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['bodySha256'] = hash('sha256', $row['body']);
            unset($row['body']);
        }

        return $rows;
    };
    pr13VerifyTriggerContracts($triggerRows());
    $restore = new PDO('mysql:host=localhost;dbname=novinpay_restore13r1;charset=utf8mb4', config('database.connections.mariadb.username'), config('database.connections.mariadb.password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true]);
    $restore->exec("SET SESSION time_zone='+00:00', explicit_defaults_for_timestamp=1");
    $restore->exec('SET SESSION TRANSACTION READ ONLY');
    need($restore->query('SELECT DATABASE()')->fetchColumn() === 'novinpay_restore13r1', 'retained_restore_identity');
    need((int) $restore->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 22, 'retained_restore_22');
    $tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    need(count($tables) === 39, 'original_table_inventory');
    $before = [];
    $ceiling = (int) $db->query('SELECT MAX(id) FROM migrations')->fetchColumn();
    foreach ($tables as $table) {
        $columns = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $columns->execute([$table]);
        $order = $db->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
        $order->execute([$table]);
        $cols = $columns->fetchAll(PDO::FETCH_COLUMN);
        $pk = $order->fetchAll(PDO::FETCH_COLUMN);
        $digest = pr13ProjectedRowsDigest($db, $table, $cols, $pk, $table === 'migrations' ? $ceiling : null);
        // Only disposable sessions/cache are excluded from backup freshness.
        // Jobs, customer/account, stock and financial records are NOT excluded.
        if (! in_array($table, ['sessions', 'cache', 'cache_locks'], true)) {
            need($digest === pr13ProjectedRowsDigest($restore, $table, $cols, $pk, $table === 'migrations' ? $ceiling : null), 'backup_business_state_changed_requires_new_generation');
        }
        $before[$table] = ['columns' => $cols, 'order' => $pk, 'digest' => $digest];
    }
    $keys = static fn () => $db->query('SELECT CONSTRAINT_NAME,TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
    $oldKeys = $keys();
    NovinPay\Release\writeNew($journal.'/original-data-contract.json', ['tables' => $before, 'foreignKeys' => $oldKeys, 'migrationCeiling' => $ceiling]);
    $phase = 'eight_live_migrations';
    $pending = array_values(array_diff($m['candidateMigrations'], $baseline));
    need(count($pending) === 8, 'exact_eight_pending');
    $paths = [];
    foreach ($pending as $name) {
        $file = 'database/migrations/'.$name.'.php';
        need(hash_equals($m['changedRuntimeFiles'][$file], hash_file('sha256', $root.'/'.$file)), 'live_migration_hash');
        $paths[] = $file;
    }
    need(Artisan::call('migrate', ['--path' => $paths, '--force' => true, '--no-interaction' => true]) === 0, 'live_migrate_failed_preserve_partial_ddl');
    $candidate = $m['candidateMigrations'];
    sort($candidate);
    need($db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN) === $candidate, 'final_22_migrations');
    pr13VerifyTriggerContracts($triggerRows(), true);
    $phase = 'preservation_after_ddl';
    foreach ($before as $table => $snapshot) {
        need(pr13ProjectedRowsDigest($db, $table, $snapshot['columns'], $snapshot['order'], $table === 'migrations' ? $ceiling : null) === $snapshot['digest'], 'live_original_rows_changed');
    }
    $newKeys = $keys();
    foreach ($oldKeys as $key) {
        need(in_array($key, $newKeys, true), 'live_original_foreign_key_changed');
    }
    echo json_encode(['result' => 'PR13_LIVE_MIGRATION_PRESERVATION_PASS', 'migrations' => 22, 'triggers' => 13, 'originalTablesPreserved' => 39, 'originalRowsPreserved' => true, 'originalForeignKeysPreserved' => true, 'baselineTriggersPreserved' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(NovinPay\Release\failureReport($error, $phase, $phase, true), JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
