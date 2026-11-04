<?php

declare(strict_types=1);

namespace NovinPay\Release\Activation;

use NovinPay\Release\Pending;

use function NovinPay\Release\command;
use function NovinPay\Release\privateRead;
use function NovinPay\Release\requireGate;
use function NovinPay\Release\writeNew;

function run(array $argv, array $m, string $check, int $timeout = 180): string
{
    $r = command($argv, $m['app'], $check, $timeout);
    requireGate($r['exit'] === 0, $check, 'operation exits zero', ['exitCode' => $r['exit'], 'stderrCategory' => $r['stderrCategory'], 'failedCheck' => $r['failureCode']],
        'Inspect the named operation and release receipts; do not replay mutations.');

    return $r['stdout'];
}
function state(array $m): void
{
    foreach ($m['maintenanceHashes'] as $name => $hash) {
        requireGate(in_array($name, ['state.json', 'audit.jsonl'], true) && hash_equals($hash, hash('sha256', privateRead($m['app'].'/storage/framework/site-maintenance/'.$name))),
            'private_maintenance_history', 'unchanged reviewed state and audit history', [], 'Preserve changed history; update evidence deliberately.');
    }
    $s = json_decode(privateRead($m['app'].'/storage/framework/site-maintenance/state.json'), true, 16, JSON_THROW_ON_ERROR);
    requireGate(($s['enabled'] ?? null) === false, 'maintenance_disabled', 'public maintenance OFF', [], 'Inspect the administrative maintenance state.');
}
function sourceReady(array $m): void
{
    state($m);
    requireGate(! file_exists($m['app'].'/storage/framework/down') && ! file_exists($m['app'].'/storage/framework/maintenance.php'),
        'existing_fence', 'no unexplained deployment fence', [], 'Inspect release receipts; never remove a fence automatically.');
    requireGate(trim(\NovinPayReleaseV1\git($m['app'], ['branch', '--show-current'])) === 'codex/novinpay-release-contact-corrections'
        && trim(\NovinPayReleaseV1\git($m['app'], ['rev-parse', 'HEAD'])) === $m['baseline'], 'baseline_branch', 'expected unchanged production checkout', [], 'Reconcile source state.');
    requireGate(trim(\NovinPayReleaseV1\git($m['app'], ['remote', 'get-url', 'origin'])) === 'https://github.com/khashayarxy/NovinPay.git',
        'git_remote', 'existing trusted repository', [], 'Inspect origin; do not substitute a repository.');
    run(['git', 'fetch', '--no-tags', 'origin', 'main'], $m, 'fetch_reviewed_source');
    requireGate(trim(\NovinPayReleaseV1\git($m['app'], ['rev-parse', 'FETCH_HEAD'])) === $m['target'], 'remote_main_identity', 'exact approved merge', [], 'Review unexpected GitHub changes.');
    run(['git', 'merge-base', '--is-ancestor', $m['baseline'], $m['target']], $m, 'fast_forward_ancestry');
    $paths = explode("\n", trim(\NovinPayReleaseV1\git($m['app'], ['diff', '--name-only', $m['baseline'], $m['target']])));
    sort($paths);
    $expected = array_keys($m['changedFiles']);
    sort($expected);
    requireGate($paths === $expected && trim(\NovinPayReleaseV1\git($m['app'], ['rev-parse', $m['target'].'^{tree}'])) === $m['targetTree'],
        'reviewed_tree', 'exact reviewed presentation-only tree and paths', [], 'Stop on unexpected source changes.');
    foreach ($m['changedFiles'] as $file => $hash) {
        requireGate(hash_equals($hash, hash('sha256', \NovinPayReleaseV1\git($m['app'], ['show', $m['target'].':'.$file]))),
            'reviewed_blob', 'exact approved file contents', [], 'Inspect artifact identity.');
    }
    run([PHP_BINARY, __DIR__.'/health.php', '--local'], $m, 'local_readiness');
}
function backupCurrent(array $m, array $proof): void
{
    requireGate(count($proof['protectedFileHashes'] ?? []) === 5, 'backup_config_inventory', 'five protected configuration hashes', [], 'Inspect independent recovery proof.');
    foreach ($proof['protectedFileHashes'] as $file => $hash) {
        requireGate(in_array($file, ['.env', 'public/.htaccess', 'public/build/manifest.json', 'composer.lock', 'package-lock.json'], true)
            && ! is_link($m['app'].'/'.$file) && hash_equals($hash, hash_file('sha256', $m['app'].'/'.$file)),
            'backup_config_current', 'current protected configuration matches recovered snapshot', [], 'Preserve new configuration; refresh only the affected recovery evidence.');
    }
    state($m);
}
function fence(array $m): void
{
    \NovinPayReleaseV1\fence($m['app'], __DIR__, ['fenceSha256' => $m['tools']['fence-v1.php']]);
}
function preserve(array $m): void
{
    $snapshot = json_decode(privateRead($m['stateDirectory'].'/before.json'), true, 64, JSON_THROW_ON_ERROR);
    \NovinPayReleaseV1\preservation($m['app'], '/home/novinpay', $snapshot, false);
    \NovinPayBackupWorktreeV2\verify($m['app']);
    state($m);
}
function verifyLive(array $m, bool $rollback = false): void
{
    requireGate(trim(\NovinPayReleaseV1\git($m['app'], ['rev-parse', 'HEAD'])) === ($rollback ? $m['baseline'] : $m['target']),
        'active_source', 'expected release commit', [], 'Inspect actual source and receipts.');
    preserve($m);
    run([PHP_BINARY, __DIR__.'/health.php', '--local'], $m, 'local_readiness');
    $health = $rollback ? 'health-maintenance-v1.php' : 'health.php';
    $r = command([PHP_BINARY, __DIR__.'/'.$health, '--https'], $m['app'], 'https_acceptance', 600);
    if ($r['exit'] === 75) {
        run([PHP_BINARY, __DIR__.'/health.php', '--local'], $m, 'local_readiness_after_transport_failure');
        preserve($m);
        throw new Pending('live_transport_verification_pending');
    }
    requireGate($r['exit'] === 0, 'https_application_health', 'all required HTTPS checks pass', ['exitCode' => $r['exit'], 'failedCheck' => $r['failureCode']],
        'Preserve the fence for a genuine failure; inspect the safe health-check identifier.');
    requireGate(! file_exists($m['app'].'/storage/framework/down') && ! file_exists($m['app'].'/storage/framework/maintenance.php'),
        'traffic_admitted', 'no deployment maintenance marker', [], 'Inspect fence state.');
}
function execute(array $m): array
{
    sequence([
        'snapshot' => static function () use ($m): void {
            requireGate(! file_exists($m['stateDirectory'].'/before.json'), 'snapshot_exists', 'one immutable rollback snapshot', [], 'Inspect the interrupted activation.');
            writeNew($m['stateDirectory'].'/before.json', \NovinPayReleaseV1\snapshot($m['app'], '/home/novinpay'));
        },
        'fence' => static function () use ($m): void {
            $GLOBALS['np_fence_armed'] = true;
            fence($m);
            run([PHP_BINARY, __DIR__.'/fence-health-v1.php'], $m, 'actual_https_fence', 120);
        },
        'source' => static function () use ($m): void {
            // Already-admitted requests finish under unchanged business code.
            run(['git', 'merge', '--ff-only', $m['target']], $m, 'source_fast_forward');
            foreach ($m['changedFiles'] as $file => $hash) {
                requireGate(hash_equals($hash, hash_file('sha256', $m['app'].'/'.$file)),
                    'installed_source_hash', 'reviewed working-tree bytes', [], 'Preserve fence; inspect checkout conversion or file mutation.');
            }
            preserve($m);
        },
        'readiness' => static function () use ($m): void {
            run([PHP_BINARY, __DIR__.'/health.php', '--local'], $m, 'local_readiness_after_update');
            run([PHP_BINARY, __DIR__.'/standalone.php'], $m, 'final_design_before_traffic', 120);
        },
        'admit' => static fn () => run([PHP_BINARY, $m['app'].'/artisan', 'up', '--no-interaction'], $m, 'traffic_admission'),
        'verify' => static fn () => verifyLive($m),
    ]);

    return ['commit' => $m['target'], 'maintenanceOff' => true, 'databaseRestored' => false, 'migrations' => false, 'verifiedUtc' => gmdate('c')];
}
/** An exception stops at the actual failing step; no later mutation is attempted. */
function sequence(array $steps): void
{
    foreach ($steps as $name => $step) {
        $GLOBALS['np_activation_check'] = $name;
        $step();
    }
}
function recover(array $m): void
{
    sequence([
        'recovery_preflight' => static function () use ($m): void {
            requireGate(in_array(trim(\NovinPayReleaseV1\git($m['app'], ['rev-parse', 'HEAD'])), [$m['baseline'], $m['target']], true),
                'recovery_head', 'known baseline or target', [], 'Inspect source; do not force checkout.');
            preserve($m);
        },
        'recovery_fence' => static function () use ($m): void {
            $GLOBALS['np_fence_armed'] = true;
            fence($m);
            run([PHP_BINARY, __DIR__.'/fence-health-v1.php'], $m, 'recovery_fence', 120);
        },
        'recovery_source' => static function () use ($m): void {
            if (trim(\NovinPayReleaseV1\git($m['app'], ['rev-parse', 'HEAD'])) !== $m['baseline']) {
                run(['git', 'switch', '--detach', $m['baseline']], $m, 'code_only_recovery');
            }
            preserve($m);
            run([PHP_BINARY, __DIR__.'/health.php', '--local'], $m, 'recovered_local_readiness');
        },
        'recovery_admit' => static fn () => run([PHP_BINARY, $m['app'].'/artisan', 'up', '--no-interaction'], $m, 'recovery_traffic_admission'),
        'recovery_verify' => static fn () => verifyLive($m, true),
    ]);
    writeNew($m['stateDirectory'].'/recovery-'.gmdate('YmdHis').'.json', ['result' => 'CODE_ONLY_RECOVERY_PASS', 'commit' => $m['baseline'], 'databaseRestored' => false]);
}
