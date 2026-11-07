<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
require_once __DIR__.'/runtime.php';

use NovinPay\Release\Journal;
use NovinPay\Release\Pending;

use function NovinPay\Release\failureReport;
use function NovinPay\Release\privateRead;
use function NovinPay\Release\requireGate;
use function NovinPay\Release\writeNew;

$phase = 'integrity';
$armed = false;
$done = false;
$journal = null;
$m = null;
register_shutdown_function(static function () use (&$done, &$phase, &$armed): void {
    if (! $done) {
        fwrite(STDERR, json_encode(['result' => 'INTERRUPTED', 'phase' => $phase, 'fenceMayBeActive' => ($GLOBALS['np_fence_armed'] ?? $armed),
            'retrySafe' => false, 'nextAction' => 'Inspect durable phase receipts and actual HEAD; never replay an ambiguous mutation.']).PHP_EOL);
    }
});
function backupVerified(array $receipt, array $m): bool
{
    return ($receipt['result'] ?? '') === 'BACKUP_AND_DISPOSABLE_RESTORE_PASS'
        && ($receipt['sourceCommit'] ?? '') === $m['baseline'] && ($receipt['sourceDatabase'] ?? '') === 'novinpay_live1'
        && ($receipt['restoreDatabase'] ?? '') === $m['backup']['restoreDatabase'] && ($receipt['migrations'] ?? 0) === 14 && ($receipt['triggers'] ?? 0) === 5
        && ($receipt['schemaAndDefinersMatch'] ?? false) && ($receipt['allSnapshotRowsMatch'] ?? false)
        && ($receipt['privateMaintenanceStateVerified'] ?? false) && ($receipt['serverPlaintextRemoved'] ?? false)
        && ($receipt['productionUpdated'] ?? true) === false && is_file($m['backup']['directory'].'/backup.npb')
        && ! is_link($m['backup']['directory'].'/backup.npb')
        && hash_equals($receipt['archiveSha256'] ?? '', hash_file('sha256', $m['backup']['directory'].'/backup.npb'));
}
function proofVerified(array $proof, array $receipt, array $m): void
{
    foreach (['authenticatedDecryption', 'archiveContentsVerified', 'isolatedRestoreVerified', 'schemaAndDefinersMatch', 'allSnapshotRowsMatch',
        'noPlaintextWrittenToWindowsDisk', 'recoveryKeyAvailable', 'privateMaintenanceHistoryVerified'] as $key) {
        requireGate(($proof[$key] ?? null) === true, 'offhost_'.$key, 'independent off-host recovery PASS', [], 'Finish the independent off-host verification; do not activate.');
    }
    requireGate(($proof['syntheticFixture'] ?? false) === false && ($proof['sourceCommit'] ?? '') === $m['baseline']
        && ($proof['sourceDatabase'] ?? '') === 'novinpay_live1' && ($proof['restoreDatabase'] ?? '') === $m['backup']['restoreDatabase']
        && ($proof['serverArchivePath'] ?? '') === $m['backup']['directory'].'/backup.npb'
        && ($proof['archiveSha256'] ?? '') === $receipt['archiveSha256'] && ($proof['snapshotUnix'] ?? null) === $receipt['snapshotUnix'],
        'offhost_identity', 'proof bound to this exact backup', [], 'Inspect proof and backup linkage.');
    \NovinPay\Release\recoveryGate($proof, $m['recoveryPolicy'], time());
}
try {
    requireGate(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && function_exists('posix_geteuid') && posix_geteuid() === 1321
        && count($argv) === 4 && in_array($argv[1], ['run', 'status', 'recover-code'], true), 'invocation',
        'pipeline.php run|status|recover-code MANIFEST SHA256 under novinpay PHP 8.4', [], 'Use the reviewed pinned command.');
    $text = privateRead($argv[2]);
    requireGate(preg_match('/\A[a-f0-9]{64}\z/', $argv[3]) === 1 && hash_equals($argv[3], hash('sha256', $text)), 'manifest_pin', 'reviewed manifest', [], 'Verify immutable package identity.');
    $m = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
    $m['_sha256'] = $argv[3];
    requireGate(__DIR__ === '/home/novinpay/novinpay-release-pipeline-v1' && $m['app'] === '/home/novinpay/novinpay' && $m['stateDirectory'] === __DIR__.'/pipeline-pr12'
        && $m['backup']['directory'] === '/home/novinpay/novinpay-private-backups/maintenance-pr12-v2'
        && $m['backup']['restoreDatabase'] === 'novinpay_restore10r3', 'manifest_scope', 'approved permanent path and isolated recovery scope', [], 'Inspect manifest scope.');
    foreach ($m['tools'] as $file => $hash) {
        requireGate(preg_match('/\A[a-z0-9-]+\.(php|json|zip)\z/', $file) === 1 && is_file(__DIR__.'/'.$file) && ! is_link(__DIR__.'/'.$file)
            && hash_equals($hash, hash_file('sha256', __DIR__.'/'.$file)), 'tool_hash', 'complete pinned tool set', [], 'Do not execute changed tools.');
    }
    require __DIR__.'/guard-v1.php';
    require __DIR__.'/backup-working-tree-v2.php';
    require __DIR__.'/activation.php';
    $host = json_decode(privateRead(__DIR__.'/hosting-proof.json'), true, 32, JSON_THROW_ON_ERROR);
    foreach (['webPhp84Verified', 'webUidMatchesCli', 'webFlockRenameFsyncVerified', 'appScopedOpcacheVerified'] as $key) {
        requireGate(($host[$key] ?? null) === true, 'hosting_'.$key, 'verified unchanged hosting capability', [], 'Stop if hosting or relevant state implementation changed.');
    }
    chdir($m['app']);
    $phase = 'production_identity';
    requireGate(realpath($m['app']) === $m['app'] && ! is_link($m['app']) && readlink('/home/novinpay/public_html') === $m['app'].'/public',
        'permanent_path', 'unchanged production path/symlink', [], 'Inspect routing without modifying it.');
    $head = trim(\NovinPayReleaseV1\git($m['app'], ['rev-parse', 'HEAD']));
    requireGate(in_array($head, [$m['baseline'], $m['target']], true), 'production_head', 'approved baseline or target', [], 'Reconcile the unexpected source identity.');
    \NovinPayBackupWorktreeV2\verify($m['app']);
    if ($argv[1] === 'status') {
        echo json_encode(['result' => 'READ_ONLY_STATUS', 'head' => $head, 'laravelMaintenance' => is_file($m['app'].'/storage/framework/down'),
            'backupReceipt' => is_file($m['backup']['directory'].'/receipt.json'), 'activationReceipt' => is_file($m['stateDirectory'].'/activation.complete.json')]).PHP_EOL;
        $done = true;
        exit;
    }
    if (! is_dir($m['stateDirectory'])) {
        requireGate(! is_link($m['stateDirectory']) && mkdir($m['stateDirectory'], 0700), 'journal_create', 'private release journal', [], 'Inspect filesystem state.');
    }
    requireGate(! is_link($m['stateDirectory']) && fileowner($m['stateDirectory']) === 1321 && (fileperms($m['stateDirectory']) & 0777) === 0700, 'journal_private', 'owned directory mode 0700', [], 'Inspect permissions.');
    $journal = new Journal($m['stateDirectory'], $m['_sha256']);
    if ($argv[1] === 'recover-code') {
        $phase = 'code_recovery';
        \NovinPay\Release\Activation\recover($m);
        $armed = false;
        $done = true;
        echo "CODE-ONLY RECOVERY PASS; database and private state preserved.\n";
        exit;
    }
    if (is_file($m['stateDirectory'].'/activation.complete.json')) {
        $phase = 'acceptance_revalidation';
        \NovinPay\Release\Activation\verifyLive($m);
        $done = true;
        echo 'RELEASE ALREADY COMPLETE: '.$m['target'].PHP_EOL;
        exit;
    }
    if ($head === $m['target'] && is_file($m['stateDirectory'].'/activation.started.json')) {
        $phase = 'interrupted_acceptance_reconciliation';
        $start = json_decode(privateRead($m['stateDirectory'].'/activation.started.json'), true, 16, JSON_THROW_ON_ERROR);
        requireGate(($start['manifest'] ?? '') === $m['_sha256'] && ($start['phase'] ?? '') === 'activation',
            'activation_receipt_identity', 'matching immutable started receipt', [], 'Inspect the activation journal.');
        // This read-only reconciliation never removes an active fence or replays Git.
        \NovinPay\Release\Activation\verifyLive($m);
        writeNew($m['stateDirectory'].'/activation.complete.json', ['manifest' => $m['_sha256'], 'phase' => 'activation',
            'result' => ['commit' => $m['target'], 'maintenanceOff' => true, 'databaseRestored' => false, 'migrations' => false, 'verifiedUtc' => gmdate('c')],
            'completedUtc' => gmdate('c'), 'reconciled' => true]);
        $done = true;
        echo 'RELEASE VERIFIED AFTER INTERRUPTION: '.$m['target'].PHP_EOL;
        exit;
    }
    requireGate($head === $m['baseline'], 'activation_interrupted', 'baseline before uncompleted release', [], 'Inspect activation receipts; use the reviewed code-recovery action if necessary.');
    $phase = 'failed_attempt_reconciliation';
    $failed = '/home/novinpay/novinpay-private-backups/maintenance-pr12-v1';
    $names = array_values(array_diff(scandir($failed), ['.', '..']));
    sort($names);
    requireGate($names === ['private-process-error.txt', 'source-commit.txt'] && privateRead($failed.'/private-process-error.txt') === ''
        && trim(privateRead($failed.'/source-commit.txt')) === $m['baseline'], 'previous_attempt_changed', 'two preserved pre-export files only', [], 'Inspect prior evidence; no automatic deletion.');
    \NovinPay\Release\Activation\sourceReady($m);
    $phase = 'backup_preflight';
    $context = [];
    if (! is_file($m['stateDirectory'].'/backup.complete.json')) {
        requireGate(! is_file($m['stateDirectory'].'/backup.started.json'), 'backup_interrupted', 'no ambiguous import', [], 'Preserve and reconcile partial backup/restore before resuming.');
        require __DIR__.'/backup.php';
        require __DIR__.'/backup-io.php';
        $context = \NovinPay\Release\Backup\prepare($m); // read-only checks and fresh quota BEFORE started receipt or backup writes
    }
    $phase = 'backup';
    $receipt = $journal->phase('backup', static fn () => \NovinPay\Release\Backup\execute($context), static fn ($r) => backupVerified($r, $m));
    unset($context);
    unset($GLOBALS['np_backup_check']);
    $phase = 'independent_offhost_recovery';
    $deadline = microtime(true) + 900;
    $proofPath = $m['stateDirectory'].'/offhost-proof.json';
    $readyPath = $m['stateDirectory'].'/offhost-ready.json';
    $ready = null;
    while (microtime(true) < $deadline) {
        clearstatcache();
        if (is_file($readyPath)) {
            try {
                $ready = json_decode(privateRead($readyPath), true, 16, JSON_THROW_ON_ERROR);
                break;
            } catch (JsonException) {
                // File Manager may expose a partially uploaded readiness marker.
            }
        }
        sleep(2);
    }
    if ($ready === null) {
        throw new Pending('offhost_verification_pending_backup_preserved');
    }
    $proofText = privateRead($proofPath);
    requireGate(($ready['manifestSha256'] ?? '') === $m['_sha256'] && hash_equals($ready['sha256'] ?? '', hash('sha256', $proofText)),
        'offhost_proof_pin', 'proof verified by the authenticated local client', [], 'Inspect recovery proof.');
    $proof = json_decode($proofText, true, 32, JSON_THROW_ON_ERROR);
    proofVerified($proof, $receipt, $m);
    $phase = 'activation_preflight';
    \NovinPay\Release\Activation\sourceReady($m);
    \NovinPay\Release\Activation\backupCurrent($m, $proof);
    \NovinPay\Release\requestQuota($m, __DIR__, 'fence');
    $phase = 'activation';
    $journal->phase('activation', static fn () => \NovinPay\Release\Activation\execute($m), static fn ($r) => ($r['commit'] ?? '') === $m['target']);
    $armed = false;
    $done = true;
    echo 'RELEASE PASS: '.$m['target']."; maintenance OFF; no migrations or data restore.\n";
} catch (Pending $error) {
    $done = true;
    fwrite(STDERR, json_encode(['result' => 'PENDING', 'phase' => $phase, 'check' => $error->getMessage(), 'exitCode' => 75,
        'productionModified' => (bool) ($GLOBALS['np_fence_armed'] ?? $armed), 'nextAction' => $phase === 'activation'
            ? 'Read the activation receipts; rerun this command only to recheck live health when the fence is absent. Do not replay Git.'
            : 'Restore the authenticated local API bridge and reuse this command; completed backup is preserved.']).PHP_EOL);
    exit(75);
} catch (Throwable $error) {
    $armed = (bool) ($GLOBALS['np_fence_armed'] ?? $armed);
    $report = failureReport($error, $phase, $GLOBALS['np_backup_check'] ?? $GLOBALS['np_activation_check'] ?? $phase, $armed);
    if ($armed && is_array($m)) {
        try {
            \NovinPay\Release\Activation\fence($m);
            $report['fence'] = 'retained';
        } catch (Throwable) {
            $report['fence'] = 'UNCONFIRMED';
        }
    }
    if (is_array($m) && is_dir($m['stateDirectory'])) {
        try {
            writeNew($m['stateDirectory'].'/failure-'.bin2hex(random_bytes(8)).'.json', $report);
        } catch (Throwable) {
        }
    }
    $done = true;
    fwrite(STDERR, json_encode($report, JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
} finally {
    if ($journal) {
        $journal->close();
    }
}
