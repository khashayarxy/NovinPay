<?php

declare(strict_types=1);

namespace NovinPay\Release;

require_once __DIR__.'/runtime.php';

/** Private, checksum-pinned manifests contain deployment facts, never passwords. */
function recoveryManifest(array $m): void
{
    requireGate(($m['format'] ?? null) === 'novinpay-recovery-v1', 'recovery_manifest_format', 'novinpay-recovery-v1', [], 'Use the shared recovery manifest format.');
    foreach (['baseline', 'target'] as $key) {
        requireGate(is_string($m[$key] ?? null) && preg_match('/\A[a-f0-9]{40}\z/D', $m[$key]) === 1,
            'recovery_manifest_commit', 'full commit SHA', ['field' => $key], 'Pin the approved source and target commits.');
    }
    requireGate(is_string($m['sourceBranch'] ?? null) && preg_match('~\A[a-zA-Z0-9_./-]+\z~D', $m['sourceBranch']) === 1,
        'recovery_manifest_branch', 'explicit source branch', [], 'Read the actual production branch.');
    requireGate(($m['stateDirectory'] ?? null) === '/home/novinpay/novinpay-direct-pr13', 'recovery_state_directory', 'existing private deployment directory', [], 'Preserve the hosting layout.');
    requireGate(is_string($m['backup']['directory'] ?? null) && preg_match('~\A/home/novinpay/novinpay-private-backups/recovery-[a-z0-9-]{1,60}\z~D', $m['backup']['directory']) === 1,
        'recovery_destination', 'new private recovery generation', [], 'Preserve previous generations.');
    requireGate(is_string($m['backup']['restoreDatabase'] ?? null) && preg_match('/\Anovinpay_restore[a-z0-9]+\z/D', $m['backup']['restoreDatabase']) === 1,
        'recovery_restore_identity', 'explicit isolated restore database', [], 'Use only the approved isolated destination.');
    requireGate(is_array($m['migrations'] ?? null) && count($m['migrations']) > 0 && count($m['migrations']) === count(array_unique($m['migrations']))
        && is_array($m['triggers'] ?? null) && count($m['triggers']) === ($m['expectedTriggers'] ?? null),
        'recovery_schema_contract', 'exact migration and trigger contracts', [], 'Read the approved production schema contract.');
    foreach ($m['migrations'] as $name) {
        requireGate(is_string($name) && preg_match('/\A[a-z0-9_]+\z/D', $name) === 1, 'migration_name', 'migration name', [], 'Inspect the manifest.');
    }
    requireGate(is_array($m['retainedFiles'] ?? null) && is_array($m['maintenanceHashes'] ?? null),
        'recovery_file_contract', 'explicit current retained-file and maintenance inventories', [], 'Inspect existing files read-only.');
    foreach ($m['retainedFiles'] as $path => $pin) {
        requireGate(is_string($path) && ! str_starts_with($path, '/') && ! str_contains($path, '..') && ! str_contains($path, '\\')
            && preg_match('/\A[a-f0-9]{64}\z/D', $pin['sha256'] ?? '') === 1 && is_int($pin['bytes'] ?? null) && $pin['bytes'] >= 0,
            'recovery_retained_file_contract', 'relative existing file with exact fingerprint', [], 'Retain only observed files, never a retired-path assumption.');
        requireGate(empty($pin['appendOnly']) || $path === 'error_log', 'recovery_volatile_scope', 'only the diagnostic error log may append', [], 'Durable business and security data must remain fully protected.');
    }
    foreach (['state.json', 'audit.jsonl'] as $file) {
        requireGate(preg_match('/\A[a-f0-9]{64}\z/D', $m['maintenanceHashes'][$file] ?? '') === 1,
            'maintenance_snapshot_pin', 'current private state and audit fingerprints', ['file' => $file], 'Refresh manifest pins from read-only evidence.');
    }
    foreach ([$m['credentialsSha256'] ?? '', $m['backup']['serverKeySha256'] ?? ''] as $pin) {
        requireGate(is_string($pin) && preg_match('/\A[a-f0-9]{64}\z/D', $pin) === 1,
            'recovery_private_configuration', 'pinned existing credential and encryption-key sources', [], 'Inspect private configuration without printing its contents.');
    }
}

