<?php

declare(strict_types=1);

namespace NovinPay\Release;

use Throwable;

require_once __DIR__.'/lib.php';

final class Pending extends \RuntimeException {}

function privateRead(string $path): string
{
    clearstatcache(true, $path);
    requireGate(is_file($path) && ! is_link($path), 'private_file_type', 'private regular file', [], 'Inspect the pinned path; do not follow links.');
    if (PHP_OS_FAMILY !== 'Windows') {
        requireGate(fileowner($path) === posix_geteuid() && (fileperms($path) & 0777) === 0600,
            'private_file_permissions', 'same UID and mode 0600', [], 'Inspect ownership; do not broaden permissions.');
    }

    return file_get_contents($path);
}

function writeNew(string $path, array $value): void
{
    requireGate(! file_exists($path) && ! is_link($path), 'receipt_exists', 'new receipt path', [], 'Preserve existing evidence and verify its postconditions.');
    $bytes = json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    $tmp = dirname($path).'/.write-'.bin2hex(random_bytes(12));
    $mask = umask(0077);
    try {
        $handle = fopen($tmp, 'xb');
    } finally {
        umask($mask);
    }
    try {
        requireGate(is_resource($handle) && chmod($tmp, 0600) && fwrite($handle, $bytes) === strlen($bytes)
            && fflush($handle) && fsync($handle), 'receipt_write', 'durable private receipt', [], 'Preserve the temporary receipt; inspect filesystem health.');
        fclose($handle);
        $handle = null;
        requireGate(rename($tmp, $path), 'receipt_publish', 'atomic receipt publication', [], 'Preserve journal files and reconcile before resuming.');
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }
}

/** Shared by recovery and the conventional source updater, never application requests. */
function operationLock(string $directory)
{
    $path = $directory.'/.operation.lock';
    requireGate(! is_link($path), 'operation_lock_type', 'regular private operation lock', [], 'Preserve the unexpected path.');
    if (file_exists($path)) {
        requireGate(is_file($path) && (PHP_OS_FAMILY === 'Windows' || (fileowner($path) === posix_geteuid() && (fileperms($path) & 0777) === 0600)),
            'operation_lock_permissions', 'same-owner private regular file', [], 'Inspect lock permissions without changing them.');
    }
    $mask = umask(0077);
    try {
        $handle = fopen($path, 'c+b');
    } finally {
        umask($mask);
    }
    requireGate(is_resource($handle) && flock($handle, LOCK_EX | LOCK_NB), 'operation_already_running', 'exclusive backup or release operation', [], 'Wait for the current owned operation; do not terminate it.');

    return $handle;
}

final class Journal
{
    private $lock;

    public function __construct(public readonly string $directory, private readonly string $identity)
    {
        requireGate(is_dir($directory) && ! is_link($directory), 'journal_directory', 'private existing directory', [], 'Inspect release directory.');
        $path = $directory.'/pipeline.lock';
        requireGate(! is_link($path), 'journal_lock_type', 'regular lock', [], 'Inspect release lock.');
        if (file_exists($path)) {
            privateRead($path);
        }
        $mask = umask(0077);
        try {
            $this->lock = fopen($path, 'c+b');
        } finally {
            umask($mask);
        }
        requireGate(is_resource($this->lock) && chmod($path, 0600) && flock($this->lock, LOCK_EX | LOCK_NB),
            'release_already_running', 'exclusive release ownership', [], 'Do not start a second release; inspect the existing operator.');
    }

