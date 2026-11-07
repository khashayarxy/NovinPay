<?php

declare(strict_types=1);

namespace NovinPay\Release\Pr13;

use NovinPay\Release\GateFailure;
use NovinPay\Release\Journal;
use NovinPay\Release\Pending;
use PDO;
use Throwable;

use function NovinPay\Release\command;
use function NovinPay\Release\privateRead;
use function NovinPay\Release\writeNew;

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/pr13-activation-contract.php';
require_once __DIR__.'/pr13-schema-contract.php';

const APP = '/home/novinpay/novinpay';
const KIT = '/home/novinpay/novinpay-release-pipeline-v1';
const STATE = KIT.'/pipeline-pr13-activation-r1';

function run(array $args, string $cwd = APP, int $seconds = 120): string
{
    $operation = $args[0] === 'git' ? match ($args[1] ?? '') {
        'fetch' => 'git_fetch_reviewed_candidate', 'merge' => 'git_source_fast_forward', 'merge-base' => 'git_candidate_ancestry',
        'status' => 'git_preserved_worktree', 'diff' => 'git_staged_identity', 'show' => 'git_original_entry', default => 'git_commit_identity',
    } : 'live_candidate_child';
    $result = command($args, $cwd, $operation, $seconds);
    if ($result['exit'] !== 0) {
        // Only fixed check identifiers from child JSON; never raw stdout/stderr.
        $child = json_decode(trim($result['stdout']), true);
        $check = $child['check'] ?? null;
        throw new GateFailure(is_string($check) && preg_match('/\A[a-z0-9_]+\z/D', $check) ? $check : $operation,
            'reviewed operation exits zero', ['operation' => $operation, 'exitCode' => $result['exit'], 'stderrCategory' => $result['stderrCategory']],
            'Inspect this phase and its private receipts; never replay a mutation or print raw process output.');
    }

    return $result['stdout'];
}

function replaceOwned(string $path, string $oldHash, string $bytes): void
{
    need(is_file($path) && ! is_link($path) && hash_equals($oldHash, hash_file('sha256', $path)), 'replace_original_identity');
    $mode = fileperms($path) & 0777;
    $tmp = dirname($path).'/.pr13-'.bin2hex(random_bytes(12));
    $f = fopen($tmp, 'xb');
    try {
        need(is_resource($f) && chmod($tmp, $mode) && fwrite($f, $bytes) === strlen($bytes) && fflush($f) && fsync($f), 'replacement_write');
    } finally {
        if (is_resource($f)) {
            fclose($f);
        }
    }
    need(rename($tmp, $path), 'replacement_publish');
}

function triggerRows(PDO $pdo): array
{
    $rows = $pdo->query('SELECT TRIGGER_NAME name,EVENT_OBJECT_TABLE tableName,EVENT_MANIPULATION eventName,ACTION_TIMING timing,ACTION_STATEMENT body,DEFINER definer FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['bodySha256'] = hash('sha256', $row['body']);
        unset($row['body']);
    }

    return $rows;
}

function https(string $path, array $expected, bool $head = false): array
{
    $headers = [];
    $curl = curl_init('https://novinpaypos.com'.$path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => $head, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 40,
        CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$headers) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        }]);
    try {
        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    } finally {
        curl_close($curl);
    }
    need($status === 0 || in_array($status, $expected, true), 'https_response_unexpected');
    if ($errno !== 0 && in_array($errno, [6, 7, 18, 28, 52, 55, 56], true)) {
        throw new Pending('https_transport_verification_pending');
    }
    need($errno === 0 && is_string($body) && in_array($status, $expected, true), 'https_security_or_client_failure');
    need(! preg_match('~(?:SUPPORT_(?:PUBLIC_ROOT|CORE_DIR|INSTALLATION_ID)|CHAT_DATA_DIR|APP_KEY)\s*=|/home/novinpay/~', $body), 'https_configuration_disclosure');

    return [$headers, $body];
}

