<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use NovinPay\Release\GateFailure;

use function NovinPay\Release\privateRead;
use function NovinPay\Release\recoveryGit;
use function NovinPay\Release\requireGate;
use function NovinPay\Release\sourceRecoveryEvidence;
use function NovinPay\Release\sourceReleaseManifest;
use function NovinPay\Release\sourceTriggerEvidence;

const ROOT = '/home/novinpay/novinpay';
const KIT = '/home/novinpay/novinpay-direct-pr13';

ini_set('display_errors', '0');
ini_set('log_errors', '0');

function expect(bool $condition, string $check): void
{
    requireGate($condition, $check, 'stopped before any production mutation', [], 'Preserve the existing attempt and reconcile the indicated evidence.');
}

try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || posix_geteuid() !== 1321 || count($argv) !== 4) {
        throw new RuntimeException;
    }
    [$script, $manifestPath, $pin, $nonce] = $argv;
    $release = basename($manifestPath, '.json');
    if ($manifestPath !== KIT.'/'.$release.'.json' || ! preg_match('/\A[a-z0-9-]{1,64}\z/', $release)
        || ! preg_match('/\A[a-f0-9]{64}\z/', $pin) || ! preg_match('/\A[a-f0-9]{32}\z/', $nonce)) {
        throw new RuntimeException;
    }
    require KIT.'/tools-'.$release.'/recovery-contract.php';
    $raw = privateRead($manifestPath);
    expect(hash_equals($pin, hash('sha256', $raw)), 'manifest_pin');
    $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    sourceReleaseManifest($manifest);
    expect($manifest['format'] === 'novinpay-application-release-v1' && $manifest['releaseName'] === $release, 'inspector_manifest_format');

    $lockPath = KIT.'/.operation.lock';
    expect(is_file($lockPath) && ! is_link($lockPath) && fileowner($lockPath) === 1321 && (fileperms($lockPath) & 0777) === 0600, 'operation_lock_identity');
    $lock = fopen($lockPath, 'rb');
    expect(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'operation_already_running');
    $request = KIT.'/quota-'.$nonce;
    $q = json_decode(privateRead($request.'-request.json'), true, 16, JSON_THROW_ON_ERROR);
    expect(($q['nonce'] ?? null) === $nonce && ($q['phase'] ?? null) === 'fence' && ($q['manifestSha256'] ?? null) === $pin
        && ($q['sourceCommit'] ?? null) === $manifest['baseline'] && ($q['targetCommit'] ?? null) === $manifest['target']
        && is_int($q['createdAtUnix'] ?? null) && time() - $q['createdAtUnix'] > 180, 'stopped_request_binding');
    foreach (['reply', 'ready'] as $suffix) {
        expect(! file_exists($request.'-'.$suffix.'.json') && ! is_link($request.'-'.$suffix.'.json'), 'late_quota_response_exists');
    }

    $attempt = KIT.'/'.$manifest['releaseName'].'-attempt';
    expect(is_dir($attempt) && ! is_link($attempt) && fileowner($attempt) === 1321 && (fileperms($attempt) & 0777) === 0700, 'attempt_directory_identity');
    $expected = ['added-source-files.txt', 'config-before.php', 'migration-paths.txt', 'migrations', 'migrations.tar', 'routes-before.php'];
    $entries = array_values(array_diff(scandir($attempt), ['.', '..']));
    expect($entries === $expected, 'attempt_contains_mutating_phase_evidence');
    expect(hash_equals($manifest['baselineFiles']['bootstrap/cache/config.php'], hash('sha256', privateRead($attempt.'/config-before.php')))
        && hash_equals($manifest['baselineFiles']['bootstrap/cache/routes-v7.php'], hash('sha256', privateRead($attempt.'/routes-before.php'))), 'attempt_cache_identity');
    expect(trim(recoveryGit(ROOT, ['rev-parse', 'HEAD'])) === $manifest['baseline']
        && trim(recoveryGit(ROOT, ['diff', '--cached', '--name-only'])) === '', 'production_source_identity');
    foreach (['storage/framework/down', 'storage/framework/maintenance.php'] as $path) {
        expect(! file_exists(ROOT.'/'.$path) && ! is_link(ROOT.'/'.$path), 'production_maintenance_state');
    }
    foreach ($manifest['baselineFiles'] as $path => $hash) {
        expect(is_file(ROOT.'/'.$path) && ! is_link(ROOT.'/'.$path) && hash_equals($hash, hash_file('sha256', ROOT.'/'.$path)), 'production_baseline_file_identity');
    }

    require ROOT.'/vendor/autoload.php';
    $app = require ROOT.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    expect(config('payments.zibal.enabled') === false && config('payments.zibal.live_requests') === false
        && config('operations.scheduled_payments', false) === false && config('app.debug') === false, 'production_payment_flags');
    $db = DB::connection()->getPdo();
    $identity = $db->query('SELECT DATABASE(), CURRENT_USER(), VERSION()')->fetch(PDO::FETCH_NUM);
    expect($identity[0] === 'novinpay_live1' && $identity[1] === 'novinpay_app1@localhost' && str_starts_with($identity[2], '10.6.28'), 'production_database_identity');
    expect($db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN) === $manifest['migrations'], 'production_migration_ledger');
    expect((int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('shopping_carts','legacy_cart_holds','cart_purchase_snapshots')")->fetchColumn() === 0, 'production_cart_schema');
    sourceTriggerEvidence($manifest);
    sourceRecoveryEvidence($manifest);

    echo json_encode(['result' => 'STOPPED_SOURCE_RECONCILIATION_PASS', 'productionHead' => $manifest['baseline'], 'manifestSha256' => $pin,
        'migrations' => count($manifest['migrations']), 'triggers' => $manifest['expectedTriggers'], 'cartTablesAbsent' => true,
        'operationLockAvailable' => true, 'maintenanceActive' => false, 'paymentFlagsOff' => true,
        'oldAttemptPreserved' => true, 'qualifiedNewAttemptRequired' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['result' => 'STOPPED_SOURCE_RECONCILIATION_STOP', 'check' => $error instanceof GateFailure ? $error->check : 'inspector_runtime',
        'retrySafe' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
