<?php

declare(strict_types=1);
use NovinPay\Release\Pending;

// The same entry point consumes a newly pinned manifest for each generation.
// No source switch, live migration, credential change or automatic retry occurs here.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
$finished = false;
$phase = 'invocation';
$out = null;
$m = null;
register_shutdown_function(static function () use (&$finished, &$phase): void {
    if (! $finished) {
        echo json_encode(['result' => 'DATABASE_RECOVERY_STOP', 'phase' => $phase, 'check' => 'unexpected_runtime_exit',
            'liveDatabaseWritten' => false, 'retrySafe' => false]).PHP_EOL;
    }
});
try {
    require_once __DIR__.'/recovery-contract.php';
    require_once __DIR__.'/backup.php';
    require_once __DIR__.'/backup-io.php';
    \NovinPay\Release\requireGate(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && function_exists('posix_geteuid')
        && posix_geteuid() === 1321 && count($argv) === 4 && in_array($argv[3], ['inspect', 'capture', 'resume'], true),
        'recovery_invocation', 'hosting owner PHP 8.4 CLI, manifest, SHA-256 and action', [], 'Use the reviewed checksum-verified command.');
    $bytes = \NovinPay\Release\privateRead($argv[1]);
    \NovinPay\Release\requireGate(preg_match('/\A[a-f0-9]{64}\z/D', $argv[2]) === 1 && hash_equals($argv[2], hash('sha256', $bytes)),
        'manifest_pin', 'reviewed manifest bytes', [], 'Preserve the manifest and verify the package.');
    $m = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    $m['_sha256'] = $argv[2];
    \NovinPay\Release\recoveryManifest($m);
    $out = $m['backup']['directory'];
    if (is_file($out.'/receipt.json')) {
        $r = json_decode(\NovinPay\Release\privateRead($out.'/receipt.json'), true, 32, JSON_THROW_ON_ERROR);
        \NovinPay\Release\requireGate(is_file($out.'/backup.npb') && ! is_link($out.'/backup.npb')
            && \NovinPay\Release\recoveryReceiptMatches($r, $m, hash_file('sha256', $out.'/backup.npb')),
            'existing_recovery_receipt_changed', 'complete capture matches its manifest and archive', [], 'Preserve all evidence and reconcile the changed receipt.');
        $finished = true;
        echo json_encode(['result' => 'DATABASE_BACKUP_ALREADY_CAPTURED', 'migrations' => $r['migrations'], 'triggers' => $r['triggers'], 'archiveSha256' => $r['archiveSha256'],
            'nextAction' => 'Reuse verified capture; finish or reconcile independent off-host verification. No backup or restore was repeated.']).PHP_EOL;
        exit(0);
    }
    if ($argv[3] === 'capture') {
        \NovinPay\Release\requireGate(! file_exists($m['stateDirectory'].'/recovery-'.$m['_sha256'].'.stop.json'),
            'previous_recovery_stop', 'reconciled new attempt manifest', [], 'Preserve the STOP receipt; inspect its phase, destination and restore state before preparing a newly pinned attempt.');
    }
    if ($argv[3] === 'resume') {
        $receipt = json_decode(\NovinPay\Release\privateRead($m['stateDirectory'].'/recovery-'.$m['_sha256'].'.stop.json'), true, 16, JSON_THROW_ON_ERROR);
        \NovinPay\Release\requireGate(\NovinPay\Release\recoveryResumeAllowed($receipt, $m['_sha256'], file_exists($out)),
            'recovery_resume_forbidden', 'only a reconciled quota wait before capture may resume', [], 'Preserve partial capture/restore and STOP receipts.');
    }
    foreach ($m['previousAttempts'] ?? [] as $prior) {
        \NovinPay\Release\requireGate(str_starts_with($prior['marker'], $m['stateDirectory'].'/')
            && ! str_contains($prior['marker'], '..') && hash_equals($prior['sha256'], hash('sha256', \NovinPay\Release\privateRead($prior['marker'])))
            && str_starts_with($prior['absentBackupDirectory'], '/home/novinpay/novinpay-private-backups/')
            && ! file_exists($prior['absentBackupDirectory']) && ! is_link($prior['absentBackupDirectory']),
            'previous_attempt_reconciliation', 'preserved pre-capture marker and absent old backup destination', [],
            'Inspect prior receipts and actual isolated schema; never remove a marker or overwrite partial artifacts.');
    }
    $phase = 'preflight';
    $operation = $argv[3] !== 'inspect' ? \NovinPay\Release\operationLock($m['stateDirectory']) : null;
    $context = \NovinPay\Release\Backup\prepare($m, $argv[3] !== 'inspect');
    echo json_encode(['result' => 'RECOVERY_PREFLIGHT_PASS', 'sourceCommit' => $m['baseline'],
        'migrations' => count($m['migrations']), 'triggers' => $m['expectedTriggers'], 'restoreTargetEmpty' => true,
        'liveDatabaseWritten' => false, 'captureStarted' => false]).PHP_EOL;
    if ($argv[3] !== 'inspect') {
        $phase = 'capture_and_isolated_restore';
        $r = \NovinPay\Release\Backup\execute($context);
        echo json_encode(['result' => 'DATABASE_BACKUP_AND_ISOLATED_RESTORE_PASS', 'sourceCommit' => $m['baseline'],
            'migrations' => $r['migrations'], 'triggers' => $r['triggers'], 'archiveSha256' => $r['archiveSha256'],
            'offHostPending' => true, 'liveDatabaseWritten' => false]).PHP_EOL;
    }
    $finished = true;
} catch (Throwable $error) {
    $report = function_exists('NovinPay\Release\recoveryFailure')
        ? \NovinPay\Release\recoveryFailure($error, $phase, is_string($out) && file_exists($out))
        : ['result' => 'DATABASE_RECOVERY_STOP', 'phase' => $phase, 'check' => 'recovery_tool_loading', 'liveDatabaseWritten' => false, 'retrySafe' => false];
    $busy = in_array($report['check'] ?? '', ['operation_already_running', 'recovery_already_running'], true);
    if ($busy) {
        $report['result'] = 'DATABASE_RECOVERY_BUSY';
    }
    if (! $busy && is_array($m) && isset($m['_sha256']) && ($m['stateDirectory'] ?? null) === '/home/novinpay/novinpay-direct-pr13' && in_array($argv[3] ?? '', ['capture', 'resume'], true)) {
        $stop = $m['stateDirectory'].'/recovery-'.$m['_sha256'].'.stop.json';
        if (file_exists($stop)) {
            $stop = $m['stateDirectory'].'/recovery-'.$m['_sha256'].'.stop-'.bin2hex(random_bytes(6)).'.json';
        }
        try {
            \NovinPay\Release\writeNew($stop, $report + ['manifestSha256' => $m['_sha256'], 'observedUtc' => gmdate('c')]);
        } catch (Throwable) {
            $report['diagnosticReceiptWritten'] = false;
        }
    }
    $finished = true;
    echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
    exit($error instanceof Pending ? 75 : 1);
}
