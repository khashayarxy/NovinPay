<?php

declare(strict_types=1);

/** Pure reporting only. It cannot activate, cancel, migrate or remove a fence. */
function pr13LaunchResult(string $stdout, int $exit, array $manifest, string $pin, array $receipts): array
{
    $stop = static fn (string $check) => [['result' => 'PR13_ACTIVATION_LAUNCH_STOP', 'phase' => 'launch_result',
        'check' => $check, 'childExitCode' => $exit, 'retrySafe' => false, 'maintenanceMayBeActive' => true,
        'nextAction' => 'Preserve launch output and journal; reconcile exact phase. Do not rerun or remove fences.'], 1];
    if (trim($stdout) === '') {
        return $stop('child_result_missing');
    }
    if (strlen($stdout) > 65536) {
        return $stop('child_result_too_large');
    }
    $report = json_decode($stdout, true);
    if (! is_array($report)) {
        return $stop('child_result_invalid_json');
    }
    if (($report['result'] ?? '') === 'PR13_RELEASE_PASS') {
        $receipt = $receipts['activation.complete.json'] ?? [];
        if ($exit !== 0 || ($report['candidate'] ?? null) !== $manifest['candidate'] || ($report['migrations'] ?? null) !== 22
            || ($report['triggers'] ?? null) !== 13 || ($report['maintenanceEnabled'] ?? null) !== false
            || ($receipt['manifest'] ?? null) !== $pin || ($receipt['result'] ?? null) !== 'PR13_RELEASE_PASS'
            || ($receipt['candidate'] ?? null) !== $manifest['candidate'] || ($receipt['migrations'] ?? null) !== 22
            || ($receipt['triggers'] ?? null) !== 13 || ($receipt['maintenanceEnabled'] ?? null) !== false) {
            return $stop('release_result_receipt_mismatch');
        }

        return [['result' => 'PR13_RELEASE_PASS', 'candidate' => $manifest['candidate'], 'migrations' => 22, 'triggers' => 13,
            'maintenanceEnabled' => false, 'ownerAuthenticatedAcceptance' => 'pending'], 0];
    }
    if (($report['result'] ?? '') === 'PR13_ACTIVATION_VERIFICATION_PENDING') {
        if ($exit !== 75 || ($receipts['readiness.complete.json']['manifest'] ?? null) !== $pin
            || ($receipts['admission.started.json']['manifest'] ?? null) !== $pin) {
            return $stop('pending_result_receipt_mismatch');
        }
    } elseif (($report['result'] ?? '') !== 'PR13_ACTIVATION_STOP' || $exit !== 1) {
        return $stop('child_result_unexpected');
    }
    foreach (['phase', 'check', 'category'] as $key) {
        if (! is_string($report[$key] ?? null) || ! preg_match('/\A[a-z0-9_]{1,100}\z/D', $report[$key])) {
            return $stop('child_result_labels_invalid');
        }
    }
    if (isset($report['maintenanceMayBeActive']) && ! is_bool($report['maintenanceMayBeActive'])) {
        return $stop('child_result_labels_invalid');
    }

    // Never echo raw stdout/stderr or arbitrary exception fields to the terminal.
    $public = ['result' => $report['result'], 'phase' => $report['phase'], 'check' => $report['check'], 'category' => $report['category'],
        'childExitCode' => $exit, 'retrySafe' => false, 'maintenanceMayBeActive' => $report['maintenanceMayBeActive'] ?? true,
        'nextAction' => 'Preserve private output and journal. No replay; inspect the named check and exact phase.'];
    if (in_array($report['check'], ['cli_release_opcode_cache_must_be_off', 'live_child_release_opcode_cache_must_be_off'], true)) {
        foreach (['opcacheLoaded', 'opcacheApiAvailable', 'cliDirectiveAvailable', 'fileOnlyDirectiveAvailable',
            'fileCacheDirectiveAvailable', 'fileCacheConfigured', 'engineAbsent'] as $key) {
            if (is_bool($report['actual'][$key] ?? null)) {
                $public['runtime'][$key] = $report['actual'][$key];
            }
        }
    }

    return [$public, $exit];
}

if (realpath(($_SERVER['SCRIPT_FILENAME'] ?? '') ?: ($argv[0] ?? '')) !== __FILE__) {
    return;
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 5 || $argv[1] !== __DIR__.'/launch.stdout'
        || ! preg_match('/\A[0-9]{1,3}\z/D', $argv[2]) || ! preg_match('/\A[a-f0-9]{64}\z/D', $argv[4])
        || $argv[3] !== __DIR__.'/pr13-activation-72h-v4.json') {
        throw new RuntimeException('launch_report_scope');
    }
    $read = static function (string $path): string {
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600 || filesize($path) > 2097152) {
            throw new RuntimeException('launch_report_file');
        }

        return file_get_contents($path);
    };
    $text = $read($argv[3]);
    if (! hash_equals($argv[4], hash('sha256', $text))) {
        throw new RuntimeException('launch_report_manifest');
    }
    $m = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    if ($m['toolDirectory'] !== __DIR__ || $m['stateDirectory'] !== dirname(__DIR__).'/pipeline-pr13-activation-r4'
        || ! hash_equals($m['tools']['pr13-launch-result.php'], hash_file('sha256', __FILE__))) {
        throw new RuntimeException('launch_report_manifest_scope');
    }
    $receipts = [];
    foreach (['activation.complete.json', 'readiness.complete.json', 'admission.started.json'] as $file) {
        $path = $m['stateDirectory'].'/'.$file;
        if (file_exists($path) || is_link($path)) {
            $receipts[$file] = json_decode($read($path), true, flags: JSON_THROW_ON_ERROR);
        }
    }
    [$report, $exit] = pr13LaunchResult($read($argv[1]), (int) $argv[2], $m, $argv[4], $receipts);
    echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
    exit($exit);
} catch (Throwable) {
    echo '{"result":"PR13_ACTIVATION_LAUNCH_STOP","phase":"launch_result","check":"launch_report_validation_failed","retrySafe":false,"maintenanceMayBeActive":true}'.PHP_EOL;
    exit(1);
}
