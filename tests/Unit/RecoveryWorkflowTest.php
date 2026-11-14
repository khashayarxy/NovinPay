<?php

namespace Tests\Unit;

use NovinPay\Release\GateFailure;
use NovinPay\Release\Pending;
use PHPUnit\Framework\TestCase;

use function NovinPay\Release\databasePrivilegesPresent;
use function NovinPay\Release\operationLock;
use function NovinPay\Release\quotaReply;
use function NovinPay\Release\recoveryCustomerConfiguration;
use function NovinPay\Release\recoveryFailure;
use function NovinPay\Release\recoveryFileMetadata;
use function NovinPay\Release\recoveryFileStable;
use function NovinPay\Release\recoveryGate;
use function NovinPay\Release\recoveryManifest;
use function NovinPay\Release\recoveryReceiptMatches;
use function NovinPay\Release\recoveryResumeAllowed;
use function NovinPay\Release\recoveryRetryDecision;
use function NovinPay\Release\recoveryWorktreeEntries;
use function NovinPay\Release\retainedFileDigest;
use function NovinPay\Release\sourceArchiveContract;
use function NovinPay\Release\sourceReleaseManifest;
use function NovinPay\Release\verifyRecoveryTriggers;

require_once __DIR__.'/../../scripts/release/recovery-contract.php';

class RecoveryWorkflowTest extends TestCase
{
    private function manifest(): array
    {
        return [
            'format' => 'novinpay-recovery-v1', 'baseline' => str_repeat('a', 40), 'target' => str_repeat('b', 40),
            'sourceBranch' => 'codex/fixture', 'stateDirectory' => '/home/novinpay/novinpay-direct-pr13',
            'backup' => ['directory' => '/home/novinpay/novinpay-private-backups/recovery-fixture', 'restoreDatabase' => 'novinpay_restoreauth1', 'serverKeySha256' => str_repeat('d', 64)],
            'credentialsSha256' => str_repeat('c', 64), 'migrations' => ['fixture_migration'], 'expectedTriggers' => 1,
            'triggers' => ['fixture_trigger' => ['tableName' => 'fixture', 'eventName' => 'UPDATE', 'timing' => 'BEFORE', 'definer' => 'novinpay_mig1@localhost', 'bodySha256' => str_repeat('e', 64)]],
            'retainedFiles' => ['error_log' => ['sha256' => str_repeat('f', 64), 'bytes' => 2]],
            'maintenanceHashes' => ['state.json' => str_repeat('a', 64), 'audit.jsonl' => str_repeat('b', 64)],
        ];
    }

    public function test_valid_recovery_contract_and_current_worktree_pass_without_a_retired_preview_requirement(): void
    {
        $m = $this->manifest();
        recoveryManifest($m);
        recoveryWorktreeEntries('', " M public/.htaccess\n?? error_log\n", $m['retainedFiles']);
        $this->addToAssertionCount(1);
    }

    public function test_stale_preview_inventory_reproduces_a_named_failure(): void
    {
        $this->expectException(GateFailure::class);
        $this->expectExceptionMessage('recovery_worktree_inventory');
        recoveryWorktreeEntries('', " M public/.htaccess\n?? error_log\n", $this->manifest()['retainedFiles'] + ['novinpay-stage3v2-preview/public/.htaccess' => []]);
    }

    public function test_unexpected_user_work_cannot_be_ignored(): void
    {
        $this->expectExceptionMessage('recovery_worktree_inventory');
        recoveryWorktreeEntries('', " M public/.htaccess\n?? error_log\n M app/Models/User.php\n", $this->manifest()['retainedFiles']);
    }

    public function test_missing_encryption_configuration_fails_before_capture(): void
    {
        $m = $this->manifest();
        unset($m['backup']['serverKeySha256']);
        $this->expectExceptionMessage('recovery_private_configuration');
        recoveryManifest($m);
    }

    public function test_live_database_is_rejected_as_a_restore_target(): void
    {
        $m = $this->manifest();
        $m['backup']['restoreDatabase'] = 'novinpay_live1';
        $this->expectExceptionMessage('recovery_restore_identity');
        recoveryManifest($m);
    }

