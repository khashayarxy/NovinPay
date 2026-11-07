<?php

declare(strict_types=1);

namespace NovinPay\Release\Backup;

use NovinPay\Release\GateFailure;

function runProcess(array $args, ?string $input, string $output, int $maxBytes = 67108864): void
{
    $error = OUT.'/private-process-error.txt';
    need(dirname($output) === OUT && ! is_link($output) && ! is_link($error), 'process_private_path');
    $process = proc_open($args, [0 => ['file', $input ?? (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'r'],
        1 => ['pipe', 'w'], 2 => ['file', $error, 'w']], $pipes, null, null, ['bypass_shell' => true]);
    need(is_resource($process), 'process_start_failed');
    $out = null;
    $exit = null;
    $observedExit = null;
    $written = 0;
    $deadline = microtime(true) + 1200;
    try {
        // The archive checker is intentionally reusable private scratch, not a backup or receipt.
        $scratch = basename($output) === 'archive-check.bin' && is_file($output) && ! is_link($output);
        $out = fopen($output, $scratch ? 'wb' : 'xb');
        need(is_resource($out) && chmod($output, 0600) && chmod($error, 0600), 'process_output_private');
        stream_set_blocking($pipes[1], false);
        do {
            $bytes = fread($pipes[1], 65536);
            need(is_string($bytes), 'process_read_failed');
            $written += strlen($bytes);
            need($written <= $maxBytes && fwrite($out, $bytes) === strlen($bytes), 'process_output_bound');
            $status = proc_get_status($process);
            if (! $status['running'] && $status['exitcode'] >= 0) {
                $observedExit = $status['exitcode'];
            }
            if (! $status['running'] && feof($pipes[1])) {
                $exit = $observedExit ?? $status['exitcode'];
                break;
            }
            need(microtime(true) < $deadline, 'native_process_timeout_preserve_partial_restore');
            usleep(10000);
        } while (true);
        need(fflush($out) && fsync($out), 'process_output_flush');
    } finally {
        if (is_resource($out)) {
            fclose($out);
        }
        fclose($pipes[1]);
        if ($exit === null) {
            proc_terminate($process);
        }
        $closed = proc_close($process);
    }
    $code = $exit !== null && $exit >= 0 ? $exit : $closed;
    if ($code !== 0) {
        throw new GateFailure('native_process_failed', 'reviewed native operation exits zero',
            ['exitCode' => $code, 'stderrPresent' => filesize($error) > 0], 'Preserve the private process log and partial artifacts; no blind retry.');
    }
}
