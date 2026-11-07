<?php

declare(strict_types=1);

namespace NovinPay\Release\Pr13;

use NovinPay\Release\Journal;
use PDO;
use Throwable;

use function NovinPay\Release\privateRead;
use function NovinPay\Release\writeNew;

require_once __DIR__.'/pr13-activate.php';

if (realpath(($_SERVER['SCRIPT_FILENAME'] ?? '') ?: ($argv[0] ?? '')) !== __FILE__) {
    return;
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
$lock = null;
try {
    need(PHP_SAPI === 'cli' && posix_geteuid() === 1321 && KIT.'/pr13-activation-72h-v3' === __DIR__ && count($argv) === 3, 'cancellation_scope');
    $argv[1] = manifestPath($argv[1], TOOLS);
    $text = privateRead($argv[1]);
    need(hash_equals($argv[2], hash('sha256', $text)), 'cancellation_manifest_pin');
    $m = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    need($m['mode'] === 'pr13-migration-activation-v1' && $m['stateDirectory'] === STATE && $m['toolDirectory'] === TOOLS
        && $m['baseline'] === '1f39bb476fd31880600564e58599c4488622c4c0', 'cancellation_manifest_scope');
    foreach ($m['tools'] as $file => $sha) {
        need(preg_match('/\A[a-z0-9-]+\.php\z/D', $file) && hash_equals($sha, hash('sha256', privateRead(TOOLS.'/'.$file))), 'cancellation_tool_identity');
    }
    $lock = new Journal(STATE, $argv[2]);
    need(! file_exists(STATE.'/ddl.started.json') && ! file_exists(STATE.'/source.started.json')
        && ! file_exists(STATE.'/admission.started.json') && ! file_exists(STATE.'/cancellation.started.json'), 'cancellation_not_safe_preserve_fences');
    $owner = json_decode(privateRead(STATE.'/fence-owner.json'), true, flags: JSON_THROW_ON_ERROR);
    $fence = json_decode(privateRead(STATE.'/fence.complete.json'), true, flags: JSON_THROW_ON_ERROR);
    need($owner['manifest'] === $argv[2] && $fence['manifest'] === $argv[2]
        && hash_equals($fence['downSha256'], hash('sha256', privateRead(APP.'/storage/framework/down')))
        && hash_equals($owner['webSha256'], hash('sha256', privateRead(APP.'/storage/framework/maintenance.php')))
        && hash_equals($owner['cliSha256'], hash_file('sha256', APP.'/artisan'))
        && trim(run(['git', 'rev-parse', 'HEAD'])) === $m['baseline'], 'cancellation_owned_baseline');
    $proof = json_decode(privateRead(KIT.'/pr13-offhost-proof-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    need(hash_equals($m['offhostProofSha256'], hash_file('sha256', KIT.'/pr13-offhost-proof-v1.json')), 'cancellation_proof_pin');
    foreach ($proof['protectedFileHashes'] as $file => $sha) {
        need(in_array($file, ['.env', 'public/.htaccess', 'public/build/manifest.json', 'composer.lock', 'package-lock.json'], true)
            && ! is_link(APP.'/'.$file) && hash_equals($sha, hash_file('sha256', APP.'/'.$file)), 'cancellation_original_files');
    }
    define('APP', APP);
    define('SOURCE_DB', 'novinpay_live1');
    require TOOLS.'/production-environment.php';
    \verifiedProductionEnvironment();
    $credentials = '/home/novinpay/novinpay-releases/155fc9f30feb5b9d0c41445b33f1118d5e07da8e/.stage2-credentials.json';
    need(hash_equals($m['credentialsSha256'], hash('sha256', privateRead($credentials))), 'cancellation_credentials');
    $mig = json_decode(privateRead($credentials), true, flags: JSON_THROW_ON_ERROR)['migrator'];
    need($mig['user'] === 'novinpay_mig1', 'cancellation_observer');
    $db = new PDO('mysql:host=localhost;dbname=novinpay_live1;charset=utf8mb4', $mig['user'], $mig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('SET SESSION TRANSACTION READ ONLY');
    need($db->query('SELECT DATABASE()')->fetchColumn() === 'novinpay_live1' && $db->query('SELECT CURRENT_USER()')->fetchColumn() === 'novinpay_mig1@localhost', 'cancellation_database_identity');
    $expected = $m['baselineMigrations'];
    sort($expected);
    need($db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN) === $expected && count($expected) === 14, 'cancellation_baseline_schema');
    \pr13VerifyTriggerContracts(triggerRows($db));
    foreach ($m['maintenanceHashes'] as $file => $sha) {
        need(hash_equals($sha, hash('sha256', privateRead(APP.'/storage/framework/site-maintenance/'.$file))), 'cancellation_private_history');
    }
    $original = privateRead(STATE.'/original-artisan');
    need(hash_equals($owner['artisanSha256'], hash('sha256', $original))
        && hash_equals($owner['artisanSha256'], hash('sha256', run(['git', 'show', $m['baseline'].':artisan']))), 'cancellation_original_entry');
    writeNew(STATE.'/cancellation.started.json', ['manifest' => $argv[2], 'verifiedMigrations' => 14, 'verifiedTriggers' => 5]);
    replaceOwned(APP.'/artisan', $owner['cliSha256'], $original);
    need(unlink(APP.'/storage/framework/maintenance.php') && unlink(APP.'/storage/framework/down'), 'cancellation_fence_removal');
    writeNew(STATE.'/cancellation.complete.json', ['result' => 'PR13_PRE_DDL_CANCELLATION_PASS', 'manifest' => $argv[2], 'baseline' => $m['baseline'], 'databaseWrites' => false, 'activationReplayAllowed' => false]);
    echo json_encode(['result' => 'PR13_PRE_DDL_CANCELLATION_PASS', 'maintenanceEnabled' => false, 'activationReplayAllowed' => false]).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(\NovinPay\Release\failureReport($error, 'pre_ddl_cancellation', 'cancellation', false)).PHP_EOL;
    exit(1);
} finally {
    $lock?->close();
}
