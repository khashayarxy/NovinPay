<?php

declare(strict_types=1);

namespace NovinPay\Release\Pr13;

use NovinPay\Release\GateFailure;
use Throwable;

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/pr13-activation-contract.php';

/** Sanitized /proc observations only: never cmdline, environment, exe paths or SQL. */
function observeDrain(string $proc, int $uid, int $self): array
{
    $failures = [];
    $count = null;
    try {
        $count = phpProcesses($proc, $uid, $self);
    } catch (Throwable $error) {
        $report = \NovinPay\Release\failureReport($error, 'readonly_process_visibility', 'process_observation');
        $failures[] = ['check' => $report['check'], 'actual' => $error instanceof GateFailure ? $error->actual : []];
    }
    $misses = [];
    foreach (glob($proc.'/[0-9]*') ?: [] as $entry) {
        if ((int) basename($entry) === $self) {
            continue;
        }
        $status = @file_get_contents($entry.'/status');
        if (! is_string($status) || ! preg_match('/^Uid:\s+\d+\s+(\d+)\s+\d+\s+\d+/m', $status, $match) || (int) $match[1] !== $uid) {
            continue;
        }
        clearstatcache(true, $entry.'/exe');
        $link = is_link($entry.'/exe');
        error_clear_last();
        $exe = @readlink($entry.'/exe');
        if ($link && $exe !== false) {
            continue;
        }
        $error = error_get_last()['message'] ?? '';
        $after = @file_get_contents($entry.'/status');
        clearstatcache(true, $entry);
        $state = static fn (string $text) => preg_match('/^State:\s+([A-Z])/m', $text, $m) ? $m[1] : null;
        $misses[] = ['pid' => (int) basename($entry), 'effectiveUid' => $uid,
            'stateBefore' => $state($status), 'stateAfter' => is_string($after) ? $state($after) : null,
            'directoryStillExists' => is_dir($entry), 'isLink' => $link, 'directReadSucceeded' => $exe !== false,
            'readlinkError' => preg_match('/permission denied|operation not permitted/i', $error) ? 'access_denied'
                : (str_contains(strtolower($error), 'no such file') ? 'not_found' : ($exe !== false ? null : 'unclassified'))];
        if (count($misses) >= 16) {
            break;
        }
    }

    return ['phpProcesses' => $count, 'failures' => $failures, 'executableMisses' => $misses];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    need(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && function_exists('posix_geteuid') && posix_geteuid() === 1321, 'observer_host_identity');
    $samples = [];
    for ($i = 0; $i < 12; $i++) {
        $samples[] = observeDrain('/proc', 1321, getmypid());
        if ($i < 11) {
            usleep(200000);
        }
    }
    echo json_encode(['result' => 'PR13_READONLY_DRAIN_OBSERVATION', 'phpVersion' => PHP_VERSION,
        'openBasedirSet' => ini_get('open_basedir') !== '', 'samples' => $samples,
        'databaseAccessed' => false, 'productionModified' => false, 'activationRetried' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(\NovinPay\Release\failureReport($error, 'readonly_process_visibility', 'observer_identity')).PHP_EOL;
    exit(1);
}
