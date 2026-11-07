<?php

namespace Tests\Unit;

use NovinPay\Release\GateFailure;
use NovinPay\Release\Journal;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function NovinPay\Release\failureReport;
use function NovinPay\Release\healthDecision;
use function NovinPay\Release\quotaGate;
use function NovinPay\Release\recoveryGate;
use function NovinPay\Release\restoreDefaultsPlan;
use function NovinPay\Release\resumeDecision;

require_once __DIR__.'/../../scripts/release/lib.php';
require_once __DIR__.'/../../scripts/release/runtime.php';
require_once __DIR__.'/../../scripts/release/backup.php';
require_once __DIR__.'/../../scripts/release/backup-io.php';
require_once __DIR__.'/../../scripts/release/activation.php';

final class ReleasePipelineTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'np-release-test-'.bin2hex(random_bytes(10));
        mkdir($this->scratch, 0700);
    }

    protected function tearDown(): void
    {
        $target = realpath($this->scratch);
        $temp = realpath(sys_get_temp_dir());
        if ($target === false || $temp === false || ! str_starts_with($target, $temp.DIRECTORY_SEPARATOR.'np-release-test-')) {
            throw new \RuntimeException('Refusing cleanup outside the isolated test directory');
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isLink()) {
                unlink($file->getPathname());
            } elseif ($file->isFile()) {
                chmod($file->getPathname(), 0600);
                unlink($file->getPathname());
            } elseif ($file->isDir()) {
                rmdir($file->getPathname());
            }
        }
        rmdir($target);
        parent::tearDown();
    }

    public function test_journal_reuses_verified_completion_without_repeating_work(): void
    {
        $journal = new Journal($this->scratch, 'manifest');
        $calls = 0;
        try {
            $work = function () use (&$calls): array {
                $calls++;

                return ['value' => 'ok'];
            };
            $verify = fn (array $result): bool => ($result['value'] ?? '') === 'ok';
            $this->assertSame(['value' => 'ok'], $journal->phase('backup', $work, $verify));
            $this->assertSame(['value' => 'ok'], $journal->phase('backup', $work, $verify));
            $this->assertSame(1, $calls);
            $this->assertFileExists($this->scratch.'/backup.started.json');
            $this->assertFileExists($this->scratch.'/backup.complete.json');
        } finally {
            $journal->close();
        }
    }

    public function test_interrupted_journal_never_repeats_an_ambiguous_backup(): void
    {
        $journal = new Journal($this->scratch, 'manifest');
        $calls = 0;
        try {
            try {
                $journal->phase('backup', function () use (&$calls): array {
                    $calls++;
                    throw new \RuntimeException('synthetic_failure');
                }, fn ($r) => true);
            } catch (\RuntimeException) {
            }
            try {
                $journal->phase('backup', function () use (&$calls): array {
                    $calls++;

                    return [];
                }, fn ($r) => true);
                $this->fail('Repeated mutation');
            } catch (GateFailure $e) {
                $this->assertSame('interrupted_phase', $e->check);
            }
            $this->assertSame(1, $calls);
            $this->assertNotEmpty(glob($this->scratch.'/backup.failed-*.json'));
        } finally {
            $journal->close();
        }
    }

    public function test_journal_rejects_changed_postconditions_and_another_manifest(): void
    {
        $journal = new Journal($this->scratch, 'original');
        try {
            $journal->phase('backup', fn () => ['value' => 'ok'], fn ($r) => true);
        } finally {
            $journal->close();
        }
        $journal = new Journal($this->scratch, 'different');
        try {
            $this->expectExceptionMessage('phase_postcondition_changed');
            $journal->phase('backup', fn () => [], fn ($r) => true);
        } finally {
            $journal->close();
        }
    }

    public function test_real_backup_encryption_roundtrip_and_authentication_failure(): void
    {
        $key = random_bytes(32);
        $plain = random_bytes(512);
        $cipher = \NovinPay\Release\Backup\encryptBundle($plain, $key);
        $this->assertSame($plain, \NovinPay\Release\Backup\decryptBundle($cipher, $key));
        $cipher[strlen($cipher) - 1] = chr(ord($cipher[strlen($cipher) - 1]) ^ 1);
        $this->expectExceptionMessage('authentication_failed');
        \NovinPay\Release\Backup\decryptBundle($cipher, $key);
    }

    public function test_credential_identity_requires_an_explicit_private_manifest_pin(): void
    {
        $hash = hash('sha256', 'synthetic fixture only');
        \NovinPay\Release\Backup\verifyCredentialPin(['credentialsSha256' => $hash], $hash);
        $this->addToAssertionCount(1);
        foreach ([[], ['credentialsSha256' => 'invalid'], ['credentialsSha256' => str_repeat('a', 64)]] as $manifest) {
            try {
                \NovinPay\Release\Backup\verifyCredentialPin($manifest, $hash);
                $this->fail('Missing or altered private identity accepted');
            } catch (GateFailure $error) {
                $this->assertSame('retained_credentials_changed', $error->check);
            }
        }
    }

    public function test_backup_native_process_creates_private_output_and_preserves_failed_evidence(): void
    {
        define('NovinPay\\Release\\Backup\\OUT', $this->scratch);
        \NovinPay\Release\Backup\runProcess([PHP_BINARY, '-r', 'echo "synthetic export";'], null, $this->scratch.'/output.txt');
        $this->assertSame('synthetic export', file_get_contents($this->scratch.'/output.txt'));
        try {
            \NovinPay\Release\Backup\runProcess([PHP_BINARY, '-r', 'fwrite(STDERR,"synthetic-private-error");exit(23);'], null, $this->scratch.'/failed.txt');
            $this->fail('Nonzero native process accepted');
        } catch (GateFailure $error) {
            $this->assertSame('native_process_failed', $error->check);
            $this->assertSame(23, $error->actual['exitCode']);
            $this->assertFileExists($this->scratch.'/failed.txt');
            $this->assertStringNotContainsString('synthetic-private-error', json_encode(\NovinPay\Release\failureReport($error, 'backup', 'export')));
        }
    }

    public function test_activation_order_and_failure_do_not_admit_traffic(): void
    {
        foreach (['snapshot', 'fence', 'source', 'readiness', 'admit', 'verify'] as $failAt) {
            $seen = [];
            $steps = [];
            foreach (['snapshot', 'fence', 'source', 'readiness', 'admit', 'verify'] as $name) {
                $steps[$name] = static function () use (&$seen, $name, $failAt): void {
                    $seen[] = $name;
                    if ($name === $failAt) {
                        throw new \RuntimeException('injected');
                    }
                };
            }
            try {
                \NovinPay\Release\Activation\sequence($steps);
                $this->fail('Failure was suppressed');
            } catch (\RuntimeException $e) {
                $this->assertSame('injected', $e->getMessage());
            }
            $index = array_search($failAt, array_keys($steps), true);
            $this->assertSame(array_slice(array_keys($steps), 0, $index + 1), $seen);
            if ($index < 4) {
                $this->assertNotContains('admit', $seen);
            }
        }
    }

    public function test_activation_success_runs_each_step_once(): void
    {
        $seen = [];
        $steps = [];
        foreach (['snapshot', 'fence', 'source', 'readiness', 'admit', 'verify'] as $name) {
            $steps[$name] = static function () use (&$seen, $name): void {
                $seen[] = $name;
            };
        }
        \NovinPay\Release\Activation\sequence($steps);
        $this->assertSame(array_keys($steps), $seen);
    }

    public function test_code_only_git_rollback_preserves_local_modifications_and_private_state(): void
    {
        mkdir($this->scratch.'/public');
        mkdir($this->scratch.'/.hooks');
        file_put_contents($this->scratch.'/public/.htaccess', 'baseline');
        file_put_contents($this->scratch.'/page.php', 'old page');
        $git = function (array $args): string {
            $result = \NovinPay\Release\command(array_merge(['git', '-c', 'core.autocrlf=false', '-c', 'core.hooksPath='.$this->scratch.'/.hooks',
                '-c', 'user.name=fixture', '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false', '-C', $this->scratch], $args), $this->scratch, 'fixture_git');
            $this->assertSame(0, $result['exit']);

            return rtrim($result['stdout'], "\r\n");
        };
        $git(['init', '--initial-branch=main']);
        $git(['add', '--', 'public/.htaccess', 'page.php']);
        $git(['commit', '-m', 'baseline']);
        $baseline = $git(['rev-parse', 'HEAD']);
        $git(['switch', '-c', 'feature']);
        file_put_contents($this->scratch.'/page.php', 'new page');
        $git(['add', '--', 'page.php']);
        $git(['commit', '-m', 'reviewed']);
        $target = $git(['rev-parse', 'HEAD']);
        $git(['switch', 'main']);
        file_put_contents($this->scratch.'/public/.htaccess', 'owner change');
        file_put_contents($this->scratch.'/private-state.json', 'synthetic audit history');
        file_put_contents($this->scratch.'/customer-upload.txt', 'synthetic upload');
        $git(['merge', '--ff-only', $target]);
        $this->assertSame('new page', file_get_contents($this->scratch.'/page.php'));
        $git(['switch', '--detach', $baseline]);
        $this->assertSame('old page', file_get_contents($this->scratch.'/page.php'));
        $this->assertSame('owner change', file_get_contents($this->scratch.'/public/.htaccess'));
        $this->assertSame('synthetic audit history', file_get_contents($this->scratch.'/private-state.json'));
        $this->assertSame('synthetic upload', file_get_contents($this->scratch.'/customer-upload.txt'));
        $this->assertStringContainsString(' M public/.htaccess', $git(['status', '--porcelain']));
    }

    public function test_entry_point_rejects_execution_modes_before_host_access(): void
    {
        $process = proc_open([PHP_BINARY, '-n', __DIR__.'/../../scripts/release/release.php', 'activate'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(1, proc_close($process));
        $this->assertSame('', $stdout);
        $report = json_decode($stderr, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('invocation', $report['check']);
        $this->assertFalse($report['productionModified']);
        $this->assertFalse($report['retrySafe']);
    }

    private function quota(): array
    {
        return ['account' => 'novinpay', 'apiHost' => 'leda.asemanhost.com:2083', 'method' => 'Quota/get_quota_info',
            'httpsCertificateValidated' => true, 'underQuota' => true, 'nonce' => 'request-1', 'sourceCommit' => 'reviewed',
            'observedAtUnix' => 1000, 'freeBytes' => 4000000000, 'usedBytes' => 1000000000, 'limitBytes' => 5000000000];
    }

    public function test_matching_restore_defaults_do_not_require_alter_privilege(): void
    {
        $defaults = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'];
        $this->assertSame('already_matches_no_alter', restoreDefaultsPlan($defaults, $defaults, ['CREATE', 'INSERT', 'SELECT', 'TRIGGER']));
    }

    public function test_mismatched_restore_defaults_fail_before_any_write_without_alter(): void
    {
        $this->expectException(GateFailure::class);
        $this->expectExceptionMessage('restore_defaults_privilege');
        restoreDefaultsPlan(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'], ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci'], ['CREATE']);
    }

    public function test_approved_scoped_alter_is_explicitly_required_only_for_mismatch(): void
    {
        $this->assertSame('disposable_alter_required', restoreDefaultsPlan(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci'], ['ALTER']));
    }

    public function test_database_error_is_actionable_without_exception_message_or_credentials(): void
    {
        $error = new PDOException('Access denied password=synthetic-secret SQL ALTER DATABASE private_schema', 42000);
        $error->errorInfo = ['42000', 1044, 'synthetic-sensitive-error'];
        $report = failureReport($error, 'backup_preflight', 'restore_defaults');
        $this->assertSame('42000', $report['actual']['sqlState']);
        $this->assertSame(1044, $report['actual']['driverCode']);
        $this->assertFalse($report['productionModified']);
        $this->assertFalse($report['retrySafe']);
        $this->assertStringNotContainsString('synthetic', json_encode($report));
        $this->assertStringNotContainsString('private_schema', json_encode($report));
    }

    public function test_php_type_errors_are_distinguished_without_exposing_arguments(): void
    {
        $report = failureReport(new \TypeError('secret=example'), 'preflight', 'runtime_parser');
        $this->assertSame('type_error', $report['category']);
        $this->assertSame('runtime_parser', $report['check']);
        $this->assertStringNotContainsString('secret', json_encode($report));
    }

    public function test_valid_fresh_quota_passes(): void
    {
        quotaGate($this->quota(), 1120, 'request-1', 'reviewed', 900000000);
        $this->addToAssertionCount(1);
    }

    public static function invalidQuota(): array
    {
        return [
            'expired' => [[], 1121, 'quota_freshness'],
            'future' => [[], 999, 'quota_freshness'],
            'wrong_commit' => [['sourceCommit' => 'unexpected'], 1000, 'quota_identity'],
            'replayed_request' => [['nonce' => 'other'], 1000, 'quota_identity'],
            'unverified_credentials_or_tls' => [['httpsCertificateValidated' => false], 1000, 'quota_identity'],
            'insufficient_account_reserve' => [['freeBytes' => 2900000000], 1000, 'quota_headroom'],
            'invalid_measurement' => [['freeBytes' => '4000000000'], 1000, 'quota_headroom'],
        ];
    }

    #[DataProvider('invalidQuota')]
    public function test_quota_fails_closed(array $changes, int $now, string $code): void
    {
        $this->expectExceptionMessage($code);
        quotaGate(array_replace($this->quota(), $changes), $now, 'request-1', 'reviewed', 900000000);
    }

    public function test_physical_space_is_independent_of_account_quota(): void
    {
        $this->expectExceptionMessage('quota_headroom');
        quotaGate($this->quota(), 1000, 'request-1', 'reviewed', 1);
    }

    private function recovery(): array
    {
        return ['authenticatedDecryption' => true, 'archiveContentsVerified' => true, 'isolatedRestoreVerified' => true,
            'recoveryKeyAvailable' => true, 'sourceDatabase' => 'novinpay_live1', 'snapshotUnix' => 1000];
    }

    public function test_data_release_requires_its_explicit_snapshot_freshness_policy(): void
    {
        $this->expectExceptionMessage('backup_freshness');
        recoveryGate($this->recovery(), ['risk' => 'data', 'automaticDatabaseRestore' => false, 'maxSnapshotAgeSeconds' => 3600], 4601);
    }

    public function test_presentation_reuse_requires_reviewed_preservation_and_code_only_recovery(): void
    {
        recoveryGate($this->recovery(), ['risk' => 'presentation', 'reviewedPathsOnly' => true, 'codeRollbackVerified' => true,
            'currentConfigurationPreserved' => true, 'persistentStatePreserved' => true, 'approvedReuse' => true, 'automaticDatabaseRestore' => false], 100000);
        $this->addToAssertionCount(1);
    }

    public function test_presentation_label_alone_never_bypasses_recovery(): void
    {
        $this->expectExceptionMessage('backup_freshness');
        recoveryGate($this->recovery(), ['risk' => 'presentation', 'automaticDatabaseRestore' => false], 100000);
    }

    public function test_no_old_database_restore_as_automatic_code_rollback(): void
    {
        $this->expectExceptionMessage('recovery_identity');
        recoveryGate($this->recovery(), ['automaticDatabaseRestore' => true, 'maxSnapshotAgeSeconds' => 3600], 1000);
    }

    public function test_unverified_offhost_recovery_is_not_accepted(): void
    {
        $this->expectExceptionMessage('recovery_authenticatedDecryption');
        recoveryGate(array_replace($this->recovery(), ['authenticatedDecryption' => false]), [], 1000);
    }

    public static function resumption(): array
    {
        return [
            'new' => ['pending', false, 'run'],
            'completed_and_verified' => ['complete', true, 'reuse'],
            'changed_receipt' => ['complete', false, 'stop_evidence_changed'],
            'interrupted_backup' => ['started', false, 'stop_reconcile_partial_operation'],
            'interrupted_activation_even_if_head_matches' => ['started', true, 'stop_reconcile_partial_operation'],
            'failed_or_partial_artifacts' => ['failed', true, 'stop_reconcile_partial_operation'],
            'corrupt_journal' => ['unknown', false, 'stop_invalid_journal'],
        ];
    }

    #[DataProvider('resumption')]
    public function test_operations_are_not_blindly_repeated(string $status, bool $matches, string $expected): void
    {
        $this->assertSame($expected, resumeDecision($status, $matches));
    }

    public static function health(): array
    {
        return [
            ['pass', true, true, 'pass'],
            ['transport_failure', true, true, 'live_verification_pending_75'],
            ['application_failure', true, true, 'fence_stop'],
            ['transport_failure', false, true, 'fence_stop'],
            ['pass', true, false, 'fence_stop'],
            ['unknown', true, true, 'fence_stop'],
        ];
    }

    #[DataProvider('health')]
    public function test_health_failure_decisions(string $result, bool $local, bool $preserved, string $expected): void
    {
        $this->assertSame($expected, healthDecision($result, $local, $preserved));
    }
}