function recoveryWorktreeEntries(string $staged, string $porcelain, array $retained): void
{
    requireGate(trim($staged) === '', 'unexpected_staged_work', 'empty index', [], 'Preserve staged changes.');
    $expected = [' M public/.htaccess'];
    foreach (array_keys($retained) as $path) {
        $expected[] = '?? '.$path;
    }
    $actual = array_values(array_filter(explode("\n", rtrim(str_replace("\r", '', $porcelain), "\n")), fn ($v) => $v !== ''));
    sort($actual);
    sort($expected);
    requireGate($actual === $expected, 'recovery_worktree_inventory', 'current manifest inventory matches Git status',
        ['expectedEntries' => count($expected), 'observedEntries' => count($actual), 'inventoryMatches' => $actual === $expected],
        'Inspect Git status privately; preserve unexpected work and correct only verified stale inventory facts.');
}

function verifyRecoveryWorktree(string $app, array $manifest): array
{
    recoveryWorktreeEntries(recoveryGit($app, ['diff', '--cached', '--name-only']),
        recoveryGit($app, ['status', '--porcelain=v1', '--untracked-files=all']), $manifest['retainedFiles']);
    foreach ($manifest['retainedFiles'] as $path => $pin) {
        $full = $app.'/'.$path;
        $before = lstat($full);
        recoveryFileMetadata($before, $pin);
        requireGate(is_file($full) && ! is_link($full) && realpath($full) === $full
            && (empty($pin['appendOnly']) ? filesize($full) === $pin['bytes'] : filesize($full) >= $pin['bytes'])
            && hash_equals($pin['sha256'], retainedFileDigest($full, $pin)), 'retained_file_changed', 'observed retained file or diagnostic prefix unchanged',
            ['fileMatches' => false], 'Preserve the file; reconcile its current fingerprint before capture.');
        clearstatcache(true, $full);
        $after = lstat($full);
        if (! empty($pin['appendOnly']) && is_array($after)) {
            foreach (['size', 'mtime', 'ctime'] as $field) {
                $before[$field] = $after[$field];
            }
        }
        recoveryFileStable($before, $after);
    }

    return $manifest['retainedFiles'];
}

function retainedFileDigest(string $path, array $pin): string
{
    if (empty($pin['appendOnly'])) {
        return hash_file('sha256', $path);
    }
    $stream = fopen($path, 'rb');
    $hash = hash_init('sha256');
    try {
        $bytes = hash_update_stream($hash, $stream, $pin['bytes']);
        requireGate($bytes === $pin['bytes'], 'retained_log_truncated', 'preserved diagnostic prefix', [], 'Preserve the log and reconcile truncation.');

        return hash_final($hash);
    } finally {
        fclose($stream);
    }
}

function recoveryFileStable(array $before, array|false $after): void
{
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
        requireGate(is_array($after) && $before[$field] === $after[$field], 'retained_file_changed_during_check',
            'stable identity, permissions and contents', ['field' => $field], 'Preserve the file and inspect concurrent writes.');
    }
}

function environmentBoolean(mixed $value): bool
{
    $text = strtolower(trim((string) $value));
    requireGate(in_array($text, ['true', '(true)', '1', 'false', '(false)', '0', '', 'null', '(null)'], true),
        'environment_boolean', 'recognized boolean configuration', [], 'Inspect the named private configuration source without printing it.');

    return in_array($text, ['true', '(true)', '1'], true);
}