function webOpcodeCheck(array $paths): array
{
    $token = bin2hex(random_bytes(32));
    $name = '_np_pr13_cache_'.bin2hex(random_bytes(12)).'.php';
    $file = APP.'/public/'.$name;
    $source = opcodeProbeSource($paths, hash('sha256', $token));
    $handle = fopen($file, 'xb');
    try {
        need(is_resource($handle) && chmod($file, 0600) && fwrite($handle, $source) === strlen($source) && fflush($handle) && fsync($handle), 'opcode_probe_create');
        fclose($handle);
        $handle = null;
        $curl = curl_init('https://novinpaypos.com/'.$name);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['X-NP-Release: '.$token], CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60]);
        try {
            $response = curl_exec($curl);
            need(curl_errno($curl) === 0 && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200
                && is_string($response) && (json_decode($response, true)['result'] ?? '') === 'PR13_WEB_OPCODE_PASS', 'web_opcode_not_verified');
        } finally {
            curl_close($curl);
        }
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        need(is_file($file) && ! is_link($file) && hash_equals(hash('sha256', $source), hash_file('sha256', $file)) && unlink($file), 'opcode_probe_cleanup_required');
    }

    return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
$phase = 'identity';
$armed = false;
$finished = false;
$journal = null;
register_shutdown_function(static function () use (&$finished, &$phase, &$armed): void {
    if (! $finished) {
        echo json_encode(['result' => 'PR13_ACTIVATION_INTERRUPTED', 'phase' => $phase, 'fenceMayBeActive' => $armed, 'retrySafe' => false, 'nextAction' => 'Preserve journal. Reconcile actual schema and both fences; do not replay.']).PHP_EOL;
    }
});
try {
    need(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && function_exists('posix_geteuid') && posix_geteuid() === 1321
        && __DIR__ === KIT && count($argv) === 3, 'activation_scope');
    $text = privateRead($argv[1]);
    need(hash_equals($argv[2], hash('sha256', $text)), 'manifest_pin');
    $m = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    $m['_sha256'] = $argv[2];
    need(ini_get('memory_limit') === '-1' || ini_set('memory_limit', '512M') !== false, 'bounded_recovery_memory');
    need($m['mode'] === 'pr13-migration-activation-v1' && $m['stateDirectory'] === STATE
        && $m['baseline'] === '1f39bb476fd31880600564e58599c4488622c4c0' && $m['candidate'] === 'ded4e16302770f28f7bfe8e567e9a2de2314f99d'
        && $m['backup']['directory'] === '/home/novinpay/novinpay-private-backups/maintenance-pr13-v1'
        && $m['backup']['restoreDatabase'] === 'novinpay_restore13r1', 'activation_manifest_scope');
    foreach ($m['tools'] as $file => $sha) {
        need(preg_match('/\A[a-z0-9-]+\.php\z/D', $file) === 1 && hash_equals($sha, hash('sha256', privateRead(KIT.'/'.$file))), 'activation_tool_hash');
    }
    foreach (['sourceArchive', 'vendorArchive', 'frontendArchive'] as $key) {
        need(preg_match('/\Apr13-[a-z0-9.-]+\.zip\z/D', $m[$key]['name']) === 1
            && ! is_link(KIT.'/'.$m[$key]['name']) && hash_equals($m[$key]['sha256'], hash_file('sha256', KIT.'/'.$m[$key]['name'])), 'activation_artifact_hash');
    }
    $proof = json_decode(privateRead(KIT.'/pr13-offhost-proof-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    need(hash_equals($m['offhostProofSha256'], hash_file('sha256', KIT.'/pr13-offhost-proof-v1.json'))
        && $proof['archiveSha256'] === '45c4300aaeaeab217e75f793ad576f9631dff91f71fae127bd30be6edc0b1748'
        && hash_equals($proof['archiveSha256'], hash_file('sha256', $m['backup']['directory'].'/backup.npb')), 'independent_recovery_link');
    $fresh = static function () use ($proof): void {
        \NovinPay\Release\recoveryGate($proof, ['automaticDatabaseRestore' => false, 'maxSnapshotAgeSeconds' => 14400], time());
    };
    $fresh();
    foreach ($m['recoveryReceipts'] as $file => $sha) {
        need(in_array($file, ['backup.complete.json', 'migration_rehearsal.complete.json'], true)
            && hash_equals($sha, hash('sha256', privateRead(KIT.'/pipeline-pr13-r2/'.$file))), 'recovery_receipt_pin');
        $r = json_decode(privateRead(KIT.'/pipeline-pr13-r2/'.$file), true, flags: JSON_THROW_ON_ERROR);
        need($r['manifest'] === $m['recoveryManifestSha256'], 'recovery_manifest_link');
    }
    $receipt = json_decode(privateRead(KIT.'/pipeline-pr13-r2/backup.complete.json'), true, flags: JSON_THROW_ON_ERROR)['result'];
    require KIT.'/pr13-backup-v1.php';
    $key = base64_decode(trim(privateRead('/home/novinpay/.novinpay-maint10-key')), true);
    need(is_string($key) && strlen($key) === 32, 'existing_recovery_key');
    $plain = \NovinPay\Release\Backup\decryptBundle(file_get_contents($m['backup']['directory'].'/backup.npb'), $key);
    need(hash_equals($receipt['decryptedTarSha256'], hash('sha256', $plain)), 'backup_authenticated_metadata_link');
    $metadata = backupMetadata($plain);
    unset($plain, $key);
    need($metadata['sourceCommit'] === $m['baseline'] && $metadata['protectedFileHashes'] == $proof['protectedFileHashes'], 'backup_metadata_identity');
    need(is_link('/home/novinpay/public_html') && readlink('/home/novinpay/public_html') === APP.'/public'
        && trim(run(['git', 'rev-parse', 'HEAD'])) === $m['baseline'], 'production_identity');
    $status = run(['git', 'status', '--porcelain=v1', '--untracked-files=all']);
    $entries = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $status)), fn ($line) => $line !== ''));
    sort($entries);
    $expected = $m['preservedStatus'];
    sort($expected);
    need($entries === $expected && trim(run(['git', 'diff', '--cached', '--name-only'])) === '', 'production_preserved_worktree');
    need(! file_exists(APP.'/storage/framework/down') && ! file_exists(APP.'/storage/framework/maintenance.php'), 'no_unexplained_fence');
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APP.'/public', \FilesystemIterator::SKIP_DOTS)) as $entry) {
        if ($entry->isFile() && str_ends_with(strtolower($entry->getFilename()), '.php')) {
            need(! $entry->isLink() && in_array($entry->getPathname(), [APP.'/public/index.php', APP.'/public/maintenance.php'], true), 'unreviewed_public_php_entry');
        }
    }
    need(! is_link(APP.'/vendor') && ! is_link(APP.'/public/build'), 'deployment_directory_identity');
    foreach ($m['maintenanceHashes'] as $file => $sha) {
        need(in_array($file, ['state.json', 'audit.jsonl'], true) && hash_equals($sha, hash('sha256', privateRead(APP.'/storage/framework/site-maintenance/'.$file))), 'current_maintenance_snapshot');
    }
    need(json_decode(privateRead(APP.'/storage/framework/site-maintenance/state.json'), true)['enabled'] === false, 'site_maintenance_off');
    foreach ($proof['protectedFileHashes'] as $file => $sha) {
        need(in_array($file, ['.env', 'public/.htaccess', 'public/build/manifest.json', 'composer.lock', 'package-lock.json'], true)
            && ! is_link(APP.'/'.$file) && hash_equals($sha, hash_file('sha256', APP.'/'.$file)), 'current_backup_protected_files');
    }
    define('APP', APP);
    define('SOURCE_DB', 'novinpay_live1');
    require KIT.'/production-environment.php';
    $env = \verifiedProductionEnvironment();
    $credentials = '/home/novinpay/novinpay-releases/155fc9f30feb5b9d0c41445b33f1118d5e07da8e/.stage2-credentials.json';
    need(hash_equals($m['credentialsSha256'], hash('sha256', privateRead($credentials))), 'retained_credentials');
    $mig = json_decode(privateRead($credentials), true, flags: JSON_THROW_ON_ERROR)['migrator'];
    need($mig['user'] === 'novinpay_mig1', 'migrator_account');
    $connect = static fn ($user, $password) => new PDO('mysql:host=localhost;dbname=novinpay_live1;charset=utf8mb4', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $runtime = $connect('novinpay_app1', $env['DB_PASSWORD']);
    $observer = $connect($mig['user'], $mig['password']);
    foreach ([[$runtime, 'novinpay_app1@localhost'], [$observer, 'novinpay_mig1@localhost']] as [$db, $account]) {
        need($db->query('SELECT CURRENT_USER()')->fetchColumn() === $account && $db->query('SELECT DATABASE()')->fetchColumn() === 'novinpay_live1', 'connected_database_identity');
        $db->exec('SET SESSION TRANSACTION READ ONLY');
        need(in_array($db->query('SELECT CURRENT_ROLE()')->fetchColumn(), [null, 'NONE'], true), 'unexpected_active_database_role');
    }
    require_once KIT.'/pr13-preflight-v2.php';
    $grants = $observer->prepare('SELECT GRANTEE,TABLE_SCHEMA,PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=? AND TABLE_SCHEMA IN (?,?)');
    $grants->execute(["'novinpay_mig1'@'localhost'", 'novinpay_live1', 'novinpay\\_live1']);
    need(\pr13MigratorSchemaPrivilegesPresent($grants->fetchAll(), 'novinpay_live1', 'novinpay_mig1@localhost'), 'current_migrator_privileges');
    need((int) $observer->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn() === 0, 'unexpected_database_scheduled_writer');
    \NovinPay\Release\requestQuota($m, KIT, 'fence');
    pr13VerifyTriggerContracts(triggerRows($observer));
    $baseline = $m['baselineMigrations'];
    sort($baseline);
    need($observer->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN) === $baseline, 'live_baseline_migrations');
    // Refuse unknown scheduled writers; never edit cron or kill processes.
    $process = proc_open(['crontab', '-l'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP, null, ['bypass_shell' => true]);
    need(is_resource($process), 'cron_observation_unavailable');
    $cron = stream_get_contents($pipes[1]);
    $cronError = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    need(($exit === 0 && cronIsFenced($cron)) || ($exit === 1 && trim($cron) === '' && preg_match('/\Ano crontab for novinpay\s*\z/D', trim($cronError))), 'unreviewed_scheduled_writer');
    unset($cron, $cronError);
    $web = webOpcodeCheck([]);
    need(is_int($web['pid']) && $web['pid'] > 0 && is_file('/proc/'.$web['pid'].'/stat')
        && hash_equals($web['startTicks'], processStartTicks(file_get_contents('/proc/'.$web['pid'].'/stat'))), 'web_worker_not_visible_to_cli');
    run(['git', 'fetch', '--no-tags', 'origin', $m['candidate']]);
    need(trim(run(['git', 'rev-parse', 'FETCH_HEAD'])) === $m['candidate'] && trim(run(['git', 'rev-parse', $m['candidate'].'^{tree}'])) === $m['candidateTree'], 'candidate_git_tree');
    run(['git', 'merge-base', '--is-ancestor', $m['baseline'], $m['candidate']]);
    need(! file_exists(STATE) && ! is_link(STATE) && mkdir(STATE, 0700), 'existing_activation_preserve_no_retry');
    $journal = new Journal(STATE, $argv[2]);
    writeNew(STATE.'/activation.started.json', ['manifest' => $argv[2], 'parentPid' => getmypid(), 'startedUtc' => gmdate('c')]);
    require KIT.'/pr13-recovery-v3.php'; // imports only the reviewed safe extractor
    $phase = 'stage_verified_artifacts';
    \pr13ExtractPrivate(KIT.'/'.$m['sourceArchive']['name'], KIT.'/pr13-activation-app');
    \pr13ExtractPrivate(KIT.'/'.$m['vendorArchive']['name'], KIT.'/pr13-activation-app/vendor');
    \pr13ExtractPrivate(KIT.'/'.$m['frontendArchive']['name'], KIT.'/pr13-activation-build');
    foreach ($m['changedRuntimeFiles'] as $file => $sha) {
        need(! is_link(KIT.'/pr13-activation-app/'.$file) && hash_equals($sha, hash_file('sha256', KIT.'/pr13-activation-app/'.$file)), 'staged_source_integrity');
    }
    foreach (['vendorFiles' => '/pr13-activation-app/vendor/', 'frontendFiles' => '/pr13-activation-build/'] as $key => $prefix) {
        foreach ($m[$key] as $file => $sha) {
            need(! is_link(KIT.$prefix.$file) && hash_equals($sha, hash_file('sha256', KIT.$prefix.$file)), 'staged_dependency_asset_integrity');
        }
    }
    foreach (['storage/app', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
        if (! is_dir(KIT.'/pr13-activation-app/'.$dir)) {
            need(mkdir(KIT.'/pr13-activation-app/'.$dir, 0700, true), 'private_candidate_storage');
        }
    }
    $originalArtisan = file_get_contents(APP.'/artisan');
    $artisanHash = hash('sha256', $originalArtisan);
    need(hash_equals($artisanHash, hash('sha256', run(['git', 'show', $m['baseline'].':artisan'])))
        && hash_equals($artisanHash, hash('sha256', run(['git', 'show', $m['candidate'].':artisan']))), 'unchanged_artisan_entry');
    writeNew(STATE.'/fence-owner.json', ['manifest' => $argv[2], 'artisanSha256' => $artisanHash, 'webSha256' => hash('sha256', webFence()), 'cliSha256' => hash('sha256', cliFence())]);
    $f = fopen(STATE.'/original-artisan', 'xb');
    need(is_resource($f) && fwrite($f, $originalArtisan) === strlen($originalArtisan) && fflush($f) && fsync($f), 'original_entry_backup');
    fclose($f);
    $phase = 'fence_and_drain';
    \NovinPay\Release\requestQuota($m, KIT, 'fence');
    $fresh();
    $armed = true;
    $f = fopen(APP.'/storage/framework/maintenance.php', 'xb');
    need(is_resource($f) && fwrite($f, webFence()) === strlen(webFence()) && fflush($f) && fsync($f), 'web_fence_install');
    fclose($f);
    writeNew(APP.'/storage/framework/down', ['time' => time(), 'retry' => 60, 'status' => 503, 'redirect' => null]);
    replaceOwned(APP.'/artisan', $artisanHash, cliFence());
    writeNew(STATE.'/fence.complete.json', ['manifest' => $argv[2], 'downSha256' => hash_file('sha256', APP.'/storage/framework/down')]);
    [$headers, $body] = https('/', [503]);
    need(str_contains($headers['cache-control'] ?? '', 'no-store') && ($headers['retry-after'] ?? '') === '60', 'actual_https_fence_headers');
    [, $body] = https('/', [503], true);
    need($body === '', 'fenced_head_body');
    $cli = command([PHP_BINARY, APP.'/artisan', 'list'], APP, 'cli_fence', 30);
    need($cli['exit'] === 75, 'actual_cli_fence');
    $sample = static function () use ($runtime, $observer): array {
        $connections = 0;
        foreach ([$runtime, $observer] as $db) {
            $connections += (int) $db->query('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER=SUBSTRING_INDEX(CURRENT_USER(),\'@\',1) AND ID<>CONNECTION_ID()')->fetchColumn();
        }

        return ['observable' => true, 'phpProcesses' => phpProcesses('/proc', 1321, getmypid()), 'otherDatabaseConnections' => $connections];
    };
    $drain = waitForDrain($sample, static fn () => usleep(1000000), static fn () => microtime(true), microtime(true) + 600);
    writeNew(STATE.'/drain.complete.json', ['manifest' => $argv[2], 'result' => $drain, 'completedUtc' => gmdate('c')]);
    // Refresh again after potentially slow draining, immediately before live DDL.
    \NovinPay\Release\requestQuota($m, KIT, 'fence');
    $fresh();
    need($sample()['otherDatabaseConnections'] === 0, 'new_unfenced_database_writer');
    foreach ($proof['protectedFileHashes'] as $file => $sha) {
        need(! is_link(APP.'/'.$file) && hash_equals($sha, hash_file('sha256', APP.'/'.$file)), 'backup_configuration_changed_during_drain');
    }
    foreach ($m['maintenanceHashes'] as $file => $sha) {
        need(hash_equals($sha, hash('sha256', privateRead(APP.'/storage/framework/site-maintenance/'.$file))), 'maintenance_changed_during_drain');
    }
    need(\NovinPay\Release\Backup\filesManifest(APP.'/storage/app') === $metadata['persistentFileHashes'], 'backup_uploads_changed_requires_new_generation');
    $ticket = bin2hex(random_bytes(32));
    putenv('NOVINPAY_PR13_TICKET='.$ticket);
    writeNew(STATE.'/child-permit.json', ['parentPid' => getmypid(), 'manifest' => $argv[2], 'ticketSha256' => hash('sha256', $ticket)]);
    $overrides = ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://novinpaypos.com', 'APP_KEY' => $env['APP_KEY'],
        'APP_BASE_PATH' => KIT.'/pr13-activation-app', 'LARAVEL_STORAGE_PATH' => KIT.'/pr13-activation-app/storage', 'APP_CONFIG_CACHE' => KIT.'/pr13-activation-app/bootstrap/cache/release-config.php',
        'DB_CONNECTION' => 'mariadb', 'DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => 'novinpay_live1', 'DB_USERNAME' => $mig['user'], 'DB_PASSWORD' => $mig['password'], 'DB_SOCKET' => '',
        'DB_URL' => 'mariadb://localhost/novinpay_live1?timezone=%2B00%3A00', 'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'log',
        'ZIBAL_ENABLED' => 'false', 'ZIBAL_LIVE_REQUESTS' => 'false', 'SMSIR_ENABLED' => 'false', 'SMSIR_NOTIFICATIONS_ENABLED' => 'false', 'POSTEX_QUOTES_ENABLED' => 'false', 'POSTEX_INDEPENDENT_POSTAGE_VERIFIED' => 'false'];
    foreach ($overrides as $key => $value) {
        putenv($key.'='.$value);
    }
    $phase = 'ddl';
    $journal->phase('ddl', static fn () => json_decode(run([PHP_BINARY, KIT.'/pr13-live-database.php', $argv[1], $argv[2], 'migrate'], KIT, 600), true, flags: JSON_THROW_ON_ERROR),
        static fn ($r) => ($r['result'] ?? '') === 'PR13_LIVE_MIGRATION_PRESERVATION_PASS' && $r['originalRowsPreserved'] && $r['originalForeignKeysPreserved'] && $r['baselineTriggersPreserved']);
    foreach (array_keys($overrides) as $key) {
        putenv($key);
    }
    $phase = 'install_compatible_candidate';
    writeNew(STATE.'/source.started.json', ['manifest' => $argv[2]]);
    run(['git', 'merge', '--ff-only', $m['candidate']]);
    foreach ($m['changedRuntimeFiles'] as $file => $sha) {
        need(! is_link(APP.'/'.$file) && hash_equals($sha, hash_file('sha256', APP.'/'.$file)), 'installed_candidate_integrity');
    }
    need(stat(APP.'/vendor')['dev'] === stat(KIT.'/pr13-activation-app/vendor')['dev'], 'atomic_vendor_filesystem');
    need(rename(APP.'/vendor', STATE.'/baseline-vendor') && rename(KIT.'/pr13-activation-app/vendor', APP.'/vendor'), 'vendor_switch_preserve_fence');
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(KIT.'/pr13-activation-build', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        need(! $entry->isLink() && chmod($entry->getPathname(), $entry->isDir() ? 0755 : 0644), 'public_asset_permissions');
    }
    need(chmod(KIT.'/pr13-activation-build', 0755), 'public_build_permissions');
    need(rename(APP.'/public/build', STATE.'/baseline-build') && rename(KIT.'/pr13-activation-build', APP.'/public/build'), 'assets_switch_preserve_fence');
    // Only the one non-secret feature flag changes; credentials/provider values stay byte-for-byte.
    $oldEnv = privateRead(APP.'/.env');
    replaceOwned(APP.'/.env', hash('sha256', $oldEnv), enabledChatEnvironment($oldEnv));
    if (is_file(APP.'/bootstrap/cache/config.php')) {
        need(! is_link(APP.'/bootstrap/cache/config.php') && rename(APP.'/bootstrap/cache/config.php', STATE.'/baseline-config-cache.php'), 'config_cache_preservation');
    }
    foreach (['APP_BASE_PATH' => APP, 'LARAVEL_STORAGE_PATH' => APP.'/storage', 'APP_CONFIG_CACHE' => APP.'/bootstrap/cache/config.php'] as $key => $value) {
        putenv($key.'='.$value);
    }
    $phase = 'candidate_readiness';
    $ready = json_decode(run([PHP_BINARY, KIT.'/pr13-live-database.php', $argv[1], $argv[2], 'ready'], APP, 300), true, flags: JSON_THROW_ON_ERROR);
    need(($ready['result'] ?? '') === 'PR13_RUNTIME_READINESS_PASS', 'candidate_readiness_failed');
    foreach (['vendorFiles' => '/vendor/', 'frontendFiles' => '/public/build/'] as $key => $prefix) {
        foreach ($m[$key] as $file => $sha) {
            need(! is_link(APP.$prefix.$file) && hash_equals($sha, hash_file('sha256', APP.$prefix.$file)), 'installed_dependency_asset_integrity');
        }
    }
    pr13VerifyTriggerContracts(triggerRows($observer), true);
    $php = [];
    foreach (array_merge(array_keys($m['changedRuntimeFiles']), array_map(static fn ($p) => 'vendor/'.$p, array_keys($m['vendorFiles'])), ['bootstrap/cache/config.php', 'bootstrap/cache/routes-v7.php']) as $file) {
        if (str_ends_with($file, '.php') && is_file(APP.'/'.$file)) {
            $php[] = APP.'/'.$file;
        }
    }
    foreach (['bootstrap/cache', 'storage/framework/views'] as $directory) {
        foreach (glob(APP.'/'.$directory.'/*.php') as $file) {
            need(! is_link($file) && fileowner($file) === 1321, 'compiled_cache_identity');
            $php[] = $file;
        }
    }
    webOpcodeCheck(array_unique($php));
    [, $body] = https('/maintenance.php', [503]);
    need(str_contains($body, 'tel:09384144021') && str_contains($body, 'tel:09362896373'), 'approved_standalone_page');
    need(hash_equals($proof['protectedFileHashes']['public/.htaccess'], hash_file('sha256', APP.'/public/.htaccess'))
        && readlink('/home/novinpay/public_html') === APP.'/public', 'routing_preserved_before_admission');
    foreach ($m['maintenanceHashes'] as $file => $sha) {
        need(hash_equals($sha, hash('sha256', privateRead(APP.'/storage/framework/site-maintenance/'.$file))), 'private_history_preserved');
    }
    foreach ($metadata['persistentFileHashes'] as $file => $sha) {
        need(! is_link(APP.'/storage/app/'.$file) && hash_equals($sha, hash_file('sha256', APP.'/storage/app/'.$file)), 'original_upload_preserved');
    }
    writeNew(STATE.'/readiness.complete.json', ['manifest' => $argv[2], 'result' => $ready, 'migrations' => 22, 'triggers' => 13]);
    $phase = 'admission';
    writeNew(STATE.'/admission.started.json', ['manifest' => $argv[2], 'newDataPossibleFromThisPoint' => true]);
    need(hash_equals(hash('sha256', webFence()), hash_file('sha256', APP.'/storage/framework/maintenance.php')), 'owned_web_fence');
    replaceOwned(APP.'/artisan', hash('sha256', cliFence()), $originalArtisan);
    need(unlink(APP.'/storage/framework/down') && unlink(APP.'/storage/framework/maintenance.php'), 'admission_marker_removal');
    $phase = 'https_acceptance';
    foreach (['/' => [200], '/products' => [200], '/repair' => [200], '/contact' => [200], '/account/login' => [200], '/admin/login' => [200], '/support' => [302], '/admin/maintenance' => [302], '/support-chat/support-widget.js' => [200]] as $path => $codes) {
        [$headers, $body] = https($path, $codes);
        if (str_contains($path, 'support-widget.js')) {
            need(str_contains(strtolower($headers['content-type'] ?? ''), 'javascript'), 'widget_asset_content_type');
        }
        if (in_array($path, ['/support', '/admin/maintenance'], true)) {
            need(in_array($headers['location'] ?? '', ['/admin/login', 'https://novinpaypos.com/admin/login'], true), 'anonymous_operator_denial');
        }
    }
    [, $json] = https('/support-chat/api.php?get_csrf=1', [200]);
    $bootstrap = json_decode($json, true);
    need(is_array($bootstrap) && is_string($bootstrap['token'] ?? null) && strlen($bootstrap['token']) >= 32 && is_array($bootstrap['context'] ?? null), 'customer_chat_bootstrap_contract');
    unset($bootstrap, $json);
    https('/support-chat/operator-api.php', [401]);
    https('/storage/app/private/support-chat/installation.env', [403, 404]);
    need(trim(run(['git', 'rev-parse', 'HEAD'])) === $m['candidate'], 'final_commit');
    writeNew(STATE.'/activation.complete.json', ['manifest' => $argv[2], 'result' => 'PR13_RELEASE_PASS', 'candidate' => $m['candidate'], 'migrations' => 22, 'triggers' => 13,
        'maintenanceEnabled' => false, 'databaseRestored' => false, 'ownerAuthenticatedAcceptance' => 'pending', 'completedUtc' => gmdate('c')]);
    $finished = true;
    echo json_encode(['result' => 'PR13_RELEASE_PASS', 'candidate' => $m['candidate'], 'migrations' => 22, 'triggers' => 13, 'maintenanceEnabled' => false, 'ownerAuthenticatedAcceptance' => 'pending'], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // After any DDL, never resume baseline or restore a historical database.
    // Re-arm both fences even if an error occurs after traffic admission.
    $pendingAfterAdmission = $error instanceof Pending && file_exists(STATE.'/readiness.complete.json') && file_exists(STATE.'/admission.started.json');
    if ($armed && ! $pendingAfterAdmission) {
        if (! file_exists(APP.'/storage/framework/maintenance.php')) {
            $f = fopen(APP.'/storage/framework/maintenance.php', 'xb');
            if (is_resource($f)) {
                fwrite($f, webFence());
                fflush($f);
                fsync($f);
                fclose($f);
            }
        }
        if (! file_exists(APP.'/storage/framework/down')) {
            writeNew(APP.'/storage/framework/down', ['time' => time(), 'retry' => 60, 'status' => 503]);
        }
        if (isset($artisanHash) && is_file(APP.'/artisan') && hash_equals($artisanHash, hash_file('sha256', APP.'/artisan'))) {
            replaceOwned(APP.'/artisan', $artisanHash, cliFence());
        }
    }
    $report = \NovinPay\Release\failureReport($error, $phase, $phase, $armed);
    $report['result'] = 'PR13_ACTIVATION_STOP';
    if ($pendingAfterAdmission) {
        $report['result'] = 'PR13_ACTIVATION_VERIFICATION_PENDING';
        $report['nextAction'] = 'Read-only HTTPS verification only; never replay activation or migrations.';
    }
    $report['recovery'] = recoveryAction(file_exists(STATE.'/ddl.started.json'), file_exists(STATE.'/admission.started.json'));
    $report['maintenanceMayBeActive'] = $armed;
    if ($journal instanceof Journal && ! file_exists(STATE.'/activation.failed.json')) {
        writeNew(STATE.'/activation.failed.json', $report);
    }
    $finished = true;
    echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
    exit($pendingAfterAdmission ? 75 : 1);
} finally {
    $journal?->close();
}
