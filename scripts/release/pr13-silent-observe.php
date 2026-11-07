<?php

declare(strict_types=1);

// Deliberately no SCRIPT_FILENAME dispatch guard: this is a CLI-only diagnostic,
// never imported by activation. Install privately; it never executes activation.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$finished = false;
$phase = 'entry_observation';
register_shutdown_function(static function () use (&$finished, &$phase): void {
    if (! $finished) {
        echo json_encode(['result' => 'PR13_READONLY_SILENT_INSPECTION_INTERRUPTED', 'phase' => $phase,
            'retrySafe' => false, 'activationRetried' => false]).PHP_EOL;
    }
});
$observation = [];
try {
    if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 1321
        || __DIR__ !== '/home/novinpay/novinpay-release-pipeline-v1/pr13-silent-observation-v1') {
        throw new RuntimeException('readonly_inspection_scope');
    }
    $root = '/home/novinpay/novinpay-release-pipeline-v1';
    $tools = $root.'/pr13-activation-72h-v2';
    $observation['entry'] = ['phpVersion' => PHP_VERSION, 'sapi' => PHP_SAPI, 'effectiveUid' => posix_geteuid(),
        'serverFilenamePresent' => isset($_SERVER['SCRIPT_FILENAME']), 'argvZeroPresent' => isset($argv[0]),
        'serverFilenameMatchesThisFile' => realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__,
        'argvZeroMatchesThisFile' => realpath($argv[0] ?? '') === __FILE__,
        'variablesOrderIncludesServer' => str_contains(ini_get('variables_order'), 'S'),
        'autoPrependConfigured' => ini_get('auto_prepend_file') !== '', 'autoAppendConfigured' => ini_get('auto_append_file') !== '',
        'cliOpcacheEnabled' => (bool) ini_get('opcache.enable_cli'), 'opcacheUseCwd' => (bool) ini_get('opcache.use_cwd'),
        'persistentOpcacheConfigured' => is_string(ini_get('opcache.file_cache')) && ini_get('opcache.file_cache') !== '',
        'opcacheExtensionLoaded' => extension_loaded('Zend OPcache'), 'opcacheDirectivesAvailable' => ini_get('opcache.enable_cli') !== false,
        'sessionGcDivisorPositive' => (int) ini_get('session.gc_divisor') > 0];
    require_once $tools.'/runtime.php';
    require_once $tools.'/pr13-activation-contract.php';
    require_once $tools.'/pr13-schema-contract.php';
    $read = 'NovinPay\\Release\\privateRead';
    $need = 'NovinPay\\Release\\Pr13\\need';
    $text = $read($tools.'/pr13-activation-72h-v2.json');
    $need(hash_equals('c8e87e328b2df64c65e846fca7aa0c78ba1ea439cf61b3f2f7954c7dde818dee', hash('sha256', $text)), 'inspection_manifest_pin');
    $m = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    foreach ($m['tools'] as $file => $sha) {
        $need(preg_match('/\A[a-z0-9-]+\.php\z/D', $file) === 1 && hash_equals($sha, hash('sha256', $read($tools.'/'.$file))), 'inspection_tool_pin');
    }
    $observation['entry']['serverFilenamePresentAfterImports'] = isset($_SERVER['SCRIPT_FILENAME']);
    $phase = 'readonly_filesystem_state';
    $journal = $root.'/pipeline-pr13-activation-r2';
    $observation['journalExists'] = is_dir($journal);
    foreach (['activation.started.json', 'fence.complete.json', 'ddl.started.json', 'ddl.complete.json', 'source.started.json',
        'readiness.complete.json', 'admission.started.json', 'activation.complete.json', 'activation.failed.json'] as $file) {
        $observation['markers'][$file] = file_exists($journal.'/'.$file) || is_link($journal.'/'.$file);
    }
    define('APP', '/home/novinpay/novinpay');
    define('SOURCE_DB', 'novinpay_live1');
    foreach (['down', 'maintenance.php'] as $file) {
        $observation['fences'][$file] = file_exists(APP.'/storage/framework/'.$file) || is_link(APP.'/storage/framework/'.$file);
    }
    $owner = json_decode($read($root.'/pipeline-pr13-activation-r1/fence-owner.json'), true, flags: JSON_THROW_ON_ERROR);
    $observation['artisanMatchesBaseline'] = ! is_link(APP.'/artisan') && hash_equals($owner['artisanSha256'], hash_file('sha256', APP.'/artisan'));
    $need(is_file(APP.'/.git/HEAD') && ! is_link(APP.'/.git/HEAD'), 'inspection_git_head_type');
    $ref = trim(file_get_contents(APP.'/.git/HEAD'));
    $need($ref === 'ref: refs/heads/codex/novinpay-release-contact-corrections', 'inspection_git_branch');
    $refFile = APP.'/.git/'.substr($ref, 5);
    $need(is_file($refFile) && ! is_link($refFile), 'inspection_git_ref_type');
    $observation['productionCommit'] = trim(file_get_contents($refFile));
    $proof = json_decode($read($root.'/pr13-offhost-proof-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    $need(hash_equals($m['offhostProofSha256'], hash_file('sha256', $root.'/pr13-offhost-proof-v1.json')), 'inspection_proof_pin');
    $observation['protectedFilesMatchBackup'] = true;
    foreach ($proof['protectedFileHashes'] as $file => $sha) {
        $need(in_array($file, ['.env', 'public/.htaccess', 'public/build/manifest.json', 'composer.lock', 'package-lock.json'], true), 'inspection_protected_scope');
        $observation['protectedFilesMatchBackup'] = $observation['protectedFilesMatchBackup'] && ! is_link(APP.'/'.$file) && hash_equals($sha, hash_file('sha256', APP.'/'.$file));
    }
    $phase = 'database_readonly';
    require $tools.'/production-environment.php';
    verifiedProductionEnvironment();
    $credentials = '/home/novinpay/novinpay-releases/155fc9f30feb5b9d0c41445b33f1118d5e07da8e/.stage2-credentials.json';
    $text = $read($credentials);
    $need(hash_equals($m['credentialsSha256'], hash('sha256', $text)), 'inspection_observer_pin');
    $account = json_decode($text, true, flags: JSON_THROW_ON_ERROR)['migrator'];
    $db = new PDO('mysql:host=localhost;dbname=novinpay_live1;charset=utf8mb4', $account['user'], $account['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('SET SESSION TRANSACTION READ ONLY');
    $need($db->query('SELECT DATABASE()')->fetchColumn() === 'novinpay_live1' && $db->query('SELECT CURRENT_USER()')->fetchColumn() === 'novinpay_mig1@localhost', 'inspection_database_identity');
    $migrations = $db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    $rows = $db->query('SELECT TRIGGER_NAME name,EVENT_OBJECT_TABLE tableName,EVENT_MANIPULATION eventName,ACTION_TIMING timing,ACTION_STATEMENT body,DEFINER definer FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll();
    foreach ($rows as &$row) {
        $row['bodySha256'] = hash('sha256', $row['body']);
        unset($row['body']);
    }
    unset($row);
    $observation['migrations'] = count($migrations);
    $observation['triggers'] = count($rows);
    $expected = $m['baselineMigrations'];
    sort($expected);
    $observation['baselineMigrationsExact'] = $migrations === $expected;
    $observation['baselineTriggersExact'] = false;
    try {
        pr13VerifyTriggerContracts($rows);
        $observation['baselineTriggersExact'] = true;
    } catch (RuntimeException) {
        // Report the mismatch, never emit trigger SQL or attempt recovery.
    }
    echo json_encode(['result' => 'PR13_READONLY_SILENT_INSPECTION_COMPLETE', 'observations' => $observation,
        'databaseWrites' => false, 'productionFilesModified' => false, 'activationRetried' => false, 'retryAuthorized' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    $finished = true;
} catch (Throwable $error) {
    echo json_encode(['result' => 'PR13_READONLY_SILENT_INSPECTION_STOP', 'phase' => $phase,
        'check' => preg_match('/\A[a-z0-9_]+\z/D', $error->getMessage()) ? $error->getMessage() : 'details_withheld',
        'observations' => $observation, 'retryAuthorized' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    $finished = true;
    exit(1);
}
