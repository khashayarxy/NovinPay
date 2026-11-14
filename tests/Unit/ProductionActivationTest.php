<?php

namespace Tests\Unit;

use NovinPay\Release\GateFailure;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function NovinPay\Release\Pr13\backupMetadata;
use function NovinPay\Release\Pr13\cliFence;
use function NovinPay\Release\Pr13\cronIsFenced;
use function NovinPay\Release\Pr13\effectiveProcessUid;
use function NovinPay\Release\Pr13\enabledChatEnvironment;
use function NovinPay\Release\Pr13\executableAfterMiss;
use function NovinPay\Release\Pr13\manifestPath;
use function NovinPay\Release\Pr13\observeDrain;
use function NovinPay\Release\Pr13\opcodeProbeSource;
use function NovinPay\Release\Pr13\phpProcesses;
use function NovinPay\Release\Pr13\processStartTicks;
use function NovinPay\Release\Pr13\recoveryAction;
use function NovinPay\Release\Pr13\releaseOpcodeCacheIsOff;
use function NovinPay\Release\Pr13\replaceOwned;
use function NovinPay\Release\Pr13\verifyCancelledAttempt;
use function NovinPay\Release\Pr13\waitForDrain;
use function NovinPay\Release\Pr13\webFence;

require_once __DIR__.'/../../scripts/release/pr13-activate.php';
require_once __DIR__.'/../../scripts/release/pr13-drain-observe.php';

final class ProductionActivationTest extends TestCase
{
    private string $scratch;

    public function test_opcode_guard_accepts_absent_engine_or_explicitly_disabled_registered_caches_only(): void
    {
        $this->assertTrue(releaseOpcodeCacheIsOff(false, false, false, false, false));
        foreach (['0', '', 'Off', 'false', 'no', 'none'] as $off) {
            $this->assertTrue(releaseOpcodeCacheIsOff($off, $off, '', true, true));
        }
        foreach ([['1', '0', '', true, true], ['0', '1', '', true, true], ['0', '0', '/synthetic-cache', true, true],
            [false, false, false, true, true], [false, false, false, false, true], ['0', false, '', true, false],
            ['unexpected', '0', '', true, true], ['0', '0', false, true, true]] as $unsafe) {
            $this->assertFalse(releaseOpcodeCacheIsOff(...$unsafe));
        }
    }

    public function test_real_php_without_ini_reproduces_false_directives_without_an_opcode_engine(): void
    {
        $contract = dirname(__DIR__, 2).'/scripts/release/pr13-activation-contract.php';
        $code = 'require '.var_export($contract, true).'; $a=ini_get("opcache.enable_cli");$b=ini_get("opcache.file_cache_only");$c=ini_get("opcache.file_cache");'
            .'echo json_encode(["oldGuard"=>! (bool)$a && ! (bool)$b && $c==="",'
            .'"correctedGuard"=>NovinPay\\Release\\Pr13\\releaseOpcodeCacheIsOff($a,$b,$c,extension_loaded("Zend OPcache"),function_exists("opcache_get_status"))]);';
        $p = proc_open([PHP_BINARY, '-n', '-d', 'opcache.enable_cli=0', '-d', 'opcache.file_cache_only=0', '-d', 'opcache.file_cache=', '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($p));
        $this->assertSame('', $err);
        $this->assertSame(['oldGuard' => false, 'correctedGuard' => true], json_decode($out, true, flags: JSON_THROW_ON_ERROR));
    }

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/np-activation-'.bin2hex(random_bytes(8));
        mkdir($this->scratch, 0700);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->scratch, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->scratch);
    }

    public function test_elapsed_time_does_not_establish_a_drain_while_php_or_database_writers_remain(): void
    {
        foreach ([[1, 0], [0, 1]] as [$php, $db]) {
            $time = 0;
            try {
                waitForDrain(static fn () => ['observable' => true, 'phpProcesses' => $php, 'otherDatabaseConnections' => $db], static function () use (&$time): void {
                    $time++;
                }, static function () use (&$time): float {
                    return $time;
                }, 3);
                $this->fail('A live writer was accepted after a delay');
            } catch (RuntimeException $error) {
                $this->assertSame('drain_not_established_no_ddl', $error->getMessage());
                $this->assertSame(3, $time);
            }
        }
    }

