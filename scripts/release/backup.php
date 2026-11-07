<?php

// Reuses the reviewed backup/export/restore/archive algorithm; no CLI auto-execution.
declare(strict_types=1);

namespace NovinPay\Release\Backup;

use Dotenv;
use FilesystemIterator;
use Illuminate;
use NovinPay\Release\GateFailure;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

const APP = '/home/novinpay/novinpay';
const SOURCE_DB = 'novinpay_live1';
const CREDS = '/home/novinpay/novinpay-releases/155fc9f30feb5b9d0c41445b33f1118d5e07da8e/.stage2-credentials.json';
const KEY_FILE = '/home/novinpay/.novinpay-maint10-key';
const AAD = 'NOVINPAY-BACKUP-AES256GCM-v1';
function need(bool $ok, string $code): void
{
    if (! $ok) {
        throw new GateFailure($code, 'backup invariant holds', [], 'Preserve backup and restore evidence; inspect this check before retry.');
    }
}
/** The private credential fingerprint belongs to an approved private manifest. */
function verifyCredentialPin(array $manifest, string $actualHash): void
{
    $pin = $manifest['credentialsSha256'] ?? null;
    need(is_string($pin) && preg_match('/\A[a-f0-9]{64}\z/D', $pin) === 1
        && hash_equals($pin, $actualHash), 'retained_credentials_changed');
}
function putPrivate(string $path, string $bytes): void
{
    need(! file_exists($path) && ! is_link($path), 'private_destination_exists');
    $handle = fopen($path, 'xb');
    try {
        need(is_resource($handle) && chmod($path, 0600) && fwrite($handle, $bytes) === strlen($bytes)
            && fflush($handle) && fsync($handle), 'private_write_failed');
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }
}