/** Preserve approved real-SMS testing; the test-account flag also controls reset. */
function recoveryCustomerConfiguration(array $env, array $cached): array
{
    $c = $cached['customer'] ?? [];
    requireGate(! environmentBoolean($env['SMSIR_ENABLED'] ?? 'false') && ! environmentBoolean($env['SMSIR_NOTIFICATIONS_ENABLED'] ?? 'false')
        && ($c['sms_enabled'] ?? null) === false && ($c['notifications_enabled'] ?? null) === false,
        'smsir_remains_disabled', 'SMS.ir sending and notifications remain disabled', [], 'Preserve the approved production provider configuration.');
    requireGate(($env['SMS_PROVIDER'] ?? '') === 'ippanel' && environmentBoolean($env['IPPANEL_ENABLED'] ?? 'false')
        && ($c['sms_provider'] ?? null) === 'ippanel' && ($c['ippanel']['enabled'] ?? null) === true,
        'active_ippanel_configuration', 'approved IPPanel environment and cache agree', [], 'Inspect effective and fresh flags without changing credentials.');
    $test = environmentBoolean($env['CUSTOMER_TEST_OTP_ENABLED'] ?? 'false');
    $phones = trim((string) ($env['CUSTOMER_TEST_OTP_PHONES'] ?? ''));
    requireGate($phones === '' || preg_match('/\A09[0-9]{9}(?:\s*,\s*09[0-9]{9})*\z/D', $phones) === 1,
        'private_allowlist_format', 'existing private Iranian mobile allowlist', [], 'Inspect private formatting; never print its contents.');
    $expectedPhones = array_map('trim', explode(',', $phones));
    $testPhone = trim((string) ($c['test_sms_phone'] ?? ''));
    requireGate(($c['test_otp_enabled'] ?? false) === $test && array_map('trim', $c['test_otp_phones'] ?? []) === $expectedPhones
        && trim((string) ($env['CUSTOMER_TEST_SMS_PHONE'] ?? '')) === $testPhone,
        'test_customer_cache_consistency', 'private test-account environment and cache agree', [], 'Inspect cached configuration without changing the account.');
    requireGate(! $test || (preg_match('/\A09[0-9]{9}\z/D', $testPhone) === 1 && in_array($testPhone, $expectedPhones, true)),
        'approved_test_sms_mode', 'enabled test account uses its approved real-SMS identity', [], 'Preserve the approved allowlist and SMS mode.');
    requireGate(environmentBoolean($env['CUSTOMER_TEST_RESET_ON_LOGOUT'] ?? 'true') && ($c['test_reset_on_logout'] ?? null) === true,
        'test_reset_preservation', 'approved logout-reset feature retained', [], 'Preserve reset configuration and protected-record checks.');

    return ['result' => 'HOSTED_CUSTOMER_AUTH_GATE_PASS', 'activeProvider' => 'ippanel', 'smsirSendingEnabled' => false,
        'testAccountFeatureEnabled' => $test, 'testAccountUsesSms' => $test, 'allowlistConfigured' => $phones !== ''];
}

function recoveryFileMetadata(array|false $actual, array $pin): void
{
    requireGate(is_array($actual) && ($actual['mode'] & 0170000) === 0100000 && $actual['nlink'] === 1
        && $actual['uid'] === 1321 && ($actual['mode'] & 0777) === ($pin['mode'] ?? null),
        'retained_file_permissions', 'regular, single-link hosting-owned file with observed permissions', [], 'Inspect permissions; never broaden them automatically.');
    foreach (['gid', 'mtime', 'ctime'] as $field) {
        if (! empty($pin['appendOnly']) && in_array($field, ['mtime', 'ctime'], true)) {
            continue;
        }
        requireGate(isset($pin[$field]) && $actual[$field] === $pin[$field], 'retained_file_metadata', 'observed ownership and timestamps', ['field' => $field], 'Preserve retained evidence and reconcile metadata changes.');
    }
}

function recoveryGit(string $app, array $args): string
{
    $result = command(array_merge(['git', '--no-optional-locks', '-c', 'core.fsmonitor=false', '-C', $app], $args), $app, 'recovery_git');
    requireGate($result['exit'] === 0, 'recovery_git', 'Git read operation exits zero', ['exitCode' => $result['exit']], 'Inspect Git privately; preserve work and do not start capture.');

    return $result['stdout'];
}

function verifyRecoveryTriggers(array $actual, array $manifest): void
{
    $indexed = [];
    foreach ($actual as $row) {
        $name = $row['name'];
        unset($row['name']);
        ksort($row);
        $indexed[$name] = $row;
    }
    $expected = $manifest['triggers'];
    foreach ($expected as &$row) {
        ksort($row);
    }
    unset($row);
    ksort($expected);
    ksort($indexed);
    requireGate($indexed === $expected, 'source_trigger_contracts', 'exact names, tables, events, timing, body hashes and definers',
        ['expectedCount' => count($expected), 'observedCount' => count($actual), 'contractsMatch' => $indexed === $expected], 'Inspect trigger metadata through the migrator account.');
}