    public function test_unsafe_backup_destination_is_rejected(): void
    {
        $m = $this->manifest();
        $m['backup']['directory'] .= '/../older-generation';
        $this->expectExceptionMessage('recovery_destination');
        recoveryManifest($m);
    }

    public function test_exact_permission_metadata_is_required(): void
    {
        $this->expectExceptionMessage('retained_file_permissions');
        recoveryFileMetadata(['mode' => 0100666, 'nlink' => 1, 'uid' => 1321], ['mode' => 0600]);
    }

    public function test_reading_a_file_may_update_access_time_without_invalidating_its_identity(): void
    {
        $before = array_fill_keys(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'], 1) + ['atime' => 1];
        recoveryFileStable($before, array_replace($before, ['atime' => 99]));
        $this->addToAssertionCount(1);
        $this->expectExceptionMessage('retained_file_changed_during_check');
        recoveryFileStable($before, array_replace($before, ['size' => 99]));
    }

    public function test_original_controlled_exception_survives_reporting_without_secret_text(): void
    {
        $r = recoveryFailure(new \RuntimeException('unexpected_working_tree_preserve_and_stop'), 'preflight');
        $this->assertSame('unexpected_working_tree_preserve_and_stop', $r['check']);
        $this->assertFalse($r['retrySafe']);
        $secret = recoveryFailure(new \RuntimeException('credential=synthetic-secret'), 'preflight');
        $this->assertStringNotContainsString('synthetic-secret', json_encode($secret));
    }

    public function test_database_failure_reports_only_safe_sqlstate_and_driver_code(): void
    {
        $e = new \PDOException('password=synthetic-secret');
        $e->errorInfo = ['HY000', 1045, 'secret'];
        $r = recoveryFailure($e, 'preflight');
        $this->assertSame('database', $r['category']);
        $this->assertSame(1045, $r['actual']['driverCode']);
        $this->assertStringNotContainsString('synthetic-secret', json_encode($r));
    }

    public function test_quota_timeout_is_pending_before_capture_and_never_auto_retryable(): void
    {
        $r = recoveryFailure(new Pending('quota_api_response_pending'), 'preflight');
        $this->assertSame('DATABASE_RECOVERY_PENDING', $r['result']);
        $this->assertFalse($r['captureStarted']);
        $this->assertFalse($r['retrySafe']);
        $this->assertSame('DATABASE_RECOVERY_STOP', recoveryFailure(new Pending('quota_api_response_pending'), 'capture', true)['result']);
    }

    public function test_partial_quota_publication_is_awaited_and_final_bytes_are_verified(): void
    {
        $this->assertNull(quotaReply(null, null));
        $this->assertNull(quotaReply('{"sha256":', '{}'));
        $this->assertNull(quotaReply('{}', '{}'));
        $reply = '{"nonce":"fixture"}';
        $ready = json_encode(['sha256' => hash('sha256', $reply)]);
        $this->assertSame(['nonce' => 'fixture'], quotaReply($ready, $reply));
        $this->expectExceptionMessage('quota_response_hash');
        quotaReply($ready, '{"nonce":"changed"}');
    }

    public function test_restore_privileges_accept_only_exact_literal_or_escaped_scope(): void
    {
        $rows = array_map(fn ($p) => ['GRANTEE' => "'novinpay_mig1'@'localhost'", 'TABLE_SCHEMA' => 'novinpay\\_restoreauth1', 'PRIVILEGE_TYPE' => $p], ['SELECT', 'INSERT', 'CREATE', 'TRIGGER']);
        $required = ['SELECT', 'INSERT', 'CREATE', 'TRIGGER'];
        $this->assertTrue(databasePrivilegesPresent($rows, 'novinpay_restoreauth1', 'novinpay_mig1@localhost', $required));
        $this->assertFalse(databasePrivilegesPresent(array_slice($rows, 1), 'novinpay_restoreauth1', 'novinpay_mig1@localhost', $required));
        $this->assertFalse(databasePrivilegesPresent($rows, 'novinpay_live1', 'novinpay_mig1@localhost', $required));
        $this->assertFalse(databasePrivilegesPresent($rows, 'novinpay_restoreauth1', 'novinpay_app1@localhost', $required));
    }

    public function test_partial_capture_occupied_restore_and_interrupted_restore_cannot_replay(): void
    {
        $this->assertSame('stop_preserve_and_reconcile', recoveryRetryDecision('preflight', true, true, false));
        $this->assertSame('stop_preserve_and_reconcile', recoveryRetryDecision('restore', false, false, false));
        $this->assertSame('stop_preserve_and_reconcile', recoveryRetryDecision('offhost', true, false, false));
        $this->assertSame('new_attempt_after_readonly_reconciliation', recoveryRetryDecision('quota', false, true, false));
        $this->assertSame('reuse_verified_recovery', recoveryRetryDecision('complete', true, false, true));
    }

    public function test_explicit_resume_is_limited_to_a_pinned_pre_capture_quota_wait(): void
    {
        $sha = str_repeat('a', 64);
        $receipt = ['manifestSha256' => $sha, 'result' => 'DATABASE_RECOVERY_PENDING', 'phase' => 'preflight', 'captureStarted' => false, 'check' => 'quota_api_response_pending'];
        $this->assertTrue(recoveryResumeAllowed($receipt, $sha, false));
        $this->assertFalse(recoveryResumeAllowed($receipt, $sha, true));
        $this->assertFalse(recoveryResumeAllowed($receipt, str_repeat('b', 64), false));
        $receipt['phase'] = 'restore';
        $this->assertFalse(recoveryResumeAllowed($receipt, $sha, false));
    }

    public function test_verified_capture_is_reused_only_with_the_same_archive_and_manifest(): void
    {
        $m = $this->manifest() + ['_sha256' => str_repeat('a', 64)];
        $hash = str_repeat('b', 64);
        $r = ['result' => 'BACKUP_AND_DISPOSABLE_RESTORE_PASS', 'manifestSha256' => $m['_sha256'], 'sourceCommit' => $m['baseline'],
            'sourceDatabase' => 'novinpay_live1', 'restoreDatabase' => $m['backup']['restoreDatabase'], 'migrations' => 1,
            'triggers' => 1, 'schemaAndDefinersMatch' => true, 'allSnapshotRowsMatch' => true, 'archiveSha256' => $hash];
        $this->assertTrue(recoveryReceiptMatches($r, $m, $hash));
        $this->assertFalse(recoveryReceiptMatches($r, $m, str_repeat('c', 64)));
        $r['manifestSha256'] = str_repeat('d', 64);
        $this->assertFalse(recoveryReceiptMatches($r, $m, $hash));
    }

    public function test_real_sms_test_account_configuration_is_preserved_without_disabling_its_feature_flag(): void
    {
        $env = ['SMS_PROVIDER' => 'ippanel', 'IPPANEL_ENABLED' => 'true', 'CUSTOMER_TEST_OTP_ENABLED' => 'true',
            'CUSTOMER_TEST_OTP_PHONES' => '09120000000', 'CUSTOMER_TEST_SMS_PHONE' => '09120000000'];
        $cached = ['customer' => ['sms_enabled' => false, 'notifications_enabled' => false, 'sms_provider' => 'ippanel',
            'ippanel' => ['enabled' => true], 'test_otp_enabled' => true, 'test_otp_phones' => ['09120000000'],
            'test_sms_phone' => '09120000000', 'test_reset_on_logout' => true]];
        $r = recoveryCustomerConfiguration($env, $cached);
        $this->assertTrue($r['testAccountUsesSms']);
        $this->assertFalse($r['smsirSendingEnabled']);
        $this->assertStringNotContainsString('09120000000', json_encode($r));
        $cached['customer']['test_otp_enabled'] = false;
        $this->expectExceptionMessage('test_customer_cache_consistency');
        recoveryCustomerConfiguration($env, $cached);
    }

    public function test_diagnostic_log_append_is_allowed_but_its_original_prefix_cannot_change(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'np-log-');
        try {
            file_put_contents($file, 'original');
            $pin = ['appendOnly' => true, 'bytes' => 8];
            $hash = retainedFileDigest($file, $pin);
            file_put_contents($file, 'new diagnostic', FILE_APPEND);
            $this->assertSame($hash, retainedFileDigest($file, $pin));
            file_put_contents($file, 'replaced');
            $this->assertNotSame($hash, retainedFileDigest($file, $pin));
        } finally {
            unlink($file);
        }
        $m = $this->manifest();
        $m['retainedFiles'] = ['storage/app/private/chat' => ['sha256' => str_repeat('a', 64), 'bytes' => 8, 'appendOnly' => true]];
        $this->expectExceptionMessage('recovery_volatile_scope');
        recoveryManifest($m);
    }