    public function test_a_new_writer_resets_the_consecutive_empty_sample_requirement(): void
    {
        $values = [0, 1, 0, 0];
        $samples = 0;
        $result = waitForDrain(static function () use (&$values, &$samples): array {
            $samples++;

            return ['observable' => true, 'phpProcesses' => array_shift($values), 'otherDatabaseConnections' => 0];
        }, static fn () => null, static fn () => 0.0, 10);
        $this->assertSame(4, $samples);
        $this->assertSame(2, $result['consecutiveEmptySamples']);
    }

    public function test_unobservable_processes_never_count_as_a_successful_drain(): void
    {
        $this->expectExceptionMessage('drain_visibility_unavailable');
        waitForDrain(static fn () => ['observable' => false, 'phpProcesses' => 0, 'otherDatabaseConnections' => 0], static fn () => null, static fn () => 0.0, 10);
    }

    public function test_proc_must_be_observable_instead_of_assuming_no_workers(): void
    {
        $this->expectExceptionMessage('drain_proc_unavailable');
        phpProcesses($this->scratch, 1321, 999);
    }

    public function test_manifest_stays_resolvable_when_child_working_directory_changes(): void
    {
        $file = $this->scratch.'/manifest.json';
        file_put_contents($file, '{"synthetic":true}');
        mkdir($this->scratch.'/child');
        $cwd = getcwd();
        try {
            chdir($this->scratch);
            $absolute = manifestPath('manifest.json', $this->scratch);
            chdir($this->scratch.'/child');
            $this->assertSame('{"synthetic":true}', file_get_contents($absolute));
            $this->assertSame(realpath($file), $absolute);
        } finally {
            chdir($cwd);
        }
        $this->expectExceptionMessage('activation_manifest_directory');
        manifestPath($file, $this->scratch.'/child');
    }

    public function test_new_generation_requires_pinned_cancellation_without_reusing_or_mutating_old_journal(): void
    {
        $receipt = json_encode(['result' => 'PR13_PRE_DDL_CANCELLATION_PASS', 'manifest' => 'old-manifest',
            'baseline' => 'baseline', 'databaseWrites' => false, 'activationReplayAllowed' => false]);
        $file = $this->scratch.'/cancellation.complete.json';
        file_put_contents($file, $receipt);
        chmod($file, 0600);
        $hash = hash('sha256', $receipt);
        verifyCancelledAttempt($this->scratch, $hash, 'old-manifest', 'baseline');
        $this->assertSame($receipt, file_get_contents($file));
        foreach (['ddl.started.json', 'source.started.json', 'admission.started.json', 'activation.complete.json'] as $marker) {
            file_put_contents($this->scratch.'/'.$marker, '{}');
            try {
                verifyCancelledAttempt($this->scratch, $hash, 'old-manifest', 'baseline');
                $this->fail('A previous activation boundary must block a new attempt.');
            } catch (RuntimeException $error) {
                $this->assertSame('previous_attempt_crossed_activation_boundary', $error->getMessage());
            } finally {
                unlink($this->scratch.'/'.$marker);
            }
            $this->assertSame($receipt, file_get_contents($file));
        }
        $this->expectExceptionMessage('previous_cancellation_pin');
        verifyCancelledAttempt($this->scratch, str_repeat('0', 64), 'old-manifest', 'baseline');
    }

