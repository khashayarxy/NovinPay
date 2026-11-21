<?php

declare(strict_types=1);
use NovinPay\Release\GateFailure;

require_once __DIR__.'/lib.php';

// Read-only inventory only. This entry point never backs up, migrates or activates.
function pr13MigrationDelta(array $actual, array $baseline, array $candidate): array
{
    sort($actual);
    sort($baseline);
    sort($candidate);
    if ($actual !== $baseline || count($baseline) !== 14 || count($candidate) !== 22
        || count(array_unique($candidate)) !== 22 || array_diff($baseline, $candidate)) {
        throw new RuntimeException('migration_history_mismatch');
    }

    return array_values(array_diff($candidate, $baseline));
}

function pr13MigratorSchemaPrivilegesPresent(array $rows, string $database, string $account): bool
{
    if ($database !== 'novinpay_live1' || $account !== 'novinpay_mig1@localhost') {
        return false;
    }

    return \NovinPay\Release\databasePrivilegesPresent($rows, $database, $account,
        ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'INDEX', 'TRIGGER']);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once __DIR__.'/runtime.php';
$phase = 'identity';
$check = 'invocation';
$db = null;
try {
    $need = static function (bool $ok, string $name): void {
        if (! $ok) {
            throw new GateFailure($name, 'verified read-only production inventory', [], 'Preserve evidence and inspect the named assertion.');
        }
    };
    $need(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && function_exists('posix_geteuid') && posix_geteuid() === 1321, $check);
    $need(count($argv) === 3 && is_file($argv[1]) && ! is_link($argv[1])
        && preg_match('/\A[a-f0-9]{64}\z/D', $argv[2]) === 1 && hash_equals($argv[2], hash_file('sha256', $argv[1])), 'manifest_integrity');
    $m = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    foreach ($m['tools'] as $file => $hash) {
        $need(preg_match('/\A[a-z0-9-]+\.php\z/D', $file) === 1 && ! is_link(__DIR__.'/'.$file)
            && hash_equals($hash, hash_file('sha256', __DIR__.'/'.$file)), 'tool_integrity');
    }
    define('APP', '/home/novinpay/novinpay');
    define('SOURCE_DB', 'novinpay_live1');
    $check = 'application_identity';
    $need($m['baseline'] === '1f39bb476fd31880600564e58599c4488622c4c0' && realpath(APP) === APP
        && is_link('/home/novinpay/public_html') && readlink('/home/novinpay/public_html') === APP.'/public', $check);
    $git = static function (array $args) use ($need): string {
        $result = \NovinPay\Release\command(array_merge(['git'], $args), APP, 'readonly_git', 30);
        $need($result['exit'] === 0, 'readonly_git');

        return trim($result['stdout']);
    };
    $need($git(['rev-parse', 'HEAD']) === $m['baseline'], 'production_head');
    // Do not trim porcelain's leading status column.
    $status = \NovinPay\Release\command(['git', 'status', '--porcelain=v1', '--untracked-files=all'], APP, 'readonly_git_status', 30);
    $need($status['exit'] === 0, 'readonly_git_status');
    $entries = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $status['stdout'])), fn ($line) => $line !== ''));
    sort($entries);
    $expected = $m['preservedStatus'];
    sort($expected);
    $need($entries === $expected && $git(['diff', '--cached', '--name-only']) === '', 'production_worktree');
    $need(! file_exists(APP.'/storage/framework/down') && ! file_exists(APP.'/storage/framework/maintenance.php'), 'maintenance_off');
    $phase = 'database_readonly';
    $check = 'runtime_environment';
    $envBefore = hash_file('sha256', APP.'/.env');
    require_once __DIR__.'/production-environment.php';
    $runtime = verifiedProductionEnvironment();
    unset($runtime);
    $check = 'migrator_observer';
    $credentialsPath = '/home/novinpay/novinpay-releases/155fc9f30feb5b9d0c41445b33f1118d5e07da8e/.stage2-credentials.json';
    $need(is_file($credentialsPath) && ! is_link($credentialsPath) && fileowner($credentialsPath) === 1321
        && (fileperms($credentialsPath) & 0777) === 0600, $check);
    $credential = json_decode(file_get_contents($credentialsPath), true, flags: JSON_THROW_ON_ERROR)['migrator'];
    $need($credential['user'] === 'novinpay_mig1', $check);
    $db = new PDO('mysql:host=localhost;port=3306;dbname=novinpay_live1;charset=utf8mb4', $credential['user'], $credential['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    unset($credential);
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    $need($db->query('SELECT DATABASE()')->fetchColumn() === SOURCE_DB
        && str_starts_with($db->query('SELECT CURRENT_USER()')->fetchColumn(), 'novinpay_mig1@'), $check);
    $role = $db->query('SELECT CURRENT_ROLE()')->fetchColumn();
    $need($role === null || $role === 'NONE', 'active_role');
    $check = 'migrator_schema_privileges';
    $account = $db->query('SELECT CURRENT_USER()')->fetchColumn();
    $grants = $db->prepare('SELECT GRANTEE,TABLE_SCHEMA,PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=? AND TABLE_SCHEMA IN (?,?)');
    $grants->execute(["'novinpay_mig1'@'localhost'", SOURCE_DB, str_replace('_', '\\_', SOURCE_DB)]);
    $need(pr13MigratorSchemaPrivilegesPresent($grants->fetchAll(), SOURCE_DB, $account), $check);
    $check = 'migration_history';
    $pending = pr13MigrationDelta($db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN), $m['baselineMigrations'], $m['candidateMigrations']);
    $check = 'trigger_inventory';
    $triggers = $db->query('SELECT TRIGGER_NAME name,EVENT_OBJECT_TABLE tableName,EVENT_MANIPULATION eventName,ACTION_TIMING timing,ACTION_STATEMENT body,DEFINER definer FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll();
    $need(count($triggers) === 5, $check);
    foreach ($triggers as &$trigger) {
        $trigger['bodySha256'] = hash('sha256', $trigger['body']);
        unset($trigger['body']);
        $need(str_starts_with($trigger['definer'], 'novinpay_mig1@'), 'trigger_definer');
    }
    unset($trigger);
    $db->rollBack();
    $need(hash_equals($envBefore, hash_file('sha256', APP.'/.env')), 'environment_unchanged');
    echo json_encode(['result' => 'PR13_READONLY_INVENTORY_COMPLETE', 'productionCommit' => $m['baseline'], 'candidateCommit' => $m['candidate'],
        'migrations' => 14, 'pendingMigrations' => $pending, 'observedTriggers' => $triggers,
        'migratorSchemaPrivilegesPresent' => true,
        'composerExecutablePresent' => is_file('/opt/cpanel/composer/bin/composer') || is_file('/usr/local/bin/composer'),
        'physicalFreeBytes' => disk_free_space(APP), 'php' => PHP_VERSION, 'productionModified' => false, 'databaseWrites' => false,
        'backupExecuted' => false, 'deploymentExecuted' => false, 'next' => 'Review trigger hashes and migration privileges; establish fresh verified backup/restore/off-host recovery before activation.'], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    // Only fixed check labels, never SQL/credentials, paths from exceptions or traces.
    echo json_encode(['result' => 'PR13_PREFLIGHT_STOP', 'phase' => $phase, 'check' => $error instanceof GateFailure ? $error->check : $check,
        'category' => $error instanceof PDOException ? 'database_details_withheld' : 'identity_or_contract', 'productionModified' => false]).PHP_EOL;
    exit(1);
}