    public function test_recovery_and_activation_share_an_exclusive_operation_lock(): void
    {
        $dir = sys_get_temp_dir().'/np-operation-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $first = operationLock($dir);
        try {
            $this->expectExceptionMessage('operation_already_running');
            operationLock($dir);
        } finally {
            fclose($first);
            unlink($dir.'/.operation.lock');
            rmdir($dir);
        }
    }

    public function test_trigger_bodies_and_historical_definers_are_not_weakened(): void
    {
        $m = $this->manifest();
        $actual = $m['triggers']['fixture_trigger'] + ['name' => 'fixture_trigger'];
        verifyRecoveryTriggers([$actual], $m);
        $this->expectExceptionMessage('source_trigger_contracts');
        $actual['bodySha256'] = str_repeat('0', 64);
        verifyRecoveryTriggers([$actual], $m);
    }

    public function test_expired_or_unverified_offhost_evidence_is_never_accepted(): void
    {
        $proof = ['authenticatedDecryption' => true, 'archiveContentsVerified' => true, 'isolatedRestoreVerified' => true,
            'recoveryKeyAvailable' => true, 'sourceDatabase' => 'novinpay_live1', 'snapshotUnix' => 1000];
        recoveryGate($proof, ['automaticDatabaseRestore' => false], 1000 + 72 * 3600);
        try {
            recoveryGate($proof, ['automaticDatabaseRestore' => false], 1001 + 72 * 3600);
            $this->fail('Expired snapshot passed');
        } catch (GateFailure $e) {
            $this->assertSame('backup_freshness', $e->check);
        }
        $proof['authenticatedDecryption'] = false;
        $this->expectExceptionMessage('recovery_authenticatedDecryption');
        recoveryGate($proof, ['automaticDatabaseRestore' => false], 1001);
    }

