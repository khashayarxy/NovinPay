<?php

declare(strict_types=1);

use App\Services\CustomerLogin;
use App\Services\CustomerOtpSms;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use NovinPay\Release\GateFailure;

use function NovinPay\Release\command;
use function NovinPay\Release\privateRead;
use function NovinPay\Release\recoveryGit;
use function NovinPay\Release\requireGate;
use function NovinPay\Release\sourceCompletionEvidence;
use function NovinPay\Release\sourceHttpsAcceptance;
use function NovinPay\Release\sourceRecoveryEvidence;
use function NovinPay\Release\sourceReleaseManifest;
use function NovinPay\Release\writeNew;

const KIT = '/home/novinpay/novinpay-direct-pr13';
const APP = '/home/novinpay/novinpay';

function ensure(bool $ok, string $check): void
{
    requireGate($ok, $check, 'independently verified post-deployment state', [], 'Preserve the attempt and reconcile this check before recording completion.');
}

function hashList(string $contents, array $paths, string $root, string $check): void
{
    $seen = [];
    foreach (explode("\n", rtrim($contents, "\n")) as $line) {
        ensure(preg_match('/\A([a-f0-9]{64})  (.+)\z/D', $line, $fields) === 1
            && in_array($fields[2], $paths, true) && ! isset($seen[$fields[2]]), $check);
        $file = $root.$fields[2];
        ensure(is_file($file) && ! is_link($file) && hash_equals($fields[1], hash_file('sha256', $file)), $check);
        $seen[$fields[2]] = true;
    }
    ensure(count($seen) === count($paths), $check);
}

function verifyCartSchema(PDO $db): void
{
    $schema = [
        'shopping_carts' => ['scope' => 'varchar:64:NO:PRI', 'state' => 'longtext::NO:'],
        'legacy_cart_holds' => ['reference' => 'char:36:NO:PRI', 'payload' => 'text::NO:',
            'session_digest' => 'varchar:64:NO:', 'device_scope' => 'varchar:64:YES:', 'migrated_at' => 'timestamp::YES:', 'captured_at' => 'timestamp::NO:'],
        'cart_purchase_snapshots' => ['sales_order_id' => 'bigint::NO:PRI', 'sources' => 'longtext::NO:',
            'delivery' => 'text::YES:', 'consumed_at' => 'timestamp::YES:'],
    ];
    $tables = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $columns = $db->prepare('SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_KEY, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    foreach ($schema as $name => $expected) {
        $tables->execute([$name]);
        ensure($tables->fetchColumn() === 'InnoDB', 'cart_table_engine');
        $columns->execute([$name]);
        $actual = [];
        foreach ($columns->fetchAll(PDO::FETCH_NUM) as [$column, $type, $length, $nullable, $key, $definition]) {
            $length = in_array($type, ['char', 'varchar'], true) ? $length : '';
            $actual[$column] = "$type:$length:$nullable:$key";
            if ($column === 'sales_order_id') {
                ensure(str_contains($definition, 'unsigned'), 'cart_snapshot_key_type');
            }
        }
        ensure($actual === $expected, 'cart_table_columns');
    }
    $jsonChecks = $db->query("SELECT TABLE_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ('shopping_carts','cart_purchase_snapshots')")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['shopping_carts' => 'state', 'cart_purchase_snapshots' => 'sources'] as $table => $column) {
        ensure(str_replace(['`', ' '], '', strtolower($jsonChecks[$table] ?? '')) === "json_valid($column)", 'cart_json_constraints');
    }
    $references = $db->query("SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r USING (CONSTRAINT_SCHEMA,CONSTRAINT_NAME,TABLE_NAME) WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='cart_purchase_snapshots'")->fetchAll(PDO::FETCH_NUM);
    ensure($references === [['sales_order_id', 'novinpay_live1', 'sales_orders', 'id', 'CASCADE']], 'cart_snapshot_foreign_key');
}

