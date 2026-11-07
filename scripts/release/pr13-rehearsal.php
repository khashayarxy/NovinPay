<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function pr13ProjectedRowsDigest(PDO $db, string $table, array $columns, array $order, ?int $migrationCeiling = null): array
{
    foreach (array_merge([$table], $columns, $order) as $identifier) {
        if (! is_string($identifier) || preg_match('/\A[a-zA-Z0-9_]+\z/D', $identifier) !== 1) {
            throw new RuntimeException('projection_identifier');
        }
    }
    if ($columns === [] || $order === [] || array_diff($order, $columns) || ($migrationCeiling !== null && ($table !== 'migrations' || $migrationCeiling < 1))) {
        throw new RuntimeException('projection_contract');
    }
    $quote = static fn ($name) => '`'.$name.'`';
    $sql = 'SELECT '.implode(',', array_map($quote, $columns)).' FROM '.$quote($table);
    if ($migrationCeiling !== null) {
        $sql .= ' WHERE id <= '.(int) $migrationCeiling;
    }
    $sql .= ' ORDER BY '.implode(',', array_map($quote, $order));
    $hash = hash_init('sha256');
    $rows = 0;
    foreach ($db->query($sql) as $row) {
        $values = [];
        foreach ($columns as $column) {
            $values[] = $row[$column] === null ? null : (string) $row[$column];
        }
        hash_update($hash, serialize($values));
        $rows++;
    }

    return ['rows' => $rows, 'sha256' => hash_final($hash)];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/pr13-schema-contract.php';
$phase = 'invocation';
try {
    $need = static function (bool $ok, string $check): void {
        if (! $ok) {
            throw new RuntimeException($check);
        }
    };
    $need(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && posix_geteuid() === 1321 && count($argv) === 4, $phase);
    $text = NovinPay\Release\privateRead($argv[1]);
    $need(hash_equals($argv[2], hash('sha256', $text)), 'manifest_pin');
    $m = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    $root = $argv[3];
    $need($root === '/home/novinpay/novinpay-release-pipeline-v1/pr13-rehearsal-app' && realpath($root) === $root && ! is_link($root)
        && $m['backup']['restoreDatabase'] === 'novinpay_restore13r1', 'isolated_scope');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $phase = 'isolated_connection';
    $need(base_path() === $root && config('app.env') === 'testing' && ! config('app.debug')
        && config('database.default') === 'mariadb' && config('cache.default') === 'file' && config('session.driver') === 'file', $phase);
    $db = DB::connection()->getPdo();
    $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    $identity = $db->query('SELECT DATABASE() db,CURRENT_USER() account,VERSION() version,@@session.foreign_key_checks fk,@@session.check_constraint_checks ck,@@session.explicit_defaults_for_timestamp ts')->fetch(PDO::FETCH_ASSOC);
    $need($identity['db'] === 'novinpay_restore13r1' && $identity['account'] === 'novinpay_mig1@localhost'
        && str_starts_with($identity['version'], '10.6.28') && (int) $identity['fk'] === 1 && (int) $identity['ck'] === 1 && (int) $identity['ts'] === 1, $phase);
    foreach (['payments.zibal.enabled', 'payments.zibal.live_requests', 'customer.sms_enabled', 'customer.notifications_enabled', 'postex.enabled', 'postex.independent_postage_verified'] as $flag) {
        $need(config($flag) === false, 'providers_disabled');
    }
    $actual = $db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    $baseline = $m['baselineMigrations'];
    sort($baseline);
    $need($actual === $baseline && count($actual) === 14, 'baseline_migrations');
    $triggerRows = static function () use ($db): array {
        $rows = $db->query('SELECT TRIGGER_NAME name,EVENT_OBJECT_TABLE tableName,EVENT_MANIPULATION eventName,ACTION_TIMING timing,ACTION_STATEMENT body,DEFINER definer FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['bodySha256'] = hash('sha256', $row['body']);
            unset($row['body']);
        }

        return $rows;
    };
    pr13VerifyTriggerContracts($triggerRows());
    $phase = 'old_column_snapshot';
    $tables = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\'BASE TABLE\' ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    $before = [];
    $ceiling = (int) $db->query('SELECT MAX(id) FROM migrations')->fetchColumn();
    foreach ($tables as $table) {
        $columns = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $columns->execute([$table]);
        $order = $db->prepare('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=\'PRIMARY\' ORDER BY SEQ_IN_INDEX');
        $order->execute([$table]);
        $cols = $columns->fetchAll(PDO::FETCH_COLUMN);
        $pk = $order->fetchAll(PDO::FETCH_COLUMN);
        $before[$table] = ['columns' => $cols, 'order' => $pk, 'digest' => pr13ProjectedRowsDigest($db, $table, $cols, $pk, $table === 'migrations' ? $ceiling : null)];
    }
    $foreignKeys = static fn () => $db->query('SELECT CONSTRAINT_NAME,TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
    $oldKeys = $foreignKeys();
    $phase = 'reviewed_eight_migrations';
    $pending = array_values(array_diff($m['candidateMigrations'], $baseline));
    $need(count($pending) === 8, 'pending_migration_contract');
    $paths = [];
    foreach ($pending as $name) {
        $path = 'database/migrations/'.$name.'.php';
        $need(isset($m['changedRuntimeFiles'][$path]) && hash_equals($m['changedRuntimeFiles'][$path], hash_file('sha256', $root.'/'.$path)), 'migration_file_hash');
        $paths[] = $path;
    }
    $need(Artisan::call('migrate', ['--path' => $paths, '--force' => true, '--no-interaction' => true]) === 0, $phase);
    $candidate = $m['candidateMigrations'];
    sort($candidate);
    $need($db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN) === $candidate && count($candidate) === 22, 'candidate_migrations');
    $contracts = pr13VerifyTriggerContracts($triggerRows(), true);
    $phase = 'preserved_data_and_foreign_keys';
    foreach ($before as $table => $snapshot) {
        $need(pr13ProjectedRowsDigest($db, $table, $snapshot['columns'], $snapshot['order'], $table === 'migrations' ? $ceiling : null) === $snapshot['digest'], 'original_rows_preserved');
    }
    $keys = $foreignKeys();
    foreach ($oldKeys as $key) {
        $need(in_array($key, $keys, true), 'original_foreign_key_preserved');
    }
    echo json_encode(['result' => 'PR13_ISOLATED_MIGRATION_REHEARSAL_PASS', 'candidate' => $m['candidate'], 'restoreDatabase' => 'novinpay_restore13r1',
        'migrations' => 22, 'triggers' => 13, 'baselineTriggersPreserved' => true, 'originalRowsPreserved' => true, 'originalForeignKeysPreserved' => true,
        'tablesVerified' => count($before), 'triggerContracts' => $contracts, 'productionModified' => false, 'rollbackExecuted' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['result' => 'PR13_REHEARSAL_STOP', 'phase' => $phase, 'category' => $error instanceof PDOException ? 'database_details_withheld' : 'contract_or_runtime',
        'check' => $error instanceof RuntimeException && preg_match('/\A[a-z0-9_]+\z/', $error->getMessage()) ? $error->getMessage() : 'details_withheld',
        'productionModified' => false, 'retrySafe' => false]).PHP_EOL;
    exit(1);
}
