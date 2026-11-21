<?php

declare(strict_types=1);

namespace NovinPay\Release;

/** Fixed production adapter: never accept manifest-controlled roots or PHP paths. */
function runtimeHost(array $m, string $manifestPath, string $pin): array
{
    requireGate(($m['activationReady'] ?? false) === true, 'runtime_package_not_qualified', 'independently qualified activation package', [], 'Complete isolated qualification and exact manifest binding first.');
    requireGate(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && posix_geteuid() === 1321
        && ! filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN)
        && ! filter_var(ini_get('opcache.file_cache_only'), FILTER_VALIDATE_BOOLEAN)
        && (string) ini_get('opcache.file_cache') === '',
        'runtime_cli_cache', 'explicit PHP 8.4 CLI without persistent opcode caching', [], 'Use the reviewed invocation-level settings.');
    $app = '/home/novinpay/novinpay';
    $kit = '/home/novinpay/novinpay-direct-pr13';
    requireGate(realpath($manifestPath) === $kit.'/'.$m['releaseName'].'.json', 'runtime_manifest_location', 'exact private release manifest', [], 'Reject unexpected paths.');
    $php = '/opt/cpanel/ea-php84/root/usr/bin/php';
    $args = [$php, '-d', 'session.gc_divisor=100', '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'opcache.enable_cli=0', '-d', 'opcache.file_cache_only=0', '-d', 'opcache.file_cache='];
    $check = function (string $action) use ($args, $manifestPath, $pin, $app): void {
        $result = command(array_merge($args, [__DIR__.'/activate-source.php', $manifestPath, $pin, $action]), $app, 'runtime_'.$action);
        requireGate($result['exit'] === 0, 'runtime_'.$action, 'runtime gate passes', [], 'Inspect the child result privately; do not retry activation.');
    };
    $artisan = function (array $command) use ($args, $app): void {
        $result = command(array_merge($args, [$app.'/artisan'], $command, ['--no-interaction']), $app, 'runtime_artisan');
        requireGate($result['exit'] === 0, 'runtime_artisan', 'reviewed cache or maintenance command succeeds', [], 'Inspect the journal; never retry blindly.');
    };

    return runtimeActivate($app, $kit, '/home/novinpay/public_html', $m, [
        'preflight' => function () use ($m, $check): void {
            $proof = json_decode(privateRead($m['recoveryProof']['path']), true, 32, JSON_THROW_ON_ERROR);
            requireGate(($proof['sourceCommit'] ?? '') === $m['baseline'], 'runtime_recovery_baseline', 'recovery point matches baseline', [], 'Reconcile recovery evidence.');
            $check('preflight');
            $check('web-check'); // Independently verify web PHP before maintenance or switching.
        },
        'quota' => function () use ($m, $kit): void {
            $count = array_sum(array_map(fn ($a) => count($a['files']), $m['archives']));
            $proof = requestQuota($m, $kit, 'fence', 805306368);
            runtimeInodes($proof, $count * 3);
            $disk = command(['df', '-Pi', '/home/novinpay'], '/home/novinpay', 'runtime_physical_inodes');
            $lines = preg_split('/\r?\n/', trim($disk['stdout']));
            $fields = preg_split('/\s+/', $lines[count($lines) - 1]);
            requireGate($disk['exit'] === 0 && isset($fields[3]) && ctype_digit($fields[3]) && (int) $fields[3] >= $count * 3 + 4096,
                'runtime_physical_inode_headroom', 'physical inode reserve in addition to account quota', [], 'Inspect actual filesystem capacity.');
        },
        'down' => function () use ($artisan): void {
            $artisan(['down', '--render=errors::503', '--retry=60']);
            $c = curl_init('https://novinpaypos.com/login');
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
            curl_exec($c);
            $ok = curl_errno($c) === 0 && curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 503;
            curl_close($c);
            requireGate($ok, 'runtime_maintenance_https', 'public traffic fenced by Laravel', [], 'Keep maintenance and reconcile routing.');
        },
        // Preserve cached production configuration byte-for-byte. Route and view
        // compilation use the newly installed vendor in separate CLI processes.
        'cache' => function () use ($artisan): void {
            $artisan(['route:cache']);
            $artisan(['view:cache']);
        },
        'web' => fn () => $check('web-cache'),
        'ready' => fn () => $check('ready'),
        'baselineReady' => fn () => $check('ready'),
        'up' => fn () => $artisan(['up']),
        'smoke' => fn () => $check('smoke'),
    ]);
}