/** A partial capture or restore is never replayable. Quota waiting precedes either. */
function recoveryRetryDecision(string $phase, bool $captureExists, bool $restoreEmpty, bool $receiptVerified): string
{
    if ($receiptVerified) {
        return 'reuse_verified_recovery';
    }
    if ($captureExists || ! $restoreEmpty || in_array($phase, ['capture', 'restore', 'offhost'], true)) {
        return 'stop_preserve_and_reconcile';
    }

    return in_array($phase, ['preflight', 'quota'], true) ? 'new_attempt_after_readonly_reconciliation' : 'stop_unknown_state';
}

function recoveryResumeAllowed(array $receipt, string $manifestSha, bool $captureExists): bool
{
    return ! $captureExists && ($receipt['manifestSha256'] ?? null) === $manifestSha
        && ($receipt['result'] ?? null) === 'DATABASE_RECOVERY_PENDING'
        && ($receipt['phase'] ?? null) === 'preflight' && ($receipt['captureStarted'] ?? null) === false
        && ($receipt['check'] ?? null) === 'quota_api_response_pending';
}

function recoveryReceiptMatches(array $r, array $m, string $archiveSha): bool
{
    return ($r['result'] ?? null) === 'BACKUP_AND_DISPOSABLE_RESTORE_PASS'
        && ($r['manifestSha256'] ?? null) === $m['_sha256'] && ($r['sourceCommit'] ?? null) === $m['baseline']
        && ($r['sourceDatabase'] ?? null) === 'novinpay_live1' && ($r['restoreDatabase'] ?? null) === $m['backup']['restoreDatabase']
        && ($r['migrations'] ?? null) === count($m['migrations']) && ($r['triggers'] ?? null) === $m['expectedTriggers']
        && ($r['schemaAndDefinersMatch'] ?? null) === true && ($r['allSnapshotRowsMatch'] ?? null) === true
        && ($r['archiveSha256'] ?? null) === $archiveSha;
}

function recoveryFailure(\Throwable $error, string $phase, bool $captureExists = false): array
{
    $report = failureReport($error, $phase, $GLOBALS['np_backup_check'] ?? 'recovery_runtime');
    $report['result'] = $error instanceof Pending && ! $captureExists ? 'DATABASE_RECOVERY_PENDING' : 'DATABASE_RECOVERY_STOP';
    $report['liveDatabaseWritten'] = false;
    $report['captureStarted'] = $captureExists;
    $report['retryClassification'] = recoveryRetryDecision($phase, $captureExists, $GLOBALS['np_restore_empty'] ?? false, false);
    $report['nextAction'] = $error instanceof Pending && ! $captureExists
        ? 'Restore the authenticated quota companion; reconcile this preflight before a new attempt. No capture or restore has started.'
        : $report['nextAction'];

    return $report;
}

/** Source-only remains strict; application mode needs separately reviewed pins. */
function sourceReleaseManifest(array $m): void
{
    requireGate(in_array($m['format'] ?? null, ['novinpay-source-release-v1', 'novinpay-application-release-v1'], true), 'source_release_format', 'reviewed release format', [], 'Use a separately reviewed procedure for schema or dependency changes.');
    $recovery = $m;
    $recovery['format'] = 'novinpay-recovery-v1';
    recoveryManifest($recovery);
    requireGate(preg_match('/\A[a-z0-9-]{1,64}\z/D', $m['releaseName'] ?? '') === 1
        && preg_match('~\A[a-zA-Z0-9_./-]+\z~D', $m['targetBranch'] ?? '') === 1
        && preg_match('~\Aassets/[a-zA-Z0-9_-]+\.css\z~D', $m['cssAsset'] ?? '') === 1,
        'source_release_paths', 'safe release name, branch and versioned CSS asset', [], 'Inspect the artifact contract.');
    requireGate(in_array($m['successResult'] ?? null, ['SOURCE_RELEASE_PASS', 'LOGIN_SUBMIT_RELEASE_PASS', 'APPLICATION_RELEASE_PASS'], true),
        'source_result_contract', 'known success receipt label', [], 'Inspect the release contract.');
    requireGate(is_array($m['httpChecks'] ?? null) && isset($m['httpChecks']['/'], $m['httpChecks']['/login'])
        && is_array($m['baselineFiles'] ?? null) && isset($m['baselineFiles']['public/build/manifest.json'], $m['baselineFiles']['bootstrap/cache/routes-v7.php'], $m['baselineFiles']['public/build/support-chat/manifest.json']),
        'source_release_acceptance_contract', 'explicit public checks and protected asset/route identities', [], 'Pin current public routes and asset fingerprints.');
    foreach ($m['baselineFiles'] ?? [] as $path => $hash) {
        requireGate(! str_starts_with($path, '/') && ! str_contains($path, '..') && ! str_contains($path, '\\')
            && preg_match('/\A[a-f0-9]{64}\z/D', $hash) === 1,
            'source_baseline_file', 'relative file and expected fingerprint', [], 'Pin actual production files.');
    }
    if ($m['format'] === 'novinpay-application-release-v1') {
        applicationReleasePlan($m);
        requireGate(isset($m['baselineFiles']['bootstrap/cache/config.php']), 'application_config_pin', 'original cached configuration pinned', [], 'Pin the current configuration privately.');
    }
}