function databaseEvidence(array $manifest): array
{
    $credentialPath = '/home/novinpay/novinpay-releases/155fc9f30feb5b9d0c41445b33f1118d5e07da8e/.stage2-credentials.json';
    $raw = privateRead($credentialPath);
    ensure(hash_equals($manifest['credentialsSha256'], hash('sha256', $raw)), 'observer_credential_pin');
    $credential = json_decode($raw, true, 16, JSON_THROW_ON_ERROR)['migrator'];
    ensure($credential['user'] === 'novinpay_mig1', 'observer_account');
    $db = new PDO('mysql:host=localhost;port=3306;dbname=novinpay_live1;charset=utf8mb4', $credential['user'], $credential['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($raw, $credential);
    $identity = $db->query('SELECT DATABASE(), CURRENT_USER(), VERSION()')->fetch(PDO::FETCH_NUM);
    ensure($identity[0] === 'novinpay_live1' && $identity[1] === 'novinpay_mig1@localhost' && str_starts_with($identity[2], '10.6.28'), 'observer_database_identity');
    $restore = $manifest['backup']['restoreDatabase'];
    ensure(preg_match('/\Anovinpay_restore[a-z0-9]+\z/D', $restore) === 1, 'baseline_database_scope');
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    try {
        $current = $db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        $baseline = $db->query("SELECT migration FROM `$restore`.migrations ORDER BY migration")->fetchAll(PDO::FETCH_COLUMN);
        ensure($current === $manifest['candidateMigrations'] && $baseline === $manifest['migrations'], 'live_and_restored_migration_ledgers');
        verifyCartSchema($db);
        $columns = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $checks = [];
        foreach (['customers', 'customer_accounts', 'repair_cases', 'sales_orders', 'sales_order_lines', 'payment_attempts',
            'payment_receipts', 'payment_transactions', 'inventory_items', 'inventory_units'] as $table) {
            $columns->execute([$restore, $table]);
            $names = $columns->fetchAll(PDO::FETCH_COLUMN);
            ensure(in_array('id', $names, true), 'business_baseline_primary_key');
            $comparisons = [];
            foreach ($names as $column) {
                ensure(preg_match('/\A[a-z0-9_]+\z/D', $column) === 1, 'business_column_scope');
                $comparisons[] = "(BINARY b.`$column` <=> BINARY l.`$column`)";
            }
            $same = implode(' AND ', $comparisons);
            $result = $db->query("SELECT COUNT(*), COALESCE(SUM(l.id IS NULL),0), COALESCE(SUM(l.id IS NOT NULL AND NOT ($same)),0) FROM `$restore`.`$table` b LEFT JOIN `novinpay_live1`.`$table` l ON l.id=b.id")->fetch(PDO::FETCH_NUM);
            $checks[$table] = ['baselineRows' => (int) $result[0], 'missing' => (int) $result[1], 'changed' => (int) $result[2]];
        }

        return ['migrations' => count($current), 'cartTables' => 3, 'baselineComparisons' => $checks];
    } finally {
        $db->rollBack();
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);

$check = 'startup';
try {
    require __DIR__.'/recovery-contract.php';
    require_once __DIR__.'/source-deployment-state.php';
    $mode = $argv[4] ?? '';
    ensure(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && posix_geteuid() === 1321
        && in_array($mode, ['inspect', 'record'], true) && count($argv) === ($mode === 'inspect' ? 5 : 6), 'reconciliation_scope');
    [, $manifestPath, $pin, $checksPin] = $argv;
    ensure(dirname($manifestPath) === KIT && preg_match('/\A[a-f0-9]{64}\z/D', $pin) === 1
        && preg_match('/\A[a-f0-9]{64}\z/D', $checksPin) === 1, 'reconciliation_inputs');
    $raw = privateRead($manifestPath);
    ensure(hash_equals($pin, hash('sha256', $raw)), 'reconciliation_manifest_pin');
    $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $manifest['_sha256'] = $pin;
    sourceReleaseManifest($manifest);
    $release = $manifest['releaseName'];
    ensure($manifest['format'] === 'novinpay-application-release-v1' && $manifest['successResult'] === 'APPLICATION_RELEASE_PASS'
        && $manifestPath === KIT."/$release.json", 'reconciliation_application_scope');
    $journal = KIT."/$release-attempt";
    $tools = "tools-$release";
    $lockPath = KIT.'/.operation.lock';
    ensure(is_file($lockPath) && ! is_link($lockPath) && fileowner($lockPath) === 1321 && (fileperms($lockPath) & 0777) === 0600, 'operation_lock_identity');
    $lock = fopen($lockPath, 'rb');
    ensure(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'operation_already_running');
    ensure(is_dir($journal) && ! is_link($journal) && fileowner($journal) === 1321 && (fileperms($journal) & 0777) === 0700, 'reconciliation_journal_identity');

    $check = 'pinned_release_files';
    $checksum = privateRead(KIT."/$release.sha256");
    ensure(hash_equals($checksPin, hash('sha256', $checksum)), 'reconciliation_checksum_list');
    $memberPaths = ["$release.json", "$release-source.txt", "$release-vite.zip", "$release-assets.sha256"];
    foreach (['activate-source.php', 'activate-source.sh', 'recovery-contract.php', 'runtime.php', 'lib.php',
        'schema-contract.php', 'trigger-contracts.json', 'backup-policy.php', 'launch-detached.py'] as $name) {
        $memberPaths[] = "$tools/$name";
    }
    if (is_file(KIT."/$tools/source-deployment-state.php")) {
        $memberPaths[] = "$tools/source-deployment-state.php";
    }
    hashList($checksum, $memberPaths, KIT.'/', 'reconciliation_release_bytes');
    sourceRecoveryEvidence($manifest);
    $dispatch = KIT.'/dispatch-deploy-'.$pin;
    ensure(is_dir($dispatch) && ! is_link($dispatch), 'detached_dispatch_identity');
    privateRead($dispatch.'/launch.json');
    $workerPath = $dispatch.'/complete.json';
    $worker = file_exists($workerPath) || is_link($workerPath) ? json_decode(privateRead($workerPath), true, 16, JSON_THROW_ON_ERROR) : null;
    ensure($worker === null || (($worker['result'] ?? null) === 'runner_exited' && is_int($worker['exitCode'] ?? null)), 'completed_worker_evidence');
    foreach (['started', 'complete'] as $phase) {
        $step = json_decode(privateRead($journal.'/migration-'.$phase.'.json'), true, 16, JSON_THROW_ON_ERROR);
        ensure(($step['manifestSha256'] ?? null) === $pin
            && ($phase === 'started' || ($step['expectedMigrations'] ?? null) === $manifest['candidateMigrations']), 'migration_journal_binding');
    }
    foreach (['storage/framework/down', 'storage/framework/maintenance.php', 'public/hot'] as $file) {
        ensure(! file_exists(APP.'/'.$file) && ! is_link(APP.'/'.$file), 'production_maintenance_state');
    }
    ensure(trim(recoveryGit(APP, ['rev-parse', 'HEAD'])) === $manifest['target']
        && trim(recoveryGit(APP, ['diff', '--cached', '--name-only'])) === ''
        && trim(recoveryGit(APP, ['diff', '--name-only', '--', '.', ':(exclude)public/.htaccess'])) === '', 'live_worktree_identity');
    hashList(privateRead($journal.'/protected.sha256'), ['.env', 'public/.htaccess', 'storage/app/private/support-chat/installation.env',
        'storage/framework/site-maintenance/state.json', 'storage/framework/site-maintenance/audit.jsonl', 'public/build/support-chat/manifest.json'], APP.'/', 'protected_files_unchanged');
    hashList(privateRead($journal.'/rollback.sha256'), [$journal.'/baseline-source.tar', $journal.'/vite-manifest-before.json'], '', 'rollback_file_evidence');
    hashList(privateRead(KIT."/$release-assets.sha256"), ['manifest.json', $manifest['cssAsset']], APP.'/public/build/', 'published_asset_integrity');

    $check = 'candidate_runtime';
    $runtime = command([PHP_BINARY, '-d', 'session.gc_divisor=100', '-d', 'opcache.enable_cli=0', KIT."/$tools/activate-source.php", $manifestPath, $pin, 'ready'], APP, 'ready_readonly');
    ensure($runtime['exit'] === 0 && trim($runtime['stdout']) === 'ADMIN_RUNTIME_PASS', 'candidate_runtime_contract');
    require APP.'/vendor/autoload.php';
    $app = require APP.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $runtimeDb = DB::connection()->getPdo();
    ensure($runtimeDb->query('SELECT DATABASE()')->fetchColumn() === 'novinpay_live1'
        && $app->configurationIsCached() && $app->routesAreCached() && config('app.debug') === false, 'cached_runtime_identity');
    $otp = app(CustomerOtpSms::class);
    $login = app(CustomerLogin::class);
    $phone = config('customer.test_sms_phone');
    ensure($otp->provider() === 'ippanel' && $otp->enabled() && $otp->configured() && config('customer.test_reset_on_logout') === true
        && is_string($phone) && $login->testUsesSms($phone) && $login->privateTestCode($phone) === null, 'ippanel_ready_without_sending');
    foreach (['payments.zibal.enabled', 'payments.zibal.live_requests', 'operations.scheduled_payments'] as $flag) {
        ensure(config($flag, false) === false, 'payment_flags_off');
    }
    $check = 'database_contract';
    $database = databaseEvidence($manifest);
    $issues = array_filter($database['baselineComparisons'], fn (array $row) => $row['missing'] !== 0 || $row['changed'] !== 0);
    $check = 'https_acceptance';
    $http = $manifest;
    $http['httpChecks'] += ['/cart' => 200, '/cart/checkout' => 302, '/bot/payment_verify.php' => 404];
    foreach (['/build/'.$manifest['cssAsset'], '/pay/assets/vpn-warning.css', '/pay/assets/vpn-warning.js'] as $path) {
        $http['httpChecks'][$path] = ['status' => 200, 'sha256' => hash_file('sha256', APP.'/public'.$path)];
    }
    sourceHttpsAcceptance($http);
    $evidence = ['manifestSha256' => $pin, 'commit' => $manifest['target'], 'originalWorkerExitCode' => $worker['exitCode'] ?? null,
        'triggers' => $manifest['expectedTriggers'], 'paymentFlagsOff' => true, 'ippanelReady' => true, 'maintenanceActive' => false] + $database;
    $evidencePin = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
    if ($mode === 'inspect' || $issues) {
        echo json_encode(['result' => $issues ? 'APPLICATION_DATA_REVIEW_REQUIRED' : 'APPLICATION_RECONCILIATION_READY', 'evidenceSha256' => $evidencePin] + $evidence, JSON_THROW_ON_ERROR).PHP_EOL;
        exit($issues ? 1 : 0);
    }
    ensure(hash_equals($evidencePin, $argv[5]), 'reconciliation_evidence_changed');
    $recordPath = $journal.'/reconciliation-verified.json';
    if (! file_exists($recordPath) && ! is_link($recordPath)) {
        writeNew($recordPath, $evidence);
    }
    ensure(json_decode(privateRead($recordPath), true, 32, JSON_THROW_ON_ERROR) === $evidence, 'reconciliation_existing_evidence_conflict');
    $verified = sourceCompletionEvidence($manifest) + ['reconciled' => true, 'evidenceSha256' => $evidencePin, 'originalWorkerExitCode' => $worker['exitCode'] ?? null];
    $complete = $journal.'/complete.json';
    if (! file_exists($complete) && ! is_link($complete)) {
        writeNew($complete, $verified + ['completedUtc' => gmdate('c')]);
    }
    $receipt = json_decode(privateRead($complete), true, 16, JSON_THROW_ON_ERROR);
    ensure(array_intersect_key($receipt, $verified) === $verified, 'reconciliation_existing_receipt_conflict');
    echo json_encode($receipt, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['result' => 'APPLICATION_RECONCILIATION_STOP', 'check' => $error instanceof GateFailure ? $error->check : $check,
        'retrySafe' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
