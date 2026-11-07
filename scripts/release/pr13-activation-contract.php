<?php

declare(strict_types=1);

namespace NovinPay\Release\Pr13;

use NovinPay\Release\GateFailure;
use RuntimeException;

function need(bool $ok, string $check): void
{
    if (! $ok) {
        throw new RuntimeException($check);
    }
}

/** Resolve before children change cwd; never follow an operator-supplied symlink. */
function manifestPath(string $input, string $tools): string
{
    need(! is_link($input) && is_file($input), 'activation_manifest_path');
    $path = realpath($input);
    need(is_string($path) && realpath(dirname($path)) === realpath($tools) && str_ends_with($path, '.json'), 'activation_manifest_directory');

    return $path;
}

/** No time-based inference: success requires observable absence of PHP writers. */
function waitForDrain(callable $sample, callable $pause, callable $clock, float $deadline): array
{
    $empty = 0;
    do {
        $state = $sample();
        need(($state['observable'] ?? false) === true, 'drain_visibility_unavailable');
        need(is_int($state['phpProcesses'] ?? null) && is_int($state['otherDatabaseConnections'] ?? null), 'drain_sample_invalid');
        $empty = $state['phpProcesses'] === 0 && $state['otherDatabaseConnections'] === 0 ? $empty + 1 : 0;
        if ($empty === 2) {
            return ['phpProcesses' => 0, 'otherDatabaseConnections' => 0, 'consecutiveEmptySamples' => 2];
        }
        need($clock() < $deadline, 'drain_not_established_no_ddl');
        $pause();
    } while (true);
}

/** Require complete /proc visibility, including same-UID CLI/queue workers. */
function phpProcesses(string $proc, int $uid, int $self): int
{
    need(is_dir($proc) && is_readable($proc.'/self/status'), 'drain_proc_unavailable');
    $count = 0;
    $entries = glob($proc.'/[0-9]*');
    need(is_array($entries), 'drain_proc_inventory');
    foreach ($entries as $entry) {
        if ((int) basename($entry) === $self) {
            continue;
        }
        $status = @file_get_contents($entry.'/status');
        if ($status === false) {
            clearstatcache(true, $entry);
            need(! is_dir($entry), 'drain_process_identity_unreadable');

            continue; // A process exited between enumeration and inspection.
        }
        if (effectiveProcessUid($status) !== $uid) {
            continue;
        }
        // /proc entries change independently of PHP's stat/realpath caches.
        $exe = @readlink($entry.'/exe');
        if ($exe === false) {
            $exe = executableAfterMiss($entry, $status, $uid);
            if ($exe === null) {
                continue;
            }
        }
        $name = strtolower(basename(str_replace(' (deleted)', '', $exe)));
        if (str_starts_with($name, 'lsphp') || str_starts_with($name, 'php')) {
            $count++;
        }
    }

    return $count;
}

/** A missed executable is not absence: re-observe the process before deciding. */
function executableAfterMiss(string $entry, string $before, int $uid): ?string
{
    clearstatcache(true, $entry);
    clearstatcache(true, $entry.'/exe');
    $after = @file_get_contents($entry.'/status');
    if ($after === false) {
        clearstatcache(true, $entry);
        need(! is_dir($entry), 'drain_process_identity_unreadable');

        return null; // Its disappearance was freshly established, not cached.
    }
    need(effectiveProcessUid($after) === $uid, 'drain_process_identity_changed');
    if (preg_match('/^State:\s+Z/m', $after) === 1) {
        return null; // Fresh zombie state proves this process can no longer write.
    }
    error_clear_last();
    $exe = @readlink($entry.'/exe');
    if ($exe !== false) {
        return $exe;
    }
    $error = error_get_last()['message'] ?? '';
    $state = static function (string $status): ?string {
        return preg_match('/^State:\s+([A-Z])/m', $status, $match) === 1 ? $match[1] : null;
    };
    throw new GateFailure('drain_executable_unreadable', 'readable executable or freshly proven exited/zombie process', [
        'pid' => (int) basename($entry), 'effectiveUid' => $uid,
        'stateBefore' => $state($before), 'stateAfter' => $state($after),
        'readlinkError' => preg_match('/permission denied|operation not permitted/i', $error) ? 'access_denied'
            : (str_contains(strtolower($error), 'no such file') ? 'not_found' : 'unclassified'),
    ], 'Preserve fences; inspect process visibility. Never ignore a live unreadable process or replay activation.');
}

function effectiveProcessUid(string $status): int
{
    // /proc exposes real/effective/saved/fs UIDs. A setuid system helper may
    // retain our real UID while executing as root; it is not an account worker.
    need(preg_match('/^Uid:\s+\d+\s+(\d+)\s+\d+\s+\d+/m', $status, $match) === 1, 'drain_process_uid');

    return (int) $match[1];
}

function processStartTicks(string $stat): string
{
    $end = strrpos($stat, ') ');
    need($end !== false, 'process_start_metadata');
    $fields = preg_split('/\s+/', trim(substr($stat, $end + 2)));
    need(isset($fields[19]) && ctype_digit($fields[19]), 'process_start_metadata');

    return $fields[19]; // Field22; suffix begins with field3 (process state).
}