    public function test_a_changed_baseline_or_replay_allowed_receipt_cannot_authorize_a_new_attempt(): void
    {
        foreach ([['baseline' => 'wrong'], ['activationReplayAllowed' => true], ['databaseWrites' => true], ['manifest' => 'wrong']] as $change) {
            $receipt = json_encode(array_replace(['result' => 'PR13_PRE_DDL_CANCELLATION_PASS', 'manifest' => 'old-manifest',
                'baseline' => 'baseline', 'databaseWrites' => false, 'activationReplayAllowed' => false], $change));
            $file = $this->scratch.'/cancellation.complete.json';
            file_put_contents($file, $receipt);
            chmod($file, 0600);
            try {
                verifyCancelledAttempt($this->scratch, hash('sha256', $receipt), 'old-manifest', 'baseline');
                $this->fail('A cancellation for different inputs cannot authorize activation.');
            } catch (RuntimeException $error) {
                $this->assertSame('previous_cancellation_contract', $error->getMessage());
            }
        }
    }

    public function test_versioned_bundle_imports_do_not_mix_old_root_release_libraries(): void
    {
        $root = dirname(__DIR__, 2).'/scripts/release/';
        $bundle = $this->scratch.'/bundle';
        mkdir($bundle);
        $files = ['pr13-activate.php' => 'pr13-activate.php', 'pr13-live-database.php' => 'pr13-live-database.php',
            'pr13-cancel-before-ddl.php' => 'pr13-cancel-before-ddl.php', 'pr13-activation-contract.php' => 'pr13-activation-contract.php',
            'pr13-rehearsal-v2.php' => 'pr13-rehearsal.php', 'pr13-recovery-v3.php' => 'pr13-recovery.php',
            'runtime.php' => 'runtime.php', 'lib.php' => 'lib.php', 'backup-policy.php' => 'backup-policy.php',
            'schema-contract.php' => 'schema-contract.php', 'trigger-contracts.json' => 'trigger-contracts.json',
            'pr13-schema-contract.php' => 'pr13-schema-contract.php', 'pr13-maintenance-snapshot.php' => 'pr13-maintenance-snapshot.php'];
        foreach ($files as $to => $from) {
            copy($root.$from, $bundle.'/'.$to);
        }
        file_put_contents($this->scratch.'/runtime.php', '<?php throw new RuntimeException("old_root_runtime_loaded");');
        $loader = $bundle.'/loader.php';
        file_put_contents($loader, '<?php require __DIR__."/pr13-activate.php"; require __DIR__."/pr13-live-database.php"; require __DIR__."/pr13-cancel-before-ddl.php"; require __DIR__."/pr13-recovery-v3.php"; echo NovinPay\\Release\\backupPolicy()["maxSnapshotAgeSeconds"];');
        [$exit, $out, $err] = $this->execute($loader);
        $this->assertSame([0, '259200', ''], [$exit, $out, $err]);
    }

    public function test_worker_start_identity_survives_spaces_and_parentheses_in_the_process_name(): void
    {
        $suffix = ['S', ...array_fill(0, 18, '0'), '123456', '0'];
        $this->assertSame('123456', processStartTicks('42 (php (worker)) '.implode(' ', $suffix)));
        $this->expectExceptionMessage('process_start_metadata');
        processStartTicks('42 invalid');
    }

    public function test_process_identity_uses_effective_uid_not_a_setuid_helpers_real_uid(): void
    {
        $this->assertSame(0, effectiveProcessUid("Name:\tsudo\nUid:\t1321\t0\t0\t0\n"));
        $this->assertSame(1321, effectiveProcessUid("Name:\tlsphp\nUid:\t1321\t1321\t1321\t1321\n"));
        $this->expectExceptionMessage('drain_process_uid');
        effectiveProcessUid("Uid:\t1321\n");
    }

    public function test_authenticated_backup_metadata_is_read_in_memory_and_duplicate_members_are_rejected(): void
    {
        $json = '{"sourceCommit":"synthetic"}';
        $header = str_pad('release-metadata.json', 512, "\0");
        $header = substr_replace($header, str_pad(decoct(strlen($json)), 11, '0', STR_PAD_LEFT)."\0", 124, 12);
        $header[156] = '0';
        $member = $header.str_pad($json, 512, "\0");
        $this->assertSame(['sourceCommit' => 'synthetic'], backupMetadata(gzencode($member.str_repeat("\0", 1024))));
        $this->assertSame([], array_values(array_diff(scandir($this->scratch), ['.', '..'])));
        $this->expectExceptionMessage('backup_metadata_member');
        backupMetadata(gzencode($member.$member.str_repeat("\0", 1024)));
    }