/** This extension supports reviewed additive migrations, never dependency changes. */
function applicationReleasePlan(array $m): void
{
    $reviewed = $m['reviewedRuntimeChanges'] ?? [];
    $migrations = $m['reviewedMigrations'] ?? [];
    requireGate(is_array($reviewed) && $reviewed !== [] && is_array($migrations) && $migrations !== [],
        'application_reviewed_changes', 'explicit runtime and migration blob hashes', [], 'Review and pin the exact candidate diff.');
    foreach ($reviewed as $path => $sha) {
        requireGate(preg_match('~\A(?:config|routes|database/migrations)/[A-Za-z0-9_/-]+\.php\z~D', $path) === 1
            && ! str_contains($path, '..') && preg_match('/\A[a-f0-9]{64}\z/D', $sha) === 1,
            'application_reviewed_path', 'relative reviewed PHP runtime path', [], 'Reject unexpected paths.');
    }
    $expected = $m['migrations'];
    foreach ($migrations as $path => $sha) {
        $name = basename($path, '.php');
        requireGate(preg_match('~\Adatabase/migrations/[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+\.php\z~D', $path) === 1
            && ! in_array($name, $expected, true) && ($reviewed[$path] ?? null) === $sha,
            'application_additive_migration', 'new reviewed migration, never a modified historical migration', [], 'Review the migration scope.');
        $expected[] = $name;
    }
    sort($expected);
    requireGate(($m['candidateMigrations'] ?? null) === $expected, 'application_candidate_migrations', 'baseline plus exactly reviewed additions', [], 'Rebuild the candidate inventory from Git.');
    $migrationPaths = array_keys(array_filter($reviewed, fn ($sha, $path) => str_starts_with($path, 'database/migrations/'), ARRAY_FILTER_USE_BOTH));
    $declaredPaths = array_keys($migrations);
    sort($migrationPaths);
    sort($declaredPaths);
    requireGate($migrationPaths === $declaredPaths, 'application_migration_scope', 'every schema change explicitly reviewed', [], 'Reject undeclared migration edits.');
    requireGate(expectedTriggerContracts($expected) === expectedTriggerContracts($m['migrations']),
        'application_trigger_change', 'unchanged protective trigger contracts', [], 'A trigger-changing release needs a separate reviewed procedure.');
    $rows = [];
    foreach ($m['triggers'] ?? [] as $name => $contract) {
        $rows[] = ['name' => $name] + $contract;
    }
    verifyRecoveryTriggers($rows, ['triggers' => expectedTriggerContracts($m['migrations'])]);
}

function preservedConfigurationDigest(array $configuration): string
{
    // Only the reviewed new defaults may differ. Provider/identity/secrets remain bound.
    unset($configuration['operations']);
    unset($configuration['payments']['zibal']['review_after_minutes'], $configuration['payments']['zibal']['reconciliation_limit']);
    $sort = static function (array $value) use (&$sort): array {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $sort($item);
            }
        }
        unset($item);
        ksort($value);

        return $value;
    };

    return hash('sha256', json_encode($sort($configuration), JSON_THROW_ON_ERROR));
}

function reviewedMigrationReceipt(string $journal, string $phase, array $manifest): void
{
    requireGate(in_array($phase, ['started', 'complete'], true), 'migration_receipt_phase', 'known migration phase', [], 'Preserve the journal.');
    $receipt = ['manifestSha256' => $manifest['_sha256'], 'retrySafe' => false];
    if ($phase === 'complete') {
        $receipt['expectedMigrations'] = $manifest['candidateMigrations'];
    }
    writeNew($journal.'/migration-'.$phase.'.json', $receipt);
}

