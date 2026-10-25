<?php

namespace Tests\Unit;

use App\Support\MaintenanceStateConflict;
use App\Support\MaintenanceStateFault;
use App\Support\SiteMaintenanceState;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SiteMaintenanceStateTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/novinpay-state-test-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->assertStringStartsWith(sys_get_temp_dir().'/novinpay-state-test-', $this->directory);
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public static function malformedTimestamps(): array
    {
        return array_map(fn ($value) => [$value], [null, false, 123, [], '', "2026-10-25\0",
            "2026-10-25T12:00:00+00:00\0", 'not a date', '2026-02-30T12:00:00+00:00',
            '2025-02-29T12:00:00+00:00', '2026-13-01T12:00:00+00:00',
            '2026-10-25T24:00:00+00:00', '2026-10-25T12:60:00+00:00',
            '2026-10-25T12:00:60+00:00', '2026-10-25T12:00:00+99:00',
            '2026-10-25T12:00:00+00:99', '2026-10-25T12:00:00+00:00extra']);
    }

    #[DataProvider('malformedTimestamps')]
    public function test_malformed_dates_fail_closed_and_emergency_disable_can_recover(mixed $timestamp): void
    {
        $state = new SiteMaintenanceState($this->directory);
        $original = $state->change(SiteMaintenanceState::INITIAL, true, 7, '<html>snapshot</html>');
        $original['updated_at'] = $timestamp;
        file_put_contents($this->directory.'/state.json', json_encode($original, JSON_THROW_ON_ERROR));
        try {
            $state->read();
            $this->fail('Malformed timestamp must not be accepted');
        } catch (MaintenanceStateFault $fault) {
            $this->assertSame('state_invalid', $fault->getMessage());
        }
        $recovered = $state->change('', false, null, emergency: true);
        $this->assertFalse($state->read()['enabled']);
        $this->assertSame('unreadable', $recovered['previous_version']);
    }

    public function test_strict_dates_accept_real_leap_days_and_timezone_offsets(): void
    {
        $state = new SiteMaintenanceState($this->directory);
        $original = $state->change(SiteMaintenanceState::INITIAL, false, null);
        foreach (['2024-02-29T23:59:59+00:00', '2026-10-25T12:00:00+03:30', '2026-10-25T12:00:00-04:00'] as $timestamp) {
            $original['updated_at'] = $timestamp;
            file_put_contents($this->directory.'/state.json', json_encode($original, JSON_THROW_ON_ERROR));
            $this->assertSame($timestamp, $state->read()['updated_at']);
        }
    }

    public static function malformedTargets(): array
    {
        return array_map(fn ($value) => [$value], ['//example.invalid/admin/login', '///admin/login',
            'https://example.invalid/admin/login', 'http:/admin/login', 'http:admin/login',
            'example.invalid:443/admin/login', 'admin/login', '', '*', '/admin\\login',
            '/admin/../products', '/admin/./login', '/admin//login', '/admin%2flogin',
            '/admin/%2e%2e/products', '/admin/login#fragment', "/admin/login\0", "/admin/lo\rgin",
            "/admin/login?x=\n", '/admin/login?x=raw space', '/administrator', '/ADMIN/login']);
    }

    #[DataProvider('malformedTargets')]
    public function test_only_unambiguous_raw_origin_form_admin_paths_enter_the_corridor(string $target): void
    {
        $this->assertFalse(SiteMaintenanceState::adminCorridor($target));
    }

    public function test_intended_admin_paths_and_queries_remain_available(): void
    {
        foreach (['/admin', '/admin/', '/admin/login', '/admin/maintenance/preview/page', '/admin/login?next=https%3A%2F%2Fexample.invalid'] as $target) {
            $this->assertTrue(SiteMaintenanceState::adminCorridor($target));
        }
    }

    public function test_status_reads_are_side_effect_free_and_stale_writes_conflict(): void
    {
        $state = new SiteMaintenanceState($this->directory);
        $this->assertFalse($state->read()['enabled']);
        $this->assertDirectoryDoesNotExist($this->directory);
        $first = $state->change(SiteMaintenanceState::INITIAL, true, 7, '<html>approved test snapshot</html>');
        $this->assertTrue($state->read()['enabled']);
        $this->expectException(MaintenanceStateConflict::class);
        $state->change(SiteMaintenanceState::INITIAL, false, 7);
    }

    public function test_two_real_processes_cannot_overwrite_the_same_observed_version(): void
    {
        $state = new SiteMaintenanceState($this->directory);
        $initial = $state->change(SiteMaintenanceState::INITIAL, false, null);
        $library = base_path('app/Support/SiteMaintenanceState.php');
        $barrier = $this->directory.'/start-test';
        $code = 'require '.var_export($library, true).';$s=new App\\Support\\SiteMaintenanceState('.var_export($this->directory, true).');'
            .'$deadline=microtime(true)+8;while(!is_file('.var_export($barrier, true).')){if(microtime(true)>$deadline)exit(8);usleep(1000);}'
            .'try{$s->change('.var_export($initial['version'], true).',true,7,"<html>snapshot</html>");exit(0);}catch(App\\Support\\MaintenanceStateConflict){exit(9);}';
        $processes = [new Process([PHP_BINARY, '-r', $code]), new Process([PHP_BINARY, '-r', $code])];
        try {
            foreach ($processes as $process) {
                $process->start();
            }
            file_put_contents($barrier, 'start');
            $codes = array_map(fn ($process) => $process->wait(), $processes);
            sort($codes);
            $this->assertSame([0, 9], $codes);
            $this->assertTrue($state->read()['enabled']);
            $this->assertNotSame($initial['version'], $state->read()['version']);
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
        }
    }

    public function test_interrupted_publication_preserves_the_previous_atomic_state(): void
    {
        $state = new SiteMaintenanceState($this->directory);
        $initial = $state->change(SiteMaintenanceState::INITIAL, false, null);
        $failing = new class($this->directory) extends SiteMaintenanceState
        {
            protected function publish(array $state): void
            {
                throw new MaintenanceStateFault('injected_pre_rename_failure');
            }
        };
        try {
            $failing->change($initial['version'], true, 7, '<html>snapshot</html>');
            $this->fail('Expected interrupted write');
        } catch (MaintenanceStateFault $error) {
            $this->assertFalse($error->published);
        }
        $this->assertSame($initial, $state->read());
        $events = array_map(fn ($line) => json_decode($line, true), file($this->directory.'/audit.jsonl', FILE_IGNORE_NEW_LINES));
        $last = end($events);
        $this->assertSame('prepared', $last['phase']);
        $this->assertNotSame($last['version'], $state->read()['version']);
    }

    public function test_failure_to_finish_the_audit_is_explicit_after_publication(): void
    {
        $failing = new class($this->directory) extends SiteMaintenanceState
        {
            protected function journal(array $event): void
            {
                if ($event['phase'] === 'applied') {
                    throw new MaintenanceStateFault('injected_audit_failure');
                }
                parent::journal($event);
            }
        };
        try {
            $failing->change(SiteMaintenanceState::INITIAL, true, 7, '<html>snapshot</html>');
            $this->fail('Expected explicit audit-completion fault');
        } catch (MaintenanceStateFault $error) {
            $this->assertTrue($error->published);
        }
        $state = $failing->read();
        $event = json_decode(trim(file_get_contents($this->directory.'/audit.jsonl')), true);
        $this->assertTrue($state['enabled']);
        $this->assertSame($state['version'], $event['version']);
        $this->assertSame('prepared', $event['phase']);
    }

    public function test_missing_state_after_a_failed_initial_write_is_not_silently_disabled(): void
    {
        $failing = new class($this->directory) extends SiteMaintenanceState
        {
            protected function journal(array $event): void
            {
                throw new MaintenanceStateFault('injected_disk_failure');
            }
        };
        try {
            $failing->change(SiteMaintenanceState::INITIAL, true, 7, '<html>snapshot</html>');
        } catch (MaintenanceStateFault) {
        }
        $this->expectException(MaintenanceStateFault::class);
        $failing->read();
    }

    public function test_an_interrupted_audit_tail_is_not_silently_accepted(): void
    {
        $state = new SiteMaintenanceState($this->directory);
        $initial = $state->change(SiteMaintenanceState::INITIAL, false, null);
        file_put_contents($this->directory.'/audit.jsonl', '{"phase":', FILE_APPEND);
        try {
            $state->change($initial['version'], true, 7, '<html>snapshot</html>');
            $this->fail('Incomplete audit must block a new transition');
        } catch (MaintenanceStateFault $exception) {
            $this->assertSame('audit_incomplete', $exception->getMessage());
        }
        $this->assertSame($initial, $state->read());
    }

    public function test_emergency_cli_recovers_corrupt_state_without_composer_env_or_database(): void
    {
        $files = new Filesystem;
        foreach (['scripts', 'app/Support', 'storage/framework/site-maintenance'] as $directory) {
            $files->makeDirectory($this->directory.'/'.$directory, 0700, true);
        }
        foreach (['scripts/maintenance-control.php', 'app/Support/SiteMaintenanceState.php', 'app/Support/MaintenanceStateFault.php', 'app/Support/MaintenanceStateConflict.php'] as $file) {
            copy(base_path($file), $this->directory.'/'.$file);
        }
        file_put_contents($this->directory.'/storage/framework/site-maintenance/state.json', '{corrupt');
        $down = $this->directory.'/storage/framework/down';
        file_put_contents($down, 'separate deployment marker');
        $script = $this->directory.'/scripts/maintenance-control.php';
        $denied = new Process([PHP_BINARY, $script, 'disable']);
        $this->assertSame(1, $denied->run());
        $this->assertSame('{corrupt', file_get_contents(dirname($down).'/site-maintenance/state.json'));
        $recovery = new Process([PHP_BINARY, $script, 'disable', '--confirm-disable']);
        $recovery->mustRun();
        $this->assertStringContainsString('ADMIN MAINTENANCE: DISABLED', $recovery->getOutput());
        $this->assertStringContainsString('DEPLOYMENT MAINTENANCE REMAINS ACTIVE', $recovery->getOutput());
        $this->assertSame('separate deployment marker', file_get_contents($down));
        $this->assertFalse((new SiteMaintenanceState(dirname($down).'/site-maintenance'))->read()['enabled']);
        $malformed = json_decode(file_get_contents(dirname($down).'/site-maintenance/state.json'), true);
        $malformed['updated_at'] = "2026-10-25T12:00:00+00:00\0";
        file_put_contents(dirname($down).'/site-maintenance/state.json', json_encode($malformed, JSON_THROW_ON_ERROR));
        $recovery->mustRun();
        $this->assertStringContainsString('ADMIN MAINTENANCE: DISABLED', $recovery->getOutput());
        $this->assertSame('separate deployment marker', file_get_contents($down));
        $this->assertFileDoesNotExist($this->directory.'/.env');
        $this->assertDirectoryDoesNotExist($this->directory.'/vendor');
    }
}
