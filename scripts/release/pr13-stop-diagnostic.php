<?php

declare(strict_types=1);

// Read-only reconciliation. Never requires backup.php or the recovery entry point.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/pr13-maintenance-snapshot.php';
$check = 'invocation';
$connections = [];
$lock = null;
try {
    $need = static function (bool $ok, string $label): void {
        if (! $ok) {
            throw new RuntimeException($label);
        }
    };
    $need(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && function_exists('posix_geteuid') && posix_geteuid() === 1321 && count($argv) === 5, $check);
    $text = NovinPay\Release\privateRead($argv[1]);
    $need(hash_equals('9d43b0ab1c0dbb7d3da4720c1fbb357081e79f2a4551a66a1cd3092f8a200a69', $argv[2]) && hash_equals($argv[2], hash('sha256', $text)), 'original_manifest_pin');
    $m = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    $anchors = NovinPay\Release\privateRead($argv[3]);
    $need(hash_equals($argv[4], hash('sha256', $anchors)), 'diagnostic_anchor_pin');
    $anchors = json_decode($anchors, true, flags: JSON_THROW_ON_ERROR);
    define('APP', '/home/novinpay/novinpay');
    define('SOURCE_DB', 'novinpay_live1');
    $need(__DIR__ === '/home/novinpay/novinpay-release-pipeline-v1' && realpath(APP) === APP && ! is_link(APP)
        && readlink('/home/novinpay/public_html') === APP.'/public', 'production_path');
    $check = 'locked_maintenance_snapshot';
    $dir = APP.'/storage/framework/site-maintenance';
    $need(! is_link($dir) && is_dir($dir) && fileowner($dir) === 1321 && (fileperms($dir) & 0777) === 0700, 'maintenance_directory');
    $need(is_file($dir.'/state.lock') && ! is_link($dir.'/state.lock') && fileowner($dir.'/state.lock') === 1321
        && (fileperms($dir.'/state.lock') & 0777) === 0600, 'readonly_lock_identity');
    $lock = fopen($dir.'/state.lock', 'rb');
    $need(is_resource($lock) && flock($lock, LOCK_SH | LOCK_NB), 'readonly_maintenance_lock');
    $state = NovinPay\Release\privateRead($dir.'/state.json');
    $audit = NovinPay\Release\privateRead($dir.'/audit.jsonl');
    $snapshot = pr13MaintenanceSnapshotEvidence($state, $audit, $m['maintenanceHashes']);
    $actor = json_decode($state, true, flags: JSON_THROW_ON_ERROR)['actor_id'];
    flock($lock, LOCK_UN);
    fclose($lock);
    $lock = null;
    $check = 'attempt_artifacts';
    $journal = __DIR__.'/pipeline-pr13';
    $need(is_dir($journal) && ! is_link($journal) && fileowner($journal) === 1321 && (fileperms($journal) & 0777) === 0700, 'prior_journal_identity');
    $entries = array_values(array_diff(scandir($journal), ['.', '..']));
    sort($entries);
    $expected = ['pipeline.lock', 'restore_defaults.complete.json', 'restore_defaults.started.json'];
    sort($expected);
    $need($entries === $expected && ! file_exists($m['backup']['directory']) && ! is_link($m['backup']['directory'])
        && ! file_exists(__DIR__.'/pr13-rehearsal-app') && ! is_link(__DIR__.'/pr13-rehearsal-app'), 'unexpected_attempt_artifacts');
    foreach (['restore_defaults.started.json', 'restore_defaults.complete.json'] as $file) {
        $receipt = json_decode(NovinPay\Release\privateRead($journal.'/'.$file), true, flags: JSON_THROW_ON_ERROR);
        $need(($receipt['manifest'] ?? '') === $argv[2] && ($receipt['phase'] ?? '') === 'restore_defaults', 'prior_receipt_identity');
    }
    $check = 'production_files';
    $git = static function (array $args): array {
        $r = NovinPay\Release\command(array_merge(['git', '--no-optional-locks', '-c', 'core.fsmonitor=false', '-C', APP], $args), APP, 'readonly_git', 30);
        if ($r['exit'] !== 0) {
            throw new RuntimeException('readonly_git');
        }

        return $r;
    };
    $need(trim($git(['rev-parse', 'HEAD'])['stdout']) === $m['baseline'] && trim($git(['diff', '--cached', '--name-only'])['stdout']) === '', 'production_source_identity');
    $actual = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $git(['status', '--porcelain=v1', '--untracked-files=all'])['stdout'])), fn ($line) => $line !== ''));
    $expected = $m['preservedStatus'];
    sort($actual);
    sort($expected);
    $need($actual === $expected, 'production_worktree');
    $matches = [];
    foreach ($anchors['protectedFileHashes'] as $file => $hash) {
        $need(in_array($file, ['.env', 'public/.htaccess', 'public/build/manifest.json', 'composer.lock', 'package-lock.json'], true), 'protected_file_scope');
        $matches[$file] = ! is_link(APP.'/'.$file) && hash_equals($hash, hash_file('sha256', APP.'/'.$file));
    }
    $allProtectedFilesMatch = ! in_array(false, $matches, true);
    $need(hash_equals($m['credentialsSha256'], hash_file('sha256', '/home/novinpay/novinpay-releases/155fc9f30feb5b9d0c41445b33f1118d5e07da8e/.stage2-credentials.json'))
        && hash_equals($m['backup']['serverKeySha256'], hash_file('sha256', '/home/novinpay/.novinpay-maint10-key')), 'credential_or_key_fingerprint');
    $check = 'read_only_database_state';
    require_once __DIR__.'/production-environment.php';
    $env = verifiedProductionEnvironment();
    unset($env);
    $credential = json_decode(NovinPay\Release\privateRead('/home/novinpay/novinpay-releases/155fc9f30feb5b9d0c41445b33f1118d5e07da8e/.stage2-credentials.json'), true, flags: JSON_THROW_ON_ERROR)['migrator'];
    $tableCounts = [];
    foreach (['novinpay_live1', 'novinpay_restore13r1'] as $name) {
        $db = new PDO('mysql:host=localhost;port=3306;dbname='.$name.';charset=utf8mb4', $credential['user'], $credential['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $connections[] = $db;
        $db->exec('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        $need($db->query('SELECT DATABASE()')->fetchColumn() === $name && $db->query('SELECT CURRENT_USER()')->fetchColumn() === 'novinpay_mig1@localhost', 'database_identity');
        $tableCounts[$name] = (int) $db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
        if ($name === 'novinpay_live1') {
            $history = $db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
            $need($history === $m['baselineMigrations'] && count($history) === 14, 'production_migration_history');
            $owner = $db->prepare('SELECT role,owner_slot,is_active FROM admin_accounts WHERE user_id=?');
            $owner->execute([$actor]);
            $row = $owner->fetch();
            $actorIsOwner = $row && $row['role'] === 'super_admin' && (int) $row['owner_slot'] === 1 && (int) $row['is_active'] === 1;
        }
        $db->rollBack();
    }
    unset($credential);
    $need($tableCounts['novinpay_restore13r1'] === 0, 'restore_not_empty');
    echo json_encode(['result' => $allProtectedFilesMatch ? 'PR13_READONLY_STOP_RECONCILIATION_PASS' : 'PR13_READONLY_STOP_RECONCILIATION_REVIEW_REQUIRED', 'snapshot' => $snapshot, 'lastStateActorCurrentlyProtectedOwner' => $actorIsOwner,
        'lastCompletedPhase' => 'restore_defaults', 'freshBackupExists' => false, 'backupStarted' => false, 'restoreTables' => 0, 'rehearsalStarted' => false,
        'productionCommit' => $m['baseline'], 'productionMigrations' => 14, 'protectedFileMatches' => $matches,
        'credentialsAndKeyMatch' => true, 'productionWrites' => false, 'recoveryRetried' => false, 'retryAuthorized' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    $report = NovinPay\Release\failureReport($error, 'readonly_stop_reconciliation', $check, false);
    $report['result'] = 'PR13_READONLY_STOP_RECONCILIATION_STOP';
    $report['recoveryRetried'] = false;
    echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    foreach ($connections as $db) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}