    public function test_linux_proc_detects_a_real_worker_in_a_readable_kernel_inventory_without_killing_it(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid')) {
            $this->markTestSkipped('Linux kernel inventory test; Windows cannot establish cPanel /proc visibility.');
        }
        $worker = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=', '-r', 'echo "ready\\n"; fflush(STDOUT); fgets(STDIN);'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        try {
            $this->assertSame("ready\n", fgets($pipes[1]));
            // The hosted CI account contains unrelated non-dumpable processes.
            // Test the real child's kernel identity in an observable inventory;
            // production still scans ALL same-effective-UID processes and stops
            // on unreadable entries. This fixture is not host qualification.
            $pid = proc_get_status($worker)['pid'];
            $proc = $this->scratch.'/proc';
            mkdir($proc.'/self', 0700, true);
            mkdir($proc.'/'.$pid, 0700);
            file_put_contents($proc.'/self/status', file_get_contents('/proc/self/status'));
            file_put_contents($proc.'/'.$pid.'/status', file_get_contents('/proc/'.$pid.'/status'));
            $this->assertTrue(symlink(readlink('/proc/'.$pid.'/exe'), $proc.'/'.$pid.'/exe'));
            $this->assertSame(1, phpProcesses($proc, posix_geteuid(), getmypid()));
        } finally {
            fclose($pipes[0]); // Worker exits naturally; never terminate a worker.
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($worker));
        }
    }

    #[WithoutErrorHandler] // Exercise expected failed /proc reads with native @ semantics.
    public function test_an_unreadable_same_effective_uid_executable_still_blocks_the_release(): void
    {
        $proc = $this->scratch.'/proc';
        mkdir($proc.'/self', 0700, true);
        mkdir($proc.'/222', 0700);
        file_put_contents($proc.'/self/status', 'synthetic own status');
        file_put_contents($proc.'/222/status', "Name:\tunknown\nUid:\t1321\t1321\t1321\t1321\nState:\tS\n");
        $this->expectExceptionMessage('drain_executable_unreadable');
        phpProcesses($proc, 1321, 999);
    }

    public function test_process_becoming_zombie_after_status_read_is_reobserved_before_failing(): void
    {
        $entry = $this->scratch.'/222';
        mkdir($entry);
        $running = "Uid:\t1321\t1321\t1321\t1321\nState:\tS\n";
        file_put_contents($entry.'/status', str_replace("State:\tS", "State:\tZ", $running));
        $this->assertNull(executableAfterMiss($entry, $running, 1321));
    }

    #[WithoutErrorHandler]
    public function test_process_disappearance_does_not_use_a_cached_directory_result(): void
    {
        $entry = $this->scratch.'/222';
        mkdir($entry);
        $this->assertTrue(is_dir($entry)); // Deliberately populate PHP's stat cache.
        rmdir($entry);
        $this->assertNull(executableAfterMiss($entry, "State:\tS\n", 1321));
    }

    #[WithoutErrorHandler]
    public function test_a_previous_zombie_sample_cannot_hide_a_current_live_unreadable_process(): void
    {
        $entry = $this->scratch.'/222';
        mkdir($entry);
        file_put_contents($entry.'/status', "Uid:\t1321\t1321\t1321\t1321\nState:\tS\n");
        try {
            executableAfterMiss($entry, "State:\tZ\n", 1321);
            $this->fail('A current unreadable live process must block activation.');
        } catch (GateFailure $error) {
            $this->assertSame('drain_executable_unreadable', $error->check);
            $this->assertSame('Z', $error->actual['stateBefore']);
            $this->assertSame('S', $error->actual['stateAfter']);
            $this->assertSame(222, $error->actual['pid']);
            $this->assertStringNotContainsString($entry, json_encode($error->actual));
        }
    }

    public function test_process_uid_transition_is_not_treated_as_proven_absence(): void
    {
        $entry = $this->scratch.'/222';
        mkdir($entry);
        file_put_contents($entry.'/status', "Uid:\t1321\t0\t0\t0\nState:\tS\n");
        $this->expectExceptionMessage('drain_process_identity_changed');
        executableAfterMiss($entry, "Uid:\t1321\t1321\t1321\t1321\nState:\tS\n", 1321);
    }

    #[WithoutErrorHandler]
    public function test_readonly_observer_reports_the_blocking_process_without_private_process_content(): void
    {
        $proc = $this->scratch.'/proc';
        mkdir($proc.'/self', 0700, true);
        mkdir($proc.'/222');
        mkdir($proc.'/333');
        file_put_contents($proc.'/self/status', 'observer');
        file_put_contents($proc.'/222/status', "Name:\tprivate-process-name\nUid:\t1321\t1321\t1321\t1321\nState:\tS\n");
        file_put_contents($proc.'/333/status', "Name:\tunrelated\nUid:\t2000\t2000\t2000\t2000\nState:\tS\n");
        $sample = observeDrain($proc, 1321, 999);
        $this->assertNull($sample['phpProcesses']);
        $this->assertSame('drain_executable_unreadable', $sample['failures'][0]['check']);
        $this->assertSame([222], array_column($sample['executableMisses'], 'pid'));
        $this->assertStringNotContainsString('private-process-name', json_encode($sample));
        $this->assertStringNotContainsString('unrelated', json_encode($sample));
        $this->assertStringNotContainsString($this->scratch, json_encode($sample));
    }

    public function test_only_the_fenced_artisan_scheduler_is_allowed_not_other_database_or_php_cron(): void
    {
        $this->assertTrue(cronIsFenced("# no jobs\n"));
        $this->assertTrue(cronIsFenced('* * * * * /opt/cpanel/ea-php84/root/usr/bin/php /home/novinpay/novinpay/artisan schedule:run >> /dev/null 2>&1'));
        foreach (['* * * * * mysql < unknown.sql', '* * * * * php arbitrary.php', '@reboot php worker.php', '* * * * * /opt/cpanel/ea-php84/root/usr/bin/php /home/novinpay/novinpay/artisan schedule:run; php other.php'] as $cron) {
            $this->assertFalse(cronIsFenced($cron));
        }
    }

    private function execute(string $file, string $prefix = ''): array
    {
        $p = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=', '-r', $prefix.'require '.var_export($file, true).';'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($p), $out, $err];
    }

    public function test_direct_activation_reports_scope_failure_even_when_server_globals_are_disabled(): void
    {
        foreach (['GPC', 'GPCS'] as $order) {
            // The real entry point fails its fixed host/tool scope before any I/O.
            $file = dirname(__DIR__, 2).'/scripts/release/pr13-activate.php';
            $p = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'variables_order='.$order, $file, 'unused-manifest', 'unused-pin'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(1, proc_close($p));
            $this->assertSame('', $err);
            $report = json_decode(trim($out), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('PR13_ACTIVATION_STOP', $report['result']);
            $this->assertSame('activation_scope', $report['check']);
            $this->assertFalse($report['productionModified']);
        }
    }

    public function test_silent_exit_observer_reports_invalid_scope_without_server_globals_or_application_access(): void
    {
        [$exit, $out, $err] = $this->execute(dirname(__DIR__, 2).'/scripts/release/pr13-silent-observe.php', 'unset($_SERVER);');
        $this->assertSame(1, $exit);
        $this->assertSame('', $err);
        $report = json_decode(trim($out), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('PR13_READONLY_SILENT_INSPECTION_STOP', $report['result']);
        $this->assertSame('readonly_inspection_scope', $report['check']);
        $this->assertSame([], $report['observations']);
        $this->assertFalse($report['retryAuthorized']);
    }

    public function test_real_cli_fence_exits_before_any_artisan_bootstrap(): void
    {
        $file = $this->scratch.'/artisan';
        file_put_contents($file, cliFence());
        [$exit, $out, $err] = $this->execute($file);
        $this->assertSame(75, $exit);
        $this->assertSame('', $out);
        $this->assertStringContainsString('command not executed', $err);
        $this->assertStringNotContainsString('vendor/autoload', file_get_contents($file));
    }

    public function test_generated_web_probe_rejects_missing_authorization_and_cannot_claim_cli_as_web_php(): void
    {
        $file = $this->scratch.'/probe.php';
        file_put_contents($file, opcodeProbeSource([], hash('sha256', 'synthetic-token')));
        $status = 'register_shutdown_function(function(){fwrite(STDERR,(string)http_response_code());});';
        [$exit, $out, $err] = $this->execute($file, $status);
        $this->assertSame([0, '', '404'], [$exit, $out, $err]);
        [$exit, $out, $err] = $this->execute($file, '$_SERVER["HTTP_X_NP_RELEASE"]="synthetic-token";'.$status);
        $this->assertSame(0, $exit);
        $this->assertSame('503', $err);
        $this->assertSame('STOP', json_decode($out, true)['result']);
    }

    public function test_real_web_fence_returns_503_for_json_and_empty_head_without_loading_the_framework(): void
    {
        mkdir($this->scratch.'/storage/framework', 0700, true);
        mkdir($this->scratch.'/public', 0700);
        file_put_contents($this->scratch.'/public/maintenance.php', '<?php echo "approved standalone";');
        $file = $this->scratch.'/storage/framework/maintenance.php';
        file_put_contents($file, webFence());
        $status = 'register_shutdown_function(function(){fwrite(STDERR,(string)http_response_code());});';
        foreach ([['GET', 'application/json', '{"message":"Service Unavailable"}'], ['HEAD', 'application/json', ''], ['GET', 'text/html', 'approved standalone']] as [$method, $accept, $expected]) {
            [$exit, $out, $err] = $this->execute($file, '$_SERVER["REQUEST_METHOD"]='.var_export($method, true).';$_SERVER["HTTP_ACCEPT"]='.var_export($accept, true).';'.$status);
            $this->assertSame(0, $exit);
            $this->assertSame($expected, $out);
            $this->assertSame('503', $err);
        }
    }

    public function test_replacement_refuses_changed_input_and_preserves_original_bytes(): void
    {
        $file = $this->scratch.'/entry';
        file_put_contents($file, 'original');
        try {
            replaceOwned($file, hash('sha256', 'wrong'), 'replacement');
            $this->fail('Changed original overwritten');
        } catch (RuntimeException $error) {
            $this->assertSame('replace_original_identity', $error->getMessage());
            $this->assertSame('original', file_get_contents($file));
        }
        replaceOwned($file, hash('sha256', 'original'), 'replacement');
        $this->assertSame('replacement', file_get_contents($file));
    }

    public function test_only_chat_flag_changes_and_duplicate_environment_keys_fail_closed(): void
    {
        $before = "APP_KEY=synthetic\nDB_PASSWORD=synthetic\nSMSIR_ENABLED=false\nSUPPORT_CHAT_ENABLED=false\n";
        $after = enabledChatEnvironment($before);
        $this->assertSame(str_replace('SUPPORT_CHAT_ENABLED=false', 'SUPPORT_CHAT_ENABLED=true', $before), $after);
        $this->assertSame($after, enabledChatEnvironment($after));
        $this->assertSame("A=1\nSUPPORT_CHAT_ENABLED=true\n", enabledChatEnvironment('A=1'));
        $this->expectExceptionMessage('duplicate_chat_configuration');
        enabledChatEnvironment("SUPPORT_CHAT_ENABLED=false\nSUPPORT_CHAT_ENABLED=true\n");
    }

    public function test_partial_ddl_and_new_data_never_offer_a_baseline_or_database_rollback(): void
    {
        $this->assertSame('verified_baseline_cancellation_only', recoveryAction(false, false));
        foreach ([[true, false], [true, true], [false, true]] as [$ddl, $admitted]) {
            $this->assertSame('keep_both_fences_reconcile_forward_no_database_restore', recoveryAction($ddl, $admitted));
        }
    }
}