    public function test_source_activation_contract_reuses_the_same_schema_and_explicit_paths(): void
    {
        $m = $this->manifest();
        $m['format'] = 'novinpay-source-release-v1';
        $m += ['releaseName' => 'fixture', 'targetBranch' => 'codex/fixture', 'cssAsset' => 'assets/app-fixture.css', 'successResult' => 'SOURCE_RELEASE_PASS'];
        $m['httpChecks'] = ['/' => ['status' => 200], '/login' => ['status' => 200]];
        $m['baselineFiles'] = array_fill_keys(['public/build/manifest.json', 'bootstrap/cache/routes-v7.php', 'public/build/support-chat/manifest.json'], str_repeat('a', 64));
        sourceReleaseManifest($m);
        $this->addToAssertionCount(1);
        $m['cssAsset'] = '../outside.css';
        $this->expectExceptionMessage('source_release_paths');
        sourceReleaseManifest($m);
    }

    public function test_zip_members_cannot_escape_the_checked_source_release(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'np-source-');
        try {
            $zip = new \ZipArchive;
            $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFromString('assets/app-fixture.css', 'body{}');
            $zip->addFromString('manifest.json', '{}');
            $zip->close();
            sourceArchiveContract($path, 'assets/app-fixture.css');
            $zip->open($path);
            $zip->addFromString('../escape', 'rejected');
            $zip->close();
            $this->expectExceptionMessage('source_asset_member');
            sourceArchiveContract($path, 'assets/app-fixture.css');
        } finally {
            unlink($path);
        }
    }
}