function sourceRecoveryEvidence(array $m): void
{
    $proofBytes = privateRead($m['recoveryProof']['path']);
    requireGate(hash_equals($m['recoveryProof']['sha256'], hash('sha256', $proofBytes)), 'offhost_proof_pin', 'independently published proof bytes', [], 'Verify the off-host receipt before activation.');
    $proof = json_decode($proofBytes, true, 32, JSON_THROW_ON_ERROR);
    recoveryGate($proof, ['automaticDatabaseRestore' => false], time());
    $receipt = json_decode(privateRead($m['backup']['directory'].'/receipt.json'), true, 32, JSON_THROW_ON_ERROR);
    requireGate(($receipt['result'] ?? null) === 'BACKUP_AND_DISPOSABLE_RESTORE_PASS'
        && ($receipt['sourceCommit'] ?? null) === ($proof['sourceCommit'] ?? null)
        && ($receipt['migrations'] ?? null) === count($m['migrations']) && ($receipt['triggers'] ?? null) === $m['expectedTriggers']
        && ($proof['migrations'] ?? null) === count($m['migrations']) && ($proof['triggers'] ?? null) === $m['expectedTriggers']
        && ($receipt['archiveSha256'] ?? null) === ($proof['archiveSha256'] ?? null)
        && ($receipt['snapshotUnix'] ?? null) === ($proof['snapshotUnix'] ?? null),
        'recovery_receipt_identity', 'matching isolated restore and independent recovery receipts', [], 'Reconcile the exact recovery generation.');
    $archive = $m['backup']['directory'].'/backup.npb';
    requireGate(is_file($archive) && ! is_link($archive) && (fileperms($archive) & 0777) === 0600
        && hash_equals($proof['archiveSha256'], hash_file('sha256', $archive)), 'backup_checksum', 'encrypted backup still intact', [], 'Preserve the archive and investigate corruption.');
    $forbidden = recoveryGit('/home/novinpay/novinpay', ['diff', '--name-only', $m['baseline'], $m['target'], '--', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'config', 'public/.htaccess', 'database/migrations', 'routes']);
    if ($m['format'] === 'novinpay-application-release-v1') {
        $paths = preg_split('/\r?\n/', trim($forbidden), -1, PREG_SPLIT_NO_EMPTY);
        $reviewed = array_keys($m['reviewedRuntimeChanges']);
        sort($paths);
        sort($reviewed);
        requireGate($paths === $reviewed, 'application_runtime_diff', 'exact reviewed runtime changes; dependency changes prohibited', [], 'Inspect the candidate diff.');
        foreach ($m['reviewedRuntimeChanges'] as $path => $sha) {
            requireGate(hash_equals($sha, hash('sha256', recoveryGit('/home/novinpay/novinpay', ['show', $m['target'].':'.$path]))),
                'application_runtime_blob', 'reviewed candidate blob', [], 'Reject changed artifacts.');
        }
        $files = recoveryGit('/home/novinpay/novinpay', ['ls-tree', '-r', '--name-only', $m['target'], '--', 'database/migrations']);
        verifyMigrationInventory(array_map(fn ($path) => basename($path, '.php'), preg_split('/\r?\n/', trim($files), -1, PREG_SPLIT_NO_EMPTY)), $m['candidateMigrations']);
    } else {
        requireGate(trim($forbidden) === '', 'source_release_schema_or_dependency_change', 'unchanged migrations, dependencies and routes', [], 'Prepare the appropriate reviewed migration/dependency release.');
    }
    $nonModified = recoveryGit('/home/novinpay/novinpay', ['diff', '--name-only', '--diff-filter='.($m['format'] === 'novinpay-application-release-v1' ? 'CDRT' : 'ACDRT'), $m['baseline'], $m['target']]);
    requireGate(trim($nonModified) === '', 'source_release_rollback_scope', 'modified existing files with complete baseline rollback coverage', [], 'Review additions, deletions, renames or type changes before adapting rollback.');
    $prior = privateRead($m['baselineReceipt']['path']);
    requireGate(hash_equals($m['baselineReceipt']['sha256'], hash('sha256', $prior)), 'baseline_release_receipt', 'preserved successful baseline receipt', [], 'Reconcile the active production release.');
    sourceArchiveContract($m['stateDirectory'].'/'.$m['releaseName'].'-vite.zip', $m['cssAsset']);
}

