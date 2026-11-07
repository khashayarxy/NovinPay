<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../scripts/release/pr13-launch-result.php';

final class ProductionLaunchTest extends TestCase
{
    private function manifest(): array
    {
        return ['candidate' => str_repeat('a', 40)];
    }

    public function test_empty_or_malformed_output_never_becomes_success_even_with_exit_zero(): void
    {
        foreach (['', ' ', 'synthetic-private-output', '{"result":', "{}\n{}"] as $stdout) {
            foreach ([0, 1, 139] as $exit) {
                [$report, $code] = \pr13LaunchResult($stdout, $exit, $this->manifest(), 'pin', []);
                $this->assertSame(1, $code);
                $this->assertSame('PR13_ACTIVATION_LAUNCH_STOP', $report['result']);
                $this->assertFalse($report['retrySafe']);
                $this->assertTrue($report['maintenanceMayBeActive']);
                $this->assertStringNotContainsString('synthetic-private-output', json_encode($report));
            }
        }
    }

    public function test_pass_requires_matching_final_receipt_and_exit_status(): void
    {
        $pass = ['result' => 'PR13_RELEASE_PASS', 'candidate' => $this->manifest()['candidate'],
            'migrations' => 22, 'triggers' => 13, 'maintenanceEnabled' => false];
        $receipt = $pass + ['manifest' => 'pin'];
        [$report, $code] = \pr13LaunchResult(json_encode($pass), 0, $this->manifest(), 'pin', ['activation.complete.json' => $receipt]);
        $this->assertSame(0, $code);
        $this->assertSame('PR13_RELEASE_PASS', $report['result']);
        foreach ([[], ['manifest' => 'wrong'], ['candidate' => str_repeat('b', 40)], ['migrations' => 14], ['triggers' => 5], ['maintenanceEnabled' => true]] as $change) {
            $receipts = $change === [] ? [] : ['activation.complete.json' => array_replace($receipt, $change)];
            [$report, $code] = \pr13LaunchResult(json_encode($pass), 0, $this->manifest(), 'pin', $receipts);
            $this->assertSame(1, $code);
            $this->assertSame('release_result_receipt_mismatch', $report['check']);
        }
        [$report, $code] = \pr13LaunchResult(json_encode($pass), 1, $this->manifest(), 'pin', ['activation.complete.json' => $receipt]);
        $this->assertSame(1, $code);
    }

    public function test_stop_preserves_phase_without_exposing_private_extra_fields(): void
    {
        $stop = ['result' => 'PR13_ACTIVATION_STOP', 'phase' => 'fence_and_drain', 'check' => 'drain_executable_unreadable',
            'category' => 'guard', 'maintenanceMayBeActive' => true, 'private' => 'synthetic-private-output'];
        [$report, $code] = \pr13LaunchResult(json_encode($stop), 1, $this->manifest(), 'pin', []);
        $this->assertSame(1, $code);
        $this->assertSame('drain_executable_unreadable', $report['check']);
        $this->assertFalse($report['retrySafe']);
        $this->assertStringNotContainsString('synthetic-private-output', json_encode($report));
        [$report] = \pr13LaunchResult(json_encode(array_replace($stop, ['check' => '/private/path'])), 1, $this->manifest(), 'pin', []);
        $this->assertSame('child_result_labels_invalid', $report['check']);
        $stop['check'] = 'cli_release_opcode_cache_must_be_off';
        $stop['actual'] = ['opcacheLoaded' => false, 'fileCacheConfigured' => false, 'private' => 'synthetic-private-output'];
        [$report] = \pr13LaunchResult(json_encode($stop), 1, $this->manifest(), 'pin', []);
        $this->assertSame(['opcacheLoaded' => false, 'fileCacheConfigured' => false], $report['runtime']);
        $this->assertStringNotContainsString('synthetic-private-output', json_encode($report));
    }

    public function test_pending_requires_admission_and_readiness_receipts_and_exit_75(): void
    {
        $pending = ['result' => 'PR13_ACTIVATION_VERIFICATION_PENDING', 'phase' => 'https', 'check' => 'https_transport_verification_pending',
            'category' => 'operation_failure', 'maintenanceMayBeActive' => true];
        $receipts = ['readiness.complete.json' => ['manifest' => 'pin'], 'admission.started.json' => ['manifest' => 'pin']];
        [$report, $code] = \pr13LaunchResult(json_encode($pending), 75, $this->manifest(), 'pin', $receipts);
        $this->assertSame(75, $code);
        $this->assertSame('PR13_ACTIVATION_VERIFICATION_PENDING', $report['result']);
        foreach ([[], ['readiness.complete.json' => ['manifest' => 'pin']], ['admission.started.json' => ['manifest' => 'pin']]] as $incomplete) {
            [$report, $code] = \pr13LaunchResult(json_encode($pending), 75, $this->manifest(), 'pin', $incomplete);
            $this->assertSame(1, $code);
        }
    }

    public function test_real_bash_supervisor_reports_silent_child_and_refuses_replay(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Real Bash supervision runs on Linux CI; Windows runs the pure result tests.');
        }
        $root = sys_get_temp_dir().'/np-launch-'.bin2hex(random_bytes(8));
        $bundle = $root.'/bundle';
        mkdir($bundle, 0700, true);
        try {
            foreach (['pr13-launch.sh', 'pr13-launch-result.php'] as $name) {
                copy(__DIR__.'/../../scripts/release/'.$name, $bundle.'/'.$name);
                chmod($bundle.'/'.$name, 0600);
            }
            $manifest = $this->manifest() + ['toolDirectory' => $bundle, 'stateDirectory' => $root.'/pipeline-pr13-activation-r4',
                'tools' => ['pr13-launch-result.php' => hash_file('sha256', $bundle.'/pr13-launch-result.php')]];
            $text = json_encode($manifest);
            $file = $bundle.'/pr13-activation-72h-v4.json';
            file_put_contents($file, $text);
            chmod($file, 0600);
            $child = $bundle.'/child.php';
            file_put_contents($child, '<?php file_put_contents(__DIR__."/child-executions", "1", FILE_APPEND); exit(0);');
            $run = static function () use ($bundle, $child, $file, $text): array {
                $p = proc_open(['bash', '-c', 'umask 077; cd "$1"; source "$2"; pr13_supervise "$3" "$4" "$5" "$6"', 'test',
                    $bundle, $bundle.'/pr13-launch.sh', PHP_BINARY, $child, $file, hash('sha256', $text)],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);

                return [proc_close($p), $out, $err];
            };
            [$exit, $out, $err] = $run();
            $this->assertSame(1, $exit);
            $this->assertSame('', $err);
            $lines = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($out)));
            $this->assertSame('PR13_ACTIVATION_LAUNCH_STARTED', $lines[0]['result']);
            $this->assertSame('child_result_missing', $lines[1]['check']);
            $original = file_get_contents($bundle.'/launch.started.json');
            [$exit, $out] = $run();
            $this->assertSame(1, $exit);
            $this->assertSame('previous_launch_preserve', json_decode($out, true)['check']);
            $this->assertSame('1', file_get_contents($bundle.'/child-executions'));
            $this->assertSame($original, file_get_contents($bundle.'/launch.started.json'));
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }
}