function clientConfig(string $user, string $password): string
{
    $quote = fn (string $s): string => '"'.str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $s).'"';

    return "[client]\nhost=localhost\nport=3306\nuser=".$quote($user)."\npassword=".$quote($password)."\ndefault-character-set=utf8mb4\n";
}
function dataDigest(string $file): array
{
    $handle = fopen($file, 'rb');
    need($handle !== false, 'dump_read_failed');
    $hash = hash_init('sha256');
    $counts = [];
    while (($line = fgets($handle)) !== false) {
        if (preg_match('/\AINSERT INTO `([a-z0-9_]+)` /', $line, $m)) {
            hash_update($hash, $line);
            $counts[$m[1]] = ($counts[$m[1]] ?? 0) + 1;
        }
    }
    need(feof($handle), 'dump_read_incomplete');
    fclose($handle);
    ksort($counts);

    return ['sha256' => hash_final($hash), 'insertRows' => $counts];
}
function schema(PDO $db, string $name): array
{
    need(in_array($name, [SOURCE_DB, RESTORE_DB], true), 'schema_identity');
    need($db->query('SELECT DATABASE()')->fetchColumn() === $name, 'actual_database_mismatch');
    $result = ['tables' => [], 'triggers' => []];
    $tables = $db->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tables as $table) {
        need($table['TABLE_TYPE'] === 'BASE TABLE' && $table['ENGINE'] === 'InnoDB', 'unsupported_schema_object');
        $tableName = $table['TABLE_NAME'];
        need((bool) preg_match('/\A[a-z0-9_]+\z/', $tableName), 'unexpected_table_name');
        $create = $db->query('SHOW CREATE TABLE `'.$tableName.'`')->fetch(PDO::FETCH_NUM)[1];
        $result['tables'][$tableName] = preg_replace('/\bAUTO_INCREMENT=[0-9]+\s*/', '', str_replace('`'.$name.'`.', '`BACKUP_SCHEMA`.', $create));
    }
    foreach ($db->query('SELECT TRIGGER_NAME,DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
        need((bool) preg_match('/\A[a-zA-Z0-9_]+\z/', $trigger['TRIGGER_NAME']), 'unexpected_trigger_name');
        need(explode('@', $trigger['DEFINER'])[0] === 'novinpay_mig1', 'unexpected_trigger_definer');
        $row = $db->query('SHOW CREATE TRIGGER `'.$trigger['TRIGGER_NAME'].'`')->fetch(PDO::FETCH_ASSOC);
        $result['triggers'][$trigger['TRIGGER_NAME']] = ['definer' => $trigger['DEFINER'], 'mode' => $row['sql_mode'], 'sql' => str_replace('`'.$name.'`.', '`BACKUP_SCHEMA`.', $row['SQL Original Statement']), 'charset' => $row['character_set_client'], 'collation' => $row['collation_connection'], 'databaseCollation' => $row['Database Collation']];
    }
    need(count($result['triggers']) === 5, 'five_triggers_required');
    need((int) $db->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn() === 0, 'unexpected_routines');
    need((int) $db->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn() === 0, 'unexpected_events');

    return $result;
}
function filesManifest(string $root): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        need(! $file->isLink() && $file->isFile(), 'persistent_storage_link_or_special_file');
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $files[$relative] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);

    return $files;
}
function encryptBundle(string $plain, string $key): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, AAD, 16);
    need($cipher !== false && strlen($tag) === 16, 'encryption_failed');

    return json_encode(['format' => AAD, 'iv' => base64_encode($iv), 'tag' => base64_encode($tag)], JSON_THROW_ON_ERROR)."\n".$cipher;
}
function decryptBundle(string $encoded, string $key): string
{
    $split = strpos($encoded, "\n");
    need($split !== false && $split < 1024, 'invalid_envelope');
    $h = json_decode(substr($encoded, 0, $split), true, 512, JSON_THROW_ON_ERROR);
    need($h['format'] === AAD, 'invalid_envelope_format');
    $plain = openssl_decrypt(substr($encoded, $split + 1), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, base64_decode($h['iv'], true), base64_decode($h['tag'], true), AAD);
    need($plain !== false, 'authentication_failed');

    return $plain;
}
function verifiedProductionEnvironment(): array
{
    require_once APP.'/vendor/autoload.php';
    $env = Dotenv\Dotenv::createArrayBacked(APP)->load();
    need(($env['APP_ENV'] ?? '') === 'production' && ($env['DB_CONNECTION'] ?? '') === 'mariadb'
        && ($env['DB_DATABASE'] ?? '') === SOURCE_DB && ($env['DB_USERNAME'] ?? '') === 'novinpay_app1', 'production_environment_fields');
    $parts = parse_url($env['DB_URL'] ?? '');
    need(is_array($parts) && ($parts['scheme'] ?? '') === 'mariadb' && ($parts['host'] ?? '') === 'localhost'
        && ($parts['path'] ?? '') === '/'.SOURCE_DB && ! array_key_exists('user', $parts) && ! array_key_exists('pass', $parts)
        && ! array_key_exists('fragment', $parts) && (! isset($parts['port']) || $parts['port'] === 3306), 'production_url_identity');
    parse_str($parts['query'] ?? '', $query);
    need($query === ['timezone' => '+00:00'], 'production_url_options');
    $parser = new Illuminate\Support\ConfigurationUrlParser;
    $effective = $parser->parseConfiguration(['driver' => 'mariadb', 'url' => $env['DB_URL'], 'host' => $env['DB_HOST'] ?? '127.0.0.1',
        'port' => $env['DB_PORT'] ?? '3306', 'database' => $env['DB_DATABASE'], 'username' => $env['DB_USERNAME'],
        'password' => $env['DB_PASSWORD'] ?? '', 'unix_socket' => $env['DB_SOCKET'] ?? '', 'strict' => true]);
    need($effective['database'] === SOURCE_DB && $effective['username'] === 'novinpay_app1' && $effective['host'] === 'localhost'
        && $effective['driver'] === 'mariadb' && (int) $effective['port'] === 3306 && $effective['unix_socket'] === ''
        && $effective['timezone'] === '+00:00' && $effective['strict'] === true && $effective['password'] !== '', 'production_effective_identity');
    need(is_file(APP.'/bootstrap/cache/config.php'), 'production_cached_config_missing');
    $cached = require APP.'/bootstrap/cache/config.php';
    need(($cached['database']['default'] ?? null) === 'mariadb' && ($cached['app']['env'] ?? null) === 'production'
        && ! empty($env['APP_KEY']) && ($cached['app']['key'] ?? null) === $env['APP_KEY'], 'production_cached_application');
    $active = $parser->parseConfiguration($cached['database']['connections']['mariadb']);
    foreach (['driver', 'host', 'database', 'username', 'password', 'timezone', 'strict', 'unix_socket'] as $field) {
        need(array_key_exists($field, $active) && $active[$field] === $effective[$field], 'production_cached_connection_mismatch');
    }
    need((int) ($active['port'] ?? 0) === 3306, 'production_cached_port');
    $runtime = new PDO('mysql:host=localhost;port=3306;dbname='.SOURCE_DB.';charset=utf8mb4', $effective['username'], $effective['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $runtime->exec('SET TRANSACTION READ ONLY');
    $runtime->beginTransaction();
    try {
        $identity = $runtime->query('SELECT DATABASE() db, CURRENT_USER() account, VERSION() version')->fetch();
        need($identity['db'] === SOURCE_DB && explode('@', $identity['account'])[0] === 'novinpay_app1'
            && str_starts_with($identity['version'], '10.6.28'), 'production_connected_identity');
    } finally {
        $runtime->rollBack();
    }

    return $env;
}

function prepare(array $manifest): array
{
    define(__NAMESPACE__.'\OUT', $manifest['backup']['directory']);
    define(__NAMESPACE__.'\RESTORE_DB', $manifest['backup']['restoreDatabase']);
    define(__NAMESPACE__.'\BASELINE', $manifest['baseline']);
    $optionFile = OUT.'/client.cnf';
    $GLOBALS['np_backup_check'] = 'backup_preflight';
    need(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && posix_getpwuid(posix_geteuid())['name'] === 'novinpay', 'cli_account');
    need(realpath(APP) === APP && ! is_link(APP) && is_link('/home/novinpay/public_html') && readlink('/home/novinpay/public_html') === APP.'/public', 'application_routing');
    need(! is_dir(OUT) && ! file_exists(OUT), 'nonretryable_backup_destination_exists');
    need((fileperms(APP.'/.env') & 0777) === 0600 && (fileperms(CREDS) & 0777) === 0600 && (fileperms(KEY_FILE) & 0777) === 0600, 'secret_permissions');
    verifyCredentialPin($manifest, hash_file('sha256', CREDS));
    foreach (getenv() as $k => $v) {
        need(! str_starts_with($k, 'MYSQL_') || $v === '', 'inherited_mysql_override');
    }

    need(trim(\NovinPayReleaseV1\git(APP, ['rev-parse', 'HEAD'])) === BASELINE, 'source_identity_before_backup');
    need(trim(\NovinPayReleaseV1\git(APP, ['branch', '--show-current'])) === 'codex/novinpay-release-contact-corrections', 'source_branch');
    $retainedEvidence = \NovinPayBackupWorktreeV2\verify(APP);
    $stateDir = APP.'/storage/framework/site-maintenance';
    foreach ($manifest['maintenanceHashes'] as $file => $expected) {
        $path = $stateDir.'/'.$file;
        need(is_file($path) && ! is_link($path) && fileowner($path) === 1321 && (fileperms($path) & 0777) === 0600 && hash_equals($expected, hash_file('sha256', $path)), 'offhost_state_snapshot_changed');
    }
    $maintenanceHashes = ['state.json' => hash_file('sha256', $stateDir.'/state.json'), 'audit.jsonl' => hash_file('sha256', $stateDir.'/audit.jsonl')];
    $state = json_decode(file_get_contents($stateDir.'/state.json'), true, 16, JSON_THROW_ON_ERROR);
    need(($state['enabled'] ?? null) === false, 'maintenance_not_disabled');

    $a = $manifest['backup'];
    need(hash_equals($a['serverKeySha256'], hash_file('sha256', KEY_FILE)), 'offhost_key_custody_not_proven');
    $GLOBALS['np_backup_check'] = 'runtime_identity';
    $parent = dirname(OUT);
    need(is_dir($parent), 'private_backup_parent');
    need(! is_link($parent) && (fileperms($parent) & 0777) === 0700, 'private_backup_parent_permissions');
    need(disk_free_space($parent) > 768 * 1024 * 1024, 'filesystem_headroom');
    $env = verifiedProductionEnvironment();
    $hashes = [];
    foreach (['.env', 'public/.htaccess', 'public/build/manifest.json', 'composer.lock', 'package-lock.json'] as $file) {
        $hashes[$file] = hash_file('sha256', APP.'/'.$file);
    }
    if (is_file(APP.'/bootstrap/cache/config.php')) {
        $cached = require APP.'/bootstrap/cache/config.php';
        $c = $cached['database']['connections']['mariadb'];
        need($cached['database']['default'] === 'mariadb' && $c['database'] === SOURCE_DB && $c['username'] === 'novinpay_app1' && $c['password'] === $env['DB_PASSWORD'] && $cached['app']['key'] === $env['APP_KEY'], 'cached_environment_mismatch');
        unset($cached,$c);
    }
    $credentials = json_decode(file_get_contents(CREDS), true, 512, JSON_THROW_ON_ERROR)['migrator'];
    need($credentials['user'] === 'novinpay_mig1', 'migrator_identity');
    $connect = fn (string $db): PDO => new PDO('mysql:host=localhost;port=3306;dbname='.$db.';charset=utf8mb4', $credentials['user'], $credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $source = $connect(SOURCE_DB);
    $restore = $connect(RESTORE_DB);
    unset($connect);
    need($restore->query('SELECT DATABASE()')->fetchColumn() === RESTORE_DB && (int) $restore->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() === 0, 'restore_target_not_empty');
    need(explode('@', $source->query('SELECT CURRENT_USER()')->fetchColumn())[0] === 'novinpay_mig1', 'source_account_identity');
    need(str_starts_with($source->query('SELECT VERSION()')->fetchColumn(), '10.6.28'), 'unverified_server_version');
    $policy = $source->query('SELECT @@log_bin binlog_enabled,@@log_bin_trust_function_creators trust_creators')->fetch();
    need(! (int) $policy['binlog_enabled'] || (int) $policy['trust_creators'] === 1, 'restore_trigger_policy_needs_provider');
    $dbMetadata = $source->query('SELECT DEFAULT_CHARACTER_SET_NAME charset_name,DEFAULT_COLLATION_NAME collation_name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()')->fetch();
    need((bool) preg_match('/\A[a-z0-9_]+\z/', $dbMetadata['charset_name']) && (bool) preg_match('/\A[a-z0-9_]+\z/', $dbMetadata['collation_name']), 'invalid_database_defaults');
    $dbBytes = (int) $source->query('SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
    $appBytes = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP, FilesystemIterator::SKIP_DOTS)) as $item) {
        if (! $item->isLink() && $item->isFile()) {
            $appBytes += $item->getSize();
        }
    }

    // Matching defaults are verified; no ALTER is issued or newly granted.
    $GLOBALS['np_backup_check'] = 'matching_restore_defaults';
    $restoreMetadata = $restore->query('SELECT DEFAULT_CHARACTER_SET_NAME charset_name,DEFAULT_COLLATION_NAME collation_name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()')->fetch();
    need($restoreMetadata === $dbMetadata, 'restore_defaults_changed_no_alter_permitted');

    $schema = schema($source, SOURCE_DB);
    need((int) $source->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 14, 'expected_migrations');
    $storage = filesManifest(APP.'/storage/app');
    need(is_link(APP.'/public/storage') && realpath(APP.'/public/storage') === APP.'/storage/app/public', 'public_storage_identity');
    $sourceFiles = [];
    foreach (explode("\0", \NovinPayReleaseV1\git(APP, ['ls-files', '-z'])) as $file) {
        if ($file === '' || str_starts_with($file, 'storage/framework/') || str_starts_with($file, 'storage/logs/') || str_starts_with($file, 'bootstrap/cache/')) {
            continue;
        }
        need(is_file(APP.'/'.$file) && ! is_link(APP.'/'.$file), 'tracked_source_type');
        $sourceFiles[$file] = hash_file('sha256', APP.'/'.$file);
    }
    foreach (['error_log', 'novinpay-stage3v2-preview/public/.htaccess'] as $file) {
        need(is_file(APP.'/'.$file) && ! is_link(APP.'/'.$file), 'retained_source_file');
        $sourceFiles[$file] = hash_file('sha256', APP.'/'.$file);
    }
    $assets = filesManifest(APP.'/public/build');

    $GLOBALS['np_backup_check'] = 'fresh_backup_quota';
    \NovinPay\Release\requestQuota($manifest, __DIR__, 'backup', max(805306368, 4 * $dbBytes + 3 * $appBytes + 268435456));

    return compact('manifest', 'optionFile', 'env', 'credentials', 'source', 'restore', 'dbMetadata', 'schema', 'storage', 'sourceFiles', 'assets', 'hashes', 'maintenanceHashes', 'retainedEvidence', 'stateDir');
}
function execute(array $context): array
{
    extract($context, EXTR_SKIP);
    $ownedOptionFile = false;
    try {
        need(mkdir(OUT, 0700), 'exclusive_backup_directory');
        \NovinPay\Release\writeNew(OUT.'/attempt.json', ['manifest' => $manifest['_sha256'], 'baseline' => BASELINE, 'restore' => RESTORE_DB, 'startedUtc' => gmdate('c')]);
        runProcess(['git', '-C', APP, 'rev-parse', 'HEAD'], null, OUT.'/source-commit.txt');
        need(trim(file_get_contents(OUT.'/source-commit.txt')) === BASELINE, 'unexpected_production_source');

        putPrivate(OUT.'/database-metadata.json', json_encode($dbMetadata, JSON_THROW_ON_ERROR));
        $ownedOptionFile = true;
        putPrivate($optionFile, clientConfig($credentials['user'], $credentials['password']));
        unset($credentials,$env);
        $common = ['--defaults-file='.$optionFile, '--single-transaction', '--skip-lock-tables', '--quick', '--hex-blob', '--order-by-primary', '--skip-extended-insert', '--complete-insert', '--skip-comments', '--skip-add-locks', '--skip-disable-keys', '--default-character-set=utf8mb4', '--tz-utc', '--no-tablespaces'];
        $snapshotUnix = time();
        $GLOBALS['np_backup_check'] = 'consistent_production_export';
        runProcess(array_merge(['/bin/mariadb-dump'], $common, ['--skip-add-drop-table', '--skip-add-drop-trigger', '--triggers', SOURCE_DB]), null, OUT.'/database.sql');
        need(filesize(OUT.'/database.sql') > 1000 && schema($source, SOURCE_DB) === $schema, 'dump_or_source_schema_changed');
        $snapshot = dataDigest(OUT.'/database.sql');
        need(! empty($snapshot['insertRows']['migrations']) && $snapshot['insertRows']['migrations'] === 14 && ! empty($snapshot['insertRows']['users']), 'dump_missing_required_rows');
        $GLOBALS['np_backup_check'] = 'dedicated_disposable_restore';
        runProcess(['/bin/mariadb', '--defaults-file='.$optionFile, "--init-command=SET SESSION explicit_defaults_for_timestamp=1, time_zone='+00:00', check_constraint_checks=1, foreign_key_checks=1", '--default-character-set=utf8mb4', RESTORE_DB], OUT.'/database.sql', OUT.'/restore-output.txt');
        need(schema($restore, RESTORE_DB) === $schema, 'restored_schema_or_definer_mismatch');
        runProcess(array_merge(['/bin/mariadb-dump'], $common, ['--no-create-info', '--skip-triggers', RESTORE_DB]), null, OUT.'/restored-data.sql');
        need(dataDigest(OUT.'/restored-data.sql') === $snapshot, 'restored_all_rows_mismatch');
        runProcess(['git', '-C', APP, 'status', '--porcelain', '--untracked-files=no'], null, OUT.'/source-working-tree.txt');
        need(in_array(trim(file_get_contents(OUT.'/source-working-tree.txt')), ['', 'M public/.htaccess'], true), 'unexpected_source_working_tree');
        $GLOBALS['np_backup_check'] = 'application_and_persistent_storage_archive';
        $instructions = 'Recovery source: '.BASELINE."\nDatabase: ".SOURCE_DB."; five triggers retain novinpay_mig1 definers.\nRestore only into an empty isolated database first. Never import automatically over a live database.\nApplication root: ".APP."; public_html symlink must stay unchanged.\nKeep production .env/APP_KEY and the included .stage2-credentials.json private. Match database-metadata.json defaults when creating a fresh restore destination. Set restore-client session explicit_defaults_for_timestamp=1 before importing. Never restore through the runtime account. Keep production .env/APP_KEY from this encrypted archive. Do not generate a new key.\nComposer dependencies and prebuilt assets are included; no Node installation is needed.\nRuntime cache/views/logs and Git object history are reproducible and excluded. The private state.json and audit.jsonl at the archive root belong to storage/framework/site-maintenance and must be preserved/recovered together with 0700 directory and 0600 file modes; never discard audit history as a code rollback.\nData rollback is a separately reviewed maintenance operation, never an automatic code rollback.\n";
        putPrivate(OUT.'/RECOVERY.txt', $instructions);
        putPrivate(OUT.'/release-metadata.json', json_encode(['sourceCommit' => BASELINE, 'sourceDatabase' => SOURCE_DB, 'protectedFileHashes' => $hashes, 'persistentFileHashes' => $storage, 'sourceFileHashes' => $sourceFiles, 'assetFileHashes' => $assets, 'sourceBranch' => 'codex/novinpay-release-contact-corrections', 'retainedDiagnosticEvidence' => $retainedEvidence, 'maintenanceStateFileHashes' => $maintenanceHashes], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        runProcess(['/bin/tar', '-czf', '-', '--exclude=novinpay/.git', '--exclude=novinpay/storage/logs', '--exclude=novinpay/storage/framework', '--exclude=novinpay/bootstrap/cache', '-C', '/home/novinpay', 'novinpay', '-C', OUT, 'database.sql', 'database-metadata.json', 'release-metadata.json', 'RECOVERY.txt', '-C', dirname(CREDS), '.stage2-credentials.json', '-C', $stateDir, 'state.json', 'audit.jsonl'], null, OUT.'/bundle.tar.gz');
        need(filesize(OUT.'/bundle.tar.gz') <= 64 * 1024 * 1024, 'archive_exceeds_verified_memory_bound');
        need(filesManifest(APP.'/storage/app') === $storage, 'persistent_storage_changed_during_backup');
        foreach ($hashes as $file => $hash) {
            need(hash_file('sha256', APP.'/'.$file) === $hash, 'production_file_changed_during_backup');
        }
        foreach (['.env', 'public/.htaccess', 'public/build/manifest.json'] as $file) {
            runProcess(['/bin/tar', '-xOzf', OUT.'/bundle.tar.gz', 'novinpay/'.$file], null, OUT.'/archive-check.bin');
            need(hash_file('sha256', OUT.'/archive-check.bin') === $hashes[$file], 'archive_configuration_mismatch');
        }
        foreach ($storage as $file => $hash) {
            runProcess(['/bin/tar', '-xOzf', OUT.'/bundle.tar.gz', 'novinpay/storage/app/'.$file], null, OUT.'/archive-check.bin');
            need(hash_file('sha256', OUT.'/archive-check.bin') === $hash, 'archive_upload_mismatch');
        }
        runProcess(['/bin/tar', '-xOzf', OUT.'/bundle.tar.gz', 'database.sql'], null, OUT.'/archive-check.bin');
        need(hash_file('sha256', OUT.'/archive-check.bin') === hash_file('sha256', OUT.'/database.sql'), 'archive_database_mismatch');
        foreach ($maintenanceHashes as $file => $hash) {
            runProcess(['/bin/tar', '-xOzf', OUT.'/bundle.tar.gz', $file], null, OUT.'/archive-check.bin');
            need(hash_equals($hash, hash_file('sha256', OUT.'/archive-check.bin')), 'archive_private_state_mismatch');
        }
        foreach ($sourceFiles as $file => $hash) {
            need(hash_equals($hash, hash_file('sha256', APP.'/'.$file)), 'source_changed_during_backup');
        }
        need(filesManifest(APP.'/public/build') === $assets, 'assets_changed_during_backup');
        need(\NovinPayBackupWorktreeV2\verify(APP) === $retainedEvidence, 'retained_evidence_changed_during_backup');
        foreach ($manifest['maintenanceHashes'] as $file => $expected) {
            need(hash_equals($expected, hash_file('sha256', $stateDir.'/'.$file)), 'offhost_state_snapshot_changed');
        }

        $GLOBALS['np_backup_check'] = 'authenticated_encryption_and_recovery_check';
        $key = base64_decode(trim(file_get_contents(KEY_FILE)), true);
        need(is_string($key) && strlen($key) === 32, 'invalid_backup_key');
        $mem = trim(ini_get('memory_limit'));
        $suffix = strtolower(substr($mem, -1));
        $limit = (int) $mem * (['k' => 1024, 'm' => 1048576, 'g' => 1073741824][$suffix] ?? 1);
        need($mem === '-1' || memory_get_usage(true) + 5 * filesize(OUT.'/bundle.tar.gz') < $limit, 'encryption_memory_headroom');
        $plain = file_get_contents(OUT.'/bundle.tar.gz');
        $plainHash = hash('sha256', $plain);
        $encrypted = encryptBundle($plain, $key);
        unset($plain);
        putPrivate(OUT.'/backup.npb', $encrypted);
        unset($encrypted);
        $verified = decryptBundle(file_get_contents(OUT.'/backup.npb'), $key);
        unset($key);
        need(hash('sha256', $verified) === $plainHash, 'decrypted_archive_hash_mismatch');
        unset($verified);
        $GLOBALS['np_backup_check'] = 'encrypted_offhost_transport_parts';
        $parts = [];
        $handle = fopen(OUT.'/backup.npb', 'rb');
        $index = 0;
        while (! feof($handle)) {
            $bytes = fread($handle, 196608);
            need($bytes !== false, 'encrypted_read');
            if ($bytes === '') {
                break;
            } $name = sprintf('encrypted-part-%04d.txt', $index++);
            putPrivate(OUT.'/'.$name, base64_encode($bytes));
            $parts[] = ['file' => $name, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
        }
        fclose($handle);
        foreach (['database.sql', 'restored-data.sql', 'database-metadata.json', 'bundle.tar.gz', 'archive-check.bin', 'client.cnf', 'private-process-error.txt', 'restore-output.txt', 'tar-output.txt'] as $temporary) {
            if (is_file(OUT.'/'.$temporary)) {
                need(unlink(OUT.'/'.$temporary), 'private_temporary_cleanup_failed');
            }
        }
        // Retain the exact server key until independent off-host recovery is verified.

        $receipt = ['result' => 'BACKUP_AND_DISPOSABLE_RESTORE_PASS', 'createdUtc' => gmdate('c'), 'sourceCommit' => BASELINE, 'sourceDatabase' => SOURCE_DB, 'restoreDatabase' => RESTORE_DB, 'migrations' => 14, 'triggers' => 5, 'schemaAndDefinersMatch' => true, 'allSnapshotRowsMatch' => true, 'snapshotDataDigest' => $snapshot['sha256'], 'persistentFiles' => count($storage), 'privateMaintenanceStateVerified' => true, 'environmentPreserved' => true, 'publicHtmlUnchanged' => readlink('/home/novinpay/public_html') === APP.'/public', 'archiveSha256' => hash_file('sha256', OUT.'/backup.npb'), 'decryptedTarSha256' => $plainHash, 'archiveBytes' => filesize(OUT.'/backup.npb'), 'parts' => $parts, 'serverPlaintextRemoved' => true, 'serverKeyRetained' => true, 'snapshotUnix' => $snapshotUnix, 'offHostCopyVerified' => false, 'productionUpdated' => false];
        // Publish only a complete, fsynced receipt: the off-host bridge may be polling.
        \NovinPay\Release\writeNew(OUT.'/receipt.json', $receipt);
        echo "BACKUP AND RESTORE PASS\nSource database: ".SOURCE_DB."\nDisposable restore: ".RESTORE_DB."\nMigrations: 14; triggers: 5; schema/definers and every snapshot row: MATCH\nEncrypted backup: ".OUT."/backup.npb\nArchive SHA256: ".$receipt['archiveSha256']."\nProduction updated: NO\nOff-host copy: pending automated API transfer\n";

        return $receipt;

    } finally {
        if ($ownedOptionFile && is_file($optionFile) && ! is_link($optionFile)) {
            unlink($optionFile);
        }
    }
}