/** Only the already reviewed, fenced Artisan scheduler may launch account jobs. */
function cronIsFenced(string $text): bool
{
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'SHELL=')) {
            if (! in_array($line, ['SHELL=/bin/sh', 'SHELL=/bin/bash'], true)) {
                return false;
            }

            continue;
        }
        if ($line === '' || str_starts_with($line, '#') || preg_match('/\A(?:PATH|MAILTO|HOME)=/', $line)) {
            continue;
        }
        if (! preg_match('~\A(?:[0-9*/,-]+\s+){5}/opt/cpanel/ea-php84/root/usr/bin/php\s+/home/novinpay/novinpay/artisan\s+schedule:run(?:\s+>>?\s*/dev/null)?(?:\s+2>&1)?\z~D', $line)) {
            return false;
        }
    }

    return true;
}

function webFence(): string
{
    return <<<'PHP'
<?php
http_response_code(503);
header('Retry-After: 60');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    if (str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'json')) {
        header('Content-Type: application/json; charset=UTF-8');
        echo '{"message":"Service Unavailable"}';
    } else {
        require dirname(__DIR__, 2).'/public/maintenance.php';
    }
}
exit;
PHP;
}

function opcodeProbeSource(array $paths, string $tokenHash): string
{
    need(preg_match('/\A[a-f0-9]{64}\z/D', $tokenHash) === 1, 'probe_token_hash');

    return '<?php ini_set("display_errors","0"); if (!hash_equals('.var_export($tokenHash, true).',hash("sha256",$_SERVER["HTTP_X_NP_RELEASE"]??""))) {http_response_code(404);exit;}'
        .'header("Cache-Control: no-store");header("Content-Type: application/json");$ok=PHP_VERSION_ID>=80401&&PHP_SAPI==="litespeed"&&posix_geteuid()===1321;'
        .'foreach(["flock","fsync","imagewebp","finfo_open"] as $fn){$ok=$ok&&function_exists($fn);}'
        .'$s=@file_get_contents("/proc/self/stat");$e=is_string($s)?strrpos($s,") "):false;$f=$e===false?[]:preg_split("/\\s+/",trim(substr($s,$e+2)));$ok=$ok&&isset($f[19])&&ctype_digit($f[19]);'
        .'if(ini_get("opcache.enable")){$ok=$ok&&ini_get("opcache.file_cache")===""&&function_exists("opcache_get_status")&&function_exists("opcache_invalidate")&&function_exists("opcache_is_script_cached");if($ok){$ok=is_array(opcache_get_status(false));}}'
        .'foreach ('.var_export(array_values($paths), true).' as $p) {if($ok&&ini_get("opcache.enable")&&opcache_is_script_cached($p)&&!opcache_invalidate($p,true)){$ok=false;break;}}'
        .'http_response_code($ok?200:503);echo json_encode(["result"=>$ok?"PR13_WEB_OPCODE_PASS":"STOP","pid"=>getmypid(),"startTicks"=>$f[19]??null]);';
}

function cliFence(): string
{
    return "#!/usr/bin/env php\n<?php\nfwrite(STDERR, \"PR13_DEPLOYMENT_WRITE_FENCE: command not executed; preserve release journal.\\n\");\nexit(75);\n";
}

function recoveryAction(bool $ddlStarted, bool $admissionStarted): string
{
    return $ddlStarted || $admissionStarted ? 'keep_both_fences_reconcile_forward_no_database_restore' : 'verified_baseline_cancellation_only';
}

function enabledChatEnvironment(string $env): string
{
    need(! str_contains($env, "\0"), 'environment_invalid');
    preg_match_all('/^SUPPORT_CHAT_ENABLED=.*$/m', $env, $matches);
    need(count($matches[0]) <= 1, 'duplicate_chat_configuration');
    if ($matches[0] !== []) {
        need(preg_match('/\ASUPPORT_CHAT_ENABLED=(?:true|false|"true"|"false")\r?\z/D', $matches[0][0]) === 1, 'unexpected_chat_configuration');

        return preg_replace('/^SUPPORT_CHAT_ENABLED=.*$/m', 'SUPPORT_CHAT_ENABLED=true', $env, 1);
    }

    return $env.(str_ends_with($env, "\n") ? '' : "\n")."SUPPORT_CHAT_ENABLED=true\n";
}

/** A metadata-only read from the already authenticated backup; nothing extracted. */
function backupMetadata(string $gzip): array
{
    $tar = gzdecode($gzip, 268435456);
    need(is_string($tar), 'backup_metadata_gzip');
    $result = null;
    for ($offset = 0; $offset + 512 <= strlen($tar);) {
        $header = substr($tar, $offset, 512);
        if ($header === str_repeat("\0", 512)) {
            break;
        }
        $sizeText = trim(substr($header, 124, 12), " \0");
        need(preg_match('/\A[0-7]+\z/D', $sizeText) === 1, 'backup_metadata_member_size');
        $size = octdec($sizeText);
        need($offset + 512 + $size <= strlen($tar), 'backup_metadata_truncated');
        if (rtrim(substr($header, 0, 100), "\0") === 'release-metadata.json') {
            need($result === null && $size <= 8388608 && in_array($header[156], ["\0", '0'], true), 'backup_metadata_member');
            $result = json_decode(substr($tar, $offset + 512, $size), true, flags: JSON_THROW_ON_ERROR);
        }
        $offset += 512 + (int) (ceil($size / 512) * 512);
    }
    need(is_array($result), 'backup_metadata_missing');

    return $result;
}