    public function phase(string $name, callable $work, callable $verify): array
    {
        requireGate(preg_match('/\A[a-z_]+\z/', $name) === 1, 'phase_name', 'known phase', [], 'Inspect release manifest.');
        $start = $this->directory.'/'.$name.'.started.json';
        $end = $this->directory.'/'.$name.'.complete.json';
        if (file_exists($end)) {
            $receipt = json_decode(privateRead($end), true, 32, JSON_THROW_ON_ERROR);
            requireGate(($receipt['manifest'] ?? null) === $this->identity && ($receipt['phase'] ?? null) === $name && $verify($receipt['result']),
                'phase_postcondition_changed', 'completed phase still verifiable', ['phase' => $name], 'Preserve artifacts; reconcile changed evidence.');

            return $receipt['result'];
        }
        requireGate(! file_exists($start), 'interrupted_phase', 'no ambiguous prior mutation', ['phase' => $name],
            'Inspect the started receipt and actual postconditions; do not replay this phase blindly.');
        writeNew($start, ['manifest' => $this->identity, 'phase' => $name, 'startedUtc' => gmdate('c')]);
        try {
            $result = $work();
            requireGate(is_array($result) && $verify($result), 'phase_postcondition_failed', 'verified operation result', ['phase' => $name], 'Preserve artifacts; inspect the phase.');
            writeNew($end, ['manifest' => $this->identity, 'phase' => $name, 'result' => $result, 'completedUtc' => gmdate('c')]);

            return $result;
        } catch (Throwable $error) {
            writeNew($this->directory.'/'.$name.'.failed-'.bin2hex(random_bytes(6)).'.json',
                failureReport($error, $name, $GLOBALS['np_backup_check'] ?? $GLOBALS['np_activation_check'] ?? $name,
                    (bool) ($GLOBALS['np_fence_armed'] ?? false)));
            throw $error;
        }
    }

    public function close(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}

/** API secrets stay in the existing local DPAPI workflow. Each request is unique. */
function requestQuota(array $manifest, string $directory, string $purpose, int $required = 805306368): array
{
    $nonce = bin2hex(random_bytes(16));
    $request = ['format' => 1, 'nonce' => $nonce, 'phase' => $purpose, 'sourceCommit' => $manifest['baseline'],
        'targetCommit' => $manifest['target'], 'manifestSha256' => $manifest['_sha256'], 'createdAtUnix' => time(), 'requiredFreeBytes' => $required];
    $base = $directory.'/quota-'.$nonce;
    writeNew($base.'-request.json', $request);
    $deadline = microtime(true) + 180;
    do {
        clearstatcache();
        if (is_file($base.'-ready.json') && is_file($base.'-reply.json')) {
            $proof = quotaReply(privateRead($base.'-ready.json'), privateRead($base.'-reply.json'));
            if ($proof === null) {
                usleep(250000);

                continue;
            }
            quotaGate($proof, time(), $nonce, $manifest['baseline'], (int) disk_free_space('/home/novinpay'), $required);
            requireGate(($proof['manifestSha256'] ?? '') === $manifest['_sha256'] && ($proof['phase'] ?? '') === $purpose
                && $proof['observedAtUnix'] >= $request['createdAtUnix'] - 2, 'quota_request_binding', 'response to this exact request', [], 'Refresh only quota.');

            return $proof;
        }
        usleep(500000);
    } while (microtime(true) < $deadline);
    throw new Pending('quota_api_response_pending');
}

/** No raw argv/stderr is ever printed. Supports only caller-supplied reviewed argv. */
function command(array $argv, string $cwd, string $operation, int $timeout = 180): array
{
    $p = proc_open($argv, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
    requireGate(is_resource($p), $operation, 'native process starts', [], 'Inspect executable availability.');
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }
    $out = '';
    $err = '';
    $deadline = microtime(true) + $timeout;
    $exit = null;
    try {
        do {
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            requireGate(strlen($out) + strlen($err) <= 16777216, $operation, 'bounded process output', [], 'Inspect process output privately.');
            $status = proc_get_status($p);
            if (! $status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            requireGate(microtime(true) < $deadline, $operation, 'process finishes within its bound', ['timeoutSeconds' => $timeout], 'Inspect partial operation; do not retry a mutation.');
            usleep(50000);
        } while (true);
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
    } finally {
        if ($exit === null) {
            proc_terminate($p);
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $closed = proc_close($p);
    }
    $failureCode = null;
    if (preg_match('/"check"\s*:\s*"([a-z0-9_]+)"/', $err, $match)) {
        $failureCode = $match[1];
    }

    return ['exit' => $exit !== null && $exit >= 0 ? $exit : $closed, 'stdout' => $out, 'failureCode' => $failureCode, 'stderrCategory' => $err === '' ? 'none' : 'present_redacted'];
}
