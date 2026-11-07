<?php

declare(strict_types=1);
use NovinPay\Release\Journal;

// Recovery preparation only: never deploys, fences traffic or migrates live DB.
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/pr13-schema-contract.php';
require_once __DIR__.'/pr13-maintenance-snapshot.php';

use function NovinPay\Release\privateRead;
use function NovinPay\Release\requireGate;

function pr13ExtractPrivate(string $archive, string $destination): void
{
    requireGate(! file_exists($destination) && ! is_link($destination) && mkdir($destination, 0700), 'exclusive_rehearsal_directory', 'new private rehearsal path', [], 'Preserve an existing attempt; do not overwrite.');
    $zip = new ZipArchive;
    requireGate($zip->open($archive) === true, 'zip_open', 'verified ZIP', [], 'Inspect archive integrity.');
    $total = 0;
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $s = $zip->statIndex($i);
            $name = $s['name'];
            $zip->getExternalAttributesIndex($i, $os, $attributes);
            requireGate($name !== '' && ! str_starts_with($name, '/') && ! str_contains($name, '\\') && ! str_contains($name, ':') && ! str_contains($name, "\0")
                && ! in_array('..', explode('/', $name), true) && (($attributes >> 16) & 0170000) !== 0120000,
                'zip_member_path', 'relative regular files only', [], 'Preserve the archive; reject unsafe paths.');
            $total += $s['size'];
            requireGate($total <= 268435456 && $s['size'] <= 67108864, 'zip_expansion_bound', 'bounded artifact', [], 'Inspect unexpected package expansion.');
            $path = $destination.'/'.$name;
            if (str_ends_with($name, '/')) {
                if (! is_dir($path)) {
                    requireGate(mkdir($path, 0700, true), 'zip_directory', 'private directory', [], 'Inspect filesystem.');
                }

                continue;
            }
            if (! is_dir(dirname($path))) {
                requireGate(mkdir(dirname($path), 0700, true), 'zip_parent', 'private parent', [], 'Inspect filesystem.');
            }
            $input = $zip->getStream($name);
            $output = fopen($path, 'xb');
            try {
                requireGate(is_resource($input) && is_resource($output) && chmod($path, 0600)
                    && stream_copy_to_stream($input, $output) === $s['size'] && fflush($output) && fsync($output),
                    'zip_extract', 'complete private member', [], 'Preserve incomplete rehearsal artifacts.');
            } finally {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
            }
        }
    } finally {
        $zip->close();
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
$phase = 'invocation';
$journal = null;
$done = false;
register_shutdown_function(static function () use (&$done, &$phase): void {
    if (! $done) {
        fwrite(STDERR, json_encode(['result' => 'PR13_RECOVERY_INTERRUPTED', 'phase' => $phase, 'productionModified' => false, 'retrySafe' => false]).PHP_EOL);
    }
});
try {
    requireGate(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && function_exists('posix_geteuid') && posix_geteuid() === 1321 && count($argv) === 3,
        'invocation', 'private CLI preparation under hosting owner', [], 'Use the pinned preparation command.');
    $text = privateRead($argv[1]);
    requireGate(hash_equals($argv[2], hash('sha256', $text)), 'manifest_pin', 'reviewed manifest', [], 'Inspect package identity.');
    $m = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    $m['_sha256'] = $argv[2];
    requireGate(__DIR__ === '/home/novinpay/novinpay-release-pipeline-v1' && $m['baseline'] === '1f39bb476fd31880600564e58599c4488622c4c0'
        && $m['backup']['directory'] === '/home/novinpay/novinpay-private-backups/maintenance-pr13-v1'
        && $m['backup']['restoreDatabase'] === 'novinpay_restore13r1' && $m['stateDirectory'] === __DIR__.'/pipeline-pr13-r2',
        'manifest_scope', 'approved recovery paths only', [], 'Do not substitute production or earlier restore databases.');
    foreach ($m['tools'] as $name => $hash) {
        requireGate(preg_match('/\A[a-z0-9-]+\.(php|zip|json)\z/D', $name) === 1 && hash_equals($hash, hash('sha256', privateRead(__DIR__.'/'.$name))),
            'tool_hash', 'all exact private tool bytes', [], 'Preserve changed files; no blind retry.');
    }
    foreach (['sourceArchive', 'vendorArchive'] as $key) {
        requireGate(hash_equals($m[$key]['sha256'], hash_file('sha256', __DIR__.'/'.$m[$key]['name'])), 'artifact_hash', 'pinned source/vendor artifact', [], 'Verify archives.');
    }
    // Preserve and fingerprint the accounted-for stop. Never relabel old receipts
    // with a new manifest or turn an ambiguous backup into a fresh attempt.
    $phase = 'prior_attempt_reconciliation';
    $prior = __DIR__.'/pipeline-pr13';
    requireGate(is_dir($prior) && ! is_link($prior) && fileowner($prior) === 1321 && (fileperms($prior) & 0777) === 0700,
        'prior_journal_identity', 'original owner-only journal directory', [], 'Preserve and inspect path substitution or permission changes.');
    $priorFiles = ['restore_defaults.started.json', 'restore_defaults.complete.json'];
    $priorReceipts = [];
    foreach ($priorFiles as $name) {
        $bytes = privateRead($prior.'/'.$name);
        requireGate(isset($m['priorAttempt']['files'][$name]) && hash_equals($m['priorAttempt']['files'][$name], hash('sha256', $bytes)),
            'prior_evidence_changed', 'exact preserved receipt bytes', [], 'Inspect prior evidence without overwriting it.');
        $priorReceipts[] = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }
    requireGate(($m['priorAttempt']['manifestSha256'] ?? '') === '9d43b0ab1c0dbb7d3da4720c1fbb357081e79f2a4551a66a1cd3092f8a200a69'
        && pr13PriorAttemptIsPreBackup(array_values(array_diff(scandir($prior), ['.', '..'])), $priorReceipts[0], $priorReceipts[1],
            $m['priorAttempt']['manifestSha256'], file_exists($m['backup']['directory']) || is_link($m['backup']['directory']),
            file_exists(__DIR__.'/pr13-rehearsal-app') || is_link(__DIR__.'/pr13-rehearsal-app')),
        'prior_attempt_not_pre_backup', 'verified stop before export/import', [], 'Preserve all artifacts; do not retry an ambiguous operation.');
    require __DIR__.'/guard-v1.php';
    require __DIR__.'/backup-working-tree-v2.php';
    require __DIR__.'/pr13-backup-v1.php';
    require __DIR__.'/pr13-backup-io-v1.php';
    if (! is_dir($m['stateDirectory'])) {
        requireGate(! is_link($m['stateDirectory']) && mkdir($m['stateDirectory'], 0700), 'journal_create', 'private recovery journal', [], 'Inspect directory.');
    }
    requireGate(! is_link($m['stateDirectory']) && (fileperms($m['stateDirectory']) & 0777) === 0700 && fileowner($m['stateDirectory']) === 1321,
        'journal_identity', 'owner-only state', [], 'Inspect permissions.');
    $journal = new Journal($m['stateDirectory'], $argv[2]);
    // Completed or ambiguous imports are never replayed by this entry point.
    requireGate(! file_exists($m['stateDirectory'].'/backup.started.json') && ! file_exists($m['backup']['directory']),
        'previous_attempt_preserve', 'fresh unused recovery attempt', [], 'Inspect existing receipts; do not rerun.');
    define('APP', '/home/novinpay/novinpay');
    define('SOURCE_DB', 'novinpay_live1');
    require_once __DIR__.'/production-environment.php';
    $env = verifiedProductionEnvironment();
    $credentialsPath = '/home/novinpay/novinpay-releases/155fc9f30feb5b9d0c41445b33f1118d5e07da8e/.stage2-credentials.json';
    requireGate(hash_equals($m['credentialsSha256'], hash('sha256', privateRead($credentialsPath))), 'retained_credentials_changed', 'independently recovered credential-file digest', [], 'Preserve current credentials; inspect mismatch.');
    $credential = json_decode(privateRead($credentialsPath), true, flags: JSON_THROW_ON_ERROR)['migrator'];
    requireGate($credential['user'] === 'novinpay_mig1', 'migrator_identity', 'retained account', [], 'Do not substitute users.');
    $connect = static fn ($db) => new PDO('mysql:host=localhost;port=3306;dbname='.$db.';charset=utf8mb4', $credential['user'], $credential['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $source = $connect('novinpay_live1');
    $restore = $connect('novinpay_restore13r1');
    requireGate($restore->query('SELECT DATABASE()')->fetchColumn() === 'novinpay_restore13r1'
        && $restore->query('SELECT CURRENT_USER()')->fetchColumn() === 'novinpay_mig1@localhost'
        && (int) $restore->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() === 0,
        'empty_authorized_restore', 'new empty isolated database', [], 'Preserve existing contents.');
    $phase = 'baseline_trigger_contracts';
    $rows = $source->query('SELECT TRIGGER_NAME name,EVENT_OBJECT_TABLE tableName,EVENT_MANIPULATION eventName,ACTION_TIMING timing,ACTION_STATEMENT body,DEFINER definer FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll();
    foreach ($rows as &$row) {
        $row['bodySha256'] = hash('sha256', $row['body']);
        unset($row['body']);
    }
    unset($row);
    pr13VerifyTriggerContracts($rows);
    $phase = 'empty_restore_defaults';
    $defaultsSql = 'SELECT DEFAULT_CHARACTER_SET_NAME charset_name,DEFAULT_COLLATION_NAME collation_name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()';
    $defaults = $source->query($defaultsSql)->fetch();
    $restoreDefaults = $restore->query($defaultsSql)->fetch();
    requireGate(preg_match('/\A[a-z0-9_]+\z/D', $defaults['charset_name']) === 1 && preg_match('/\A[a-z0-9_]+\z/D', $defaults['collation_name']) === 1,
        'database_defaults', 'catalog identifiers', [], 'Inspect defaults.');
    $journal->phase('restore_defaults', static function () use ($restore, $defaults, $restoreDefaults): array {
        if ($restoreDefaults !== $defaults) {
            $restore->exec('ALTER DATABASE `novinpay_restore13r1` CHARACTER SET '.$defaults['charset_name'].' COLLATE '.$defaults['collation_name']);
        }

        return $defaults;
    }, static fn ($r) => $r === $defaults && $restore->query($defaultsSql)->fetch() === $defaults);
    $source = $restore = $connect = null;
    $phase = 'fresh_backup_and_isolated_restore';
    $context = NovinPay\Release\Backup\prepare($m);
    $receipt = $journal->phase('backup', static fn () => NovinPay\Release\Backup\execute($context), static fn ($r) => ($r['result'] ?? '') === 'BACKUP_AND_DISPOSABLE_RESTORE_PASS'
        && $r['sourceCommit'] === $m['baseline'] && $r['restoreDatabase'] === 'novinpay_restore13r1' && $r['schemaAndDefinersMatch'] && $r['allSnapshotRowsMatch']
        && hash_equals($r['archiveSha256'], hash_file('sha256', $m['backup']['directory'].'/backup.npb')));
    unset($context);
    $phase = 'private_rehearsal_files';
    $root = __DIR__.'/pr13-rehearsal-app';
    pr13ExtractPrivate(__DIR__.'/'.$m['sourceArchive']['name'], $root);
    pr13ExtractPrivate(__DIR__.'/'.$m['vendorArchive']['name'], $root.'/vendor');
    foreach ($m['changedRuntimeFiles'] as $file => $hash) {
        requireGate(! is_link($root.'/'.$file) && hash_equals($hash, hash_file('sha256', $root.'/'.$file)), 'rehearsal_source_hash', 'reviewed candidate files', [], 'Preserve artifacts.');
    }
    foreach ($m['vendorFiles'] as $file => $hash) {
        requireGate(! is_link($root.'/vendor/'.$file) && hash_equals($hash, hash_file('sha256', $root.'/vendor/'.$file)), 'rehearsal_vendor_hash', 'locked runtime files', [], 'Preserve artifacts.');
    }
    foreach (['bootstrap/cache', 'storage/app', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $dir) {
        if (! is_dir($root.'/'.$dir)) {
            requireGate(mkdir($root.'/'.$dir, 0700, true), 'rehearsal_storage', 'private writable local storage', [], 'Inspect permissions.');
        }
    }
    $overrides = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://novinpaypos.com', 'APP_KEY' => $env['APP_KEY'],
        'APP_BASE_PATH' => $root, 'LARAVEL_STORAGE_PATH' => $root.'/storage', 'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/rehearsal-config.php',
        'DB_CONNECTION' => 'mariadb', 'DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => 'novinpay_restore13r1',
        'DB_USERNAME' => $credential['user'], 'DB_PASSWORD' => $credential['password'], 'DB_SOCKET' => '', 'DB_URL' => 'mariadb://localhost/novinpay_restore13r1?timezone=%2B00%3A00',
        'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'log', 'ZIBAL_ENABLED' => 'false', 'ZIBAL_LIVE_REQUESTS' => 'false',
        'SMSIR_ENABLED' => 'false', 'SMSIR_NOTIFICATIONS_ENABLED' => 'false', 'POSTEX_QUOTES_ENABLED' => 'false', 'POSTEX_INDEPENDENT_POSTAGE_VERIFIED' => 'false'];
    $previous = [];
    try {
        foreach ($overrides as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($key.'='.$value);
        }
        $phase = 'isolated_eight_migration_rehearsal';
        $rehearsal = $journal->phase('migration_rehearsal', static function () use ($argv, $root): array {
            $r = NovinPay\Release\command([PHP_BINARY, __DIR__.'/pr13-rehearsal-v2.php', $argv[1], $argv[2], $root], $root, 'isolated_migration_rehearsal', 600);
            $child = json_decode(trim($r['stdout']), true);
            $safeLabel = static fn ($value) => is_string($value) && preg_match('/\A[a-z0-9_]+\z/D', $value) ? $value : 'details_withheld';
            requireGate($r['exit'] === 0, 'isolated_migration_rehearsal', 'reviewed upgrade preserves original data',
                ['exit' => $r['exit'], 'failedCheck' => $safeLabel($child['check'] ?? null), 'childPhase' => $safeLabel($child['phase'] ?? null),
                    'sqlState' => $child['actual']['sqlState'] ?? null, 'driverCode' => $child['actual']['driverCode'] ?? null],
                'Inspect isolated evidence; never migrate production on failure.');

            return json_decode(trim($r['stdout']), true, flags: JSON_THROW_ON_ERROR);
        }, static fn ($r) => ($r['result'] ?? '') === 'PR13_ISOLATED_MIGRATION_REHEARSAL_PASS' && $r['originalRowsPreserved'] && $r['originalForeignKeysPreserved'] && $r['baselineTriggersPreserved']);
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        unset($env, $credential, $overrides);
    }
    requireGate(trim(NovinPayReleaseV1\git(APP, ['rev-parse', 'HEAD'])) === $m['baseline'], 'production_unchanged', 'baseline remains active', [], 'Inspect source without changing it.');
    $done = true;
    echo json_encode(['result' => 'PR13_BACKUP_RESTORE_REHEARSAL_PASS', 'sourceCommit' => $m['baseline'], 'candidate' => $m['candidate'],
        'archiveSha256' => $receipt['archiveSha256'], 'backupDirectory' => $m['backup']['directory'], 'restoreDatabase' => 'novinpay_restore13r1',
        'rehearsalMigrations' => 22, 'rehearsalTriggers' => 13, 'originalRowsPreserved' => true, 'productionModified' => false,
        'offhostVerification' => 'pending_independent_transfer', 'activationExecuted' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    $done = true;
    $report = NovinPay\Release\failureReport($error, $phase, $GLOBALS['np_backup_check'] ?? $phase, false);
    $report['result'] = 'PR13_RECOVERY_STOP';
    $report['productionModified'] = false;
    $report['retrySafe'] = false;
    echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
} finally {
    $journal?->close();
}