function sourceArchiveContract(string $path, string $css): void
{
    $zip = new \ZipArchive;
    requireGate($zip->open($path) === true, 'source_asset_archive', 'readable checked asset ZIP', [], 'Preserve and inspect the package.');
    $names = [];
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $s = $zip->statIndex($i);
            $zip->getExternalAttributesIndex($i, $os, $attributes);
            requireGate(in_array($s['name'], [$css, 'manifest.json'], true) && ! in_array($s['name'], $names, true)
                && (($attributes >> 16) & 0170000) !== 0120000 && $s['size'] <= 16777216,
                'source_asset_member', 'exact relative CSS/manifest members with no links or duplicates', [], 'Reject unsafe or unexpected asset archives.');
            $names[] = $s['name'];
        }
        sort($names);
        $expected = [$css, 'manifest.json'];
        sort($expected);
        requireGate($names === $expected, 'source_asset_inventory', 'both approved asset members', [], 'Inspect package completeness.');
    } finally {
        $zip->close();
    }
}

function sourceTriggerEvidence(array $m): void
{
    $credentials = '/home/novinpay/novinpay-releases/155fc9f30feb5b9d0c41445b33f1118d5e07da8e/.stage2-credentials.json';
    $bytes = privateRead($credentials);
    requireGate(hash_equals($m['credentialsSha256'], hash('sha256', $bytes)), 'retained_credentials_changed', 'same authorized observer credential', [], 'Preserve credentials and inspect identity.');
    $c = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR)['migrator'];
    requireGate($c['user'] === 'novinpay_mig1', 'trigger_observer_identity', 'migrator observer', [], 'Use the authorized metadata observer.');
    $db = new \PDO('mysql:host=localhost;port=3306;dbname=novinpay_live1;charset=utf8mb4', $c['user'], $c['password'],
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    unset($bytes, $c);
    $rows = $db->query('SELECT TRIGGER_NAME name, EVENT_OBJECT_TABLE tableName, EVENT_MANIPULATION eventName, ACTION_TIMING timing, ACTION_STATEMENT body, DEFINER definer FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll();
    foreach ($rows as &$row) {
        $row['bodySha256'] = hash('sha256', $row['body']);
        unset($row['body']);
    }
    unset($row);
    verifyRecoveryTriggers($rows, $m);
}

function sourceHttpsAcceptance(array $m): void
{
    foreach ($m['httpChecks'] as $path => $expected) {
        requireGate(preg_match('~\A/[a-zA-Z0-9_./?=&%-]*\z~D', $path) === 1 && ! str_contains($path, '..'), 'http_probe_path', 'same-origin public path', [], 'Inspect the public acceptance contract.');
        $h = curl_init('https://novinpaypos.com'.$path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25, CURLOPT_ENCODING => '']);
        try {
            $body = curl_exec($h);
            $http = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            requireGate(curl_errno($h) === 0 && $http === $expected['status'], 'release_https_acceptance', 'expected HTTPS response',
                ['path' => $path, 'expectedHttp' => $expected['status'], 'observedHttp' => $http, 'curlCode' => curl_errno($h)], 'Preserve the release journal; investigate the named read-only probe.');
            foreach ($expected['contains'] ?? [] as $needle) {
                requireGate(is_string($body) && str_contains($body, $needle), 'release_html_contract', 'expected public markup', ['path' => $path], 'Inspect the served asset versions.');
            }
            foreach ($expected['excludes'] ?? [] as $needle) {
                requireGate(is_string($body) && ! str_contains($body, $needle), 'release_html_contract', 'no stale public markup', ['path' => $path], 'Inspect the served page state.');
            }
            if (isset($expected['sha256'])) {
                requireGate(is_string($body) && hash_equals($expected['sha256'], hash('sha256', $body)), 'release_http_asset_checksum', 'approved public asset bytes', ['path' => $path], 'Inspect CDN/web cache before further changes.');
            }
        } finally {
            curl_close($h);
        }
    }
}
