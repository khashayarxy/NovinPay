<?php

declare(strict_types=1);

// Native CLI entry point. Inspection never resumes a backup or deploys code.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once __DIR__.'/lib.php';

use function NovinPay\Release\failureReport;
use function NovinPay\Release\requireGate;
use function NovinPay\Release\restoreDefaultsPlan;

$phase = 'inspection';
$check = 'invocation';
$done = false;
register_shutdown_function(static function () use (&$done, &$phase, &$check): void {
    $error = error_get_last();
    if (! $done && $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, json_encode(['result' => 'STOP', 'phase' => $phase, 'check' => $check, 'category' => 'php_fatal',
            'expected' => 'inspection completes', 'actual' => ['fatalType' => $error['type']], 'productionModified' => false,
            'retrySafe' => false, 'nextAction' => 'Preserve evidence and inspect the named check.']).PHP_EOL);
    }
});
function reportStep(string $name, array $actual): void
{
    echo json_encode(['result' => 'CHECK_PASS', 'phase' => 'inspection', 'check' => $name, 'actual' => $actual], JSON_THROW_ON_ERROR).PHP_EOL;
}
try {
    requireGate(PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'inspect-backup' && count($argv) === 4,
        'invocation', 'release.php inspect-backup MANIFEST MANIFEST_SHA256', [], 'Use the reviewed integrity-verified inspection command.');
    $manifestPath = $argv[2];
    requireGate(is_file($manifestPath) && ! is_link($manifestPath) && preg_match('/\A[a-f0-9]{64}\z/', $argv[3]) === 1
        && hash_equals($argv[3], hash_file('sha256', $manifestPath)), 'manifest_integrity', 'exact pinned manifest', [], 'Read back and verify the release manifest.');
    $m = json_decode(file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
    foreach ($m['toolHashes'] as $file => $hash) {
        requireGate(preg_match('/\A[a-z-]+\.php\z/', $file) === 1 && is_file(__DIR__.'/'.$file) && ! is_link(__DIR__.'/'.$file)
            && hash_equals($hash, hash_file('sha256', __DIR__.'/'.$file)), 'tool_integrity', 'pinned reviewed tools', [], 'Do not run altered tooling.');
    }
    $check = 'host_identity';
    requireGate(PHP_VERSION_ID >= 80401 && function_exists('posix_geteuid') && posix_geteuid() === 1321,
        $check, 'PHP 8.4.1+ under account UID 1321', ['php' => PHP_VERSION], 'Use the explicit ea-php84 executable under novinpay.');
    define('APP', '/home/novinpay/novinpay');
    define('SOURCE_DB', 'novinpay_live1');
    requireGate(realpath(APP) === APP && ! is_link(APP) && is_link('/home/novinpay/public_html')
        && readlink('/home/novinpay/public_html') === APP.'/public', 'routing_identity', 'unchanged permanent path and symlink', [], 'Stop; inspect routing without changing it.');
    $package = '/home/novinpay/novinpay-maintenance-release-v3';
    foreach ($m['legacyToolHashes'] as $file => $hash) {
        requireGate(preg_match('/\A[a-z0-9-]+\.php\z/', $file) === 1 && is_file($package.'/'.$file) && ! is_link($package.'/'.$file)
            && hash_equals($hash, hash_file('sha256', $package.'/'.$file)), 'existing_tool_integrity', 'unchanged failed-attempt evidence', [], 'Preserve and inspect changed evidence.');
    }
    require $package.'/guard-v1.php';
    require $package.'/backup-working-tree-v2.php';
    $check = 'git_and_worktree';
    requireGate(trim(\NovinPayReleaseV1\git(APP, ['rev-parse', 'HEAD'])) === $m['baseline'], $check, 'approved production baseline', [], 'Stop; reconcile production commit.');
    \NovinPayBackupWorktreeV2\verify(APP);
    reportStep($check, ['expectedCommit' => true, 'expectedWorktree' => true]);

    $check = 'partial_artifact_inventory';
    $out = '/home/novinpay/novinpay-private-backups/maintenance-pr12-v1';
    requireGate(is_dir($out) && ! is_link($out), $check, 'preserved failed-attempt directory', [], 'Preserve the previous attempt.');
    $files = array_values(array_diff(scandir($out), ['.', '..']));
    sort($files);
    requireGate($files === ['private-process-error.txt', 'source-commit.txt'], $check, 'only the two observed pre-export artifacts',
        ['entryCount' => count($files), 'archivePresent' => is_file($out.'/backup.npb'), 'receiptPresent' => is_file($out.'/receipt.json')],
        'Inspect changed partial evidence; never resume or erase an unknown backup attempt.');
    foreach ($files as $file) {
        requireGate(is_file($out.'/'.$file) && ! is_link($out.'/'.$file) && fileowner($out.'/'.$file) === 1321
            && (fileperms($out.'/'.$file) & 0777) === 0600, 'partial_artifact_properties', 'owned private regular files', [], 'Preserve and inspect evidence metadata.');
    }
    requireGate(filesize($out.'/private-process-error.txt') === 0 && trim(file_get_contents($out.'/source-commit.txt')) === $m['baseline'],
        'partial_artifact_content', 'empty process error and expected source identity', [], 'Inspect the changed evidence privately.');
    reportStep($check, ['archivePresent' => false, 'receiptPresent' => false, 'exportArtifactPresent' => false]);
    $check = 'runtime_environment_parser_and_connection';
    $envHash = hash_file('sha256', APP.'/.env');
    require __DIR__.'/production-environment.php';
    $env = verifiedProductionEnvironment();
    unset($env);
    reportStep($check, ['identityMatches' => true, 'environmentUnchanged' => hash_equals($envHash, hash_file('sha256', APP.'/.env'))]);

    $check = 'migrator_readonly_connection';
    $credentialsPath = '/home/novinpay/novinpay-releases/155fc9f30feb5b9d0c41445b33f1118d5e07da8e/.stage2-credentials.json';
    requireGate(is_file($credentialsPath) && ! is_link($credentialsPath) && (fileperms($credentialsPath) & 0777) === 0600
        && hash_equals($m['credentialsSha256'], hash_file('sha256', $credentialsPath)), 'retained_credentials_identity', 'unchanged private retained credentials', [], 'Do not change credentials; inspect evidence.');
    $credentials = json_decode(file_get_contents($credentialsPath), true, 32, JSON_THROW_ON_ERROR)['migrator'];
    requireGate($credentials['user'] === 'novinpay_mig1', $check, 'existing trigger-definer account', [], 'Inspect account identity.');
    $connect = static fn (string $db): PDO => new PDO('mysql:host=localhost;port=3306;dbname='.$db.';charset=utf8mb4',
        $credentials['user'], $credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $source = $connect(SOURCE_DB);
    $restore = $connect('novinpay_restore10r3');
    unset($connect, $credentials);
    foreach ([$source, $restore] as $db) {
        $db->exec('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
    }
    $check = 'restore_identity_and_emptiness';
    requireGate($restore->query('SELECT DATABASE()')->fetchColumn() === 'novinpay_restore10r3'
        && str_starts_with($restore->query('SELECT CURRENT_USER()')->fetchColumn(), 'novinpay_mig1@'), $check, 'approved isolated restore target/account', [], 'Stop; inspect target identity.');
    $tables = (int) $restore->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
    requireGate($tables === 0, $check, 'empty isolated restore database', ['tableCount' => $tables], 'Preserve partial restore evidence; do not retry import.');
    reportStep($check, ['tableCount' => $tables, 'database' => 'novinpay_restore10r3']);
    $check = 'restore_defaults_and_effective_privileges';
    $query = 'SELECT DEFAULT_CHARACTER_SET_NAME charset, DEFAULT_COLLATION_NAME collation FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()';
    $sourceDefaults = $source->query($query)->fetch();
    $restoreDefaults = $restore->query($query)->fetch();
    $role = $restore->query('SELECT CURRENT_ROLE()')->fetchColumn();
    requireGate($role === null || $role === 'NONE', 'restore_active_role', 'no inherited active role', ['roleActive' => true], 'Inspect role privileges without broadening grants.');
    $account = $restore->query('SELECT CURRENT_USER()')->fetchColumn();
    [$user, $host] = explode('@', $account, 2);
    $grants = $restore->prepare('SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE TABLE_SCHEMA=? AND GRANTEE=? ORDER BY PRIVILEGE_TYPE');
    $grants->execute(['novinpay_restore10r3', "'".$user."'@'".$host."'"]);
    $privileges = $grants->fetchAll(PDO::FETCH_COLUMN);
    reportStep($check, ['sourceDefaults' => $sourceDefaults, 'restoreDefaults' => $restoreDefaults, 'restorePrivileges' => $privileges,
        'previousUnconditionalAlterPermitted' => in_array('ALTER', $privileges, true)]);
    $check = 'application_inventory_readability';
    $appBytes = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isLink() && $file->isFile()) {
            $appBytes += $file->getSize();
        }
    }
    reportStep($check, ['regularFileBytes' => $appBytes]);
    $check = 'restore_defaults_plan';
    $plan = restoreDefaultsPlan($sourceDefaults, $restoreDefaults, $privileges);
    $source->rollBack();
    $restore->rollBack();
    requireGate(hash_equals($envHash, hash_file('sha256', APP.'/.env')), 'environment_preserved', 'unchanged environment', [], 'Preserve evidence.');
    echo json_encode(['result' => 'READ_ONLY_BACKUP_INSPECTION_PASS', 'restoreDefaultsPlan' => $plan,
        'productionModified' => false, 'databaseWrites' => false, 'backupExecuted' => false, 'deploymentExecuted' => false,
        'nextAction' => 'Review the exact failed-attempt diagnosis before preparing a resumable backup.']).PHP_EOL;
    $done = true;
} catch (Throwable $error) {
    foreach (['source', 'restore'] as $name) {
        if (isset($$name) && $$name instanceof PDO && $$name->inTransaction()) {
            try {
                $$name->rollBack();
            } catch (Throwable) {
                // Read-only transactions close with the connection; never replace the first diagnostic.
            }
        }
    }
    fwrite(STDERR, json_encode(failureReport($error, $phase, $check), JSON_UNESCAPED_SLASHES).PHP_EOL);
    $done = true;
    exit(1);
}
