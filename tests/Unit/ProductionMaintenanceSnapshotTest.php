<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

class ProductionMaintenanceSnapshotTest extends TestCase
{
    private function fixture(): array
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-maintenance-snapshot.php';
        $audit = '';
        $states = [];
        $audits = [];
        $previous = str_repeat('0', 64);
        foreach ([false, true, false] as $i => $enabled) {
            $version = hash('sha256', (string) $i);
            $at = '2020-01-01T00:00:0'.$i.'+00:00';
            $state = ['format' => 1, 'enabled' => $enabled, 'version' => $version, 'previous_version' => $previous, 'updated_at' => $at, 'actor_id' => 1, 'html' => null, 'html_sha256' => null];
            $event = ['action' => $enabled ? 'maintenance.enabled' : 'maintenance.disabled', 'actor_id' => 1, 'target' => 'public_website', 'at' => $at, 'version' => $version, 'previous_version' => $previous];
            foreach (['prepared', 'applied'] as $phase) {
                $audit .= json_encode($event + ['phase' => $phase])."\n";
            }
            $states[] = json_encode($state);
            $audits[] = $audit;
            $previous = $version;
        }

        return [$states, $audits, ['state.json' => hash('sha256', $states[0]), 'audit.jsonl' => hash('sha256', $audits[0])]];
    }

    public function test_stale_pins_are_identified_only_with_an_intact_complete_history_extension(): void
    {
        [$states, $audits, $anchor] = $this->fixture();
        $evidence = pr13MaintenanceSnapshotEvidence($states[2], $audits[2], $anchor);
        $this->assertTrue($evidence['requiresNewManifest']);
        $this->assertTrue($evidence['historyPrefixPreserved']);
        $this->assertSame(1, $evidence['historicalTransitions']);
        $this->assertCount(2, $evidence['appendedTransitions']);
        $this->assertFalse($evidence['retryAuthorized']);
        $this->assertFalse(pr13MaintenanceSnapshotEvidence($states[0], $audits[0], $anchor)['requiresNewManifest']);
    }

    public function test_rewritten_history_is_not_accepted_as_a_pin_refresh(): void
    {
        [$states, $audits, $anchor] = $this->fixture();
        $this->expectException(RuntimeException::class);
        pr13MaintenanceSnapshotEvidence($states[2], str_replace('public_website', 'different_target', $audits[2]), $anchor);
    }

    public function test_partial_audit_or_mismatched_state_is_never_accepted(): void
    {
        [$states, $audits, $anchor] = $this->fixture();
        foreach ([[$states[2], rtrim($audits[2])], [$states[0], $audits[2]], [$states[1], $audits[1]]] as [$state, $audit]) {
            try {
                pr13MaintenanceSnapshotEvidence($state, $audit, $anchor);
                $this->fail('Unsafe snapshot accepted');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_only_the_verified_pre_backup_attempt_can_be_reconciled_without_reusing_its_journal(): void
    {
        $this->fixture();
        $pin = hash('sha256', 'original reviewed manifest');
        $entries = ['pipeline.lock', 'restore_defaults.complete.json', 'restore_defaults.started.json'];
        $start = ['manifest' => $pin, 'phase' => 'restore_defaults'];
        $complete = $start + ['result' => ['charset_name' => 'latin1', 'collation_name' => 'latin1_swedish_ci']];
        $this->assertTrue(pr13PriorAttemptIsPreBackup($entries, $start, $complete, $pin, false, false));
        $this->assertFalse(pr13PriorAttemptIsPreBackup([...$entries, 'backup.started.json'], $start, $complete, $pin, false, false));
        $this->assertFalse(pr13PriorAttemptIsPreBackup($entries, $start, $complete, $pin, true, false));
        $this->assertFalse(pr13PriorAttemptIsPreBackup($entries, $start, $complete, $pin, false, true));
        $this->assertFalse(pr13PriorAttemptIsPreBackup($entries, $start, $complete, hash('sha256', 'changed manifest'), false, false));
        $complete['result']['charset_name'] = 'unexpected';
        $this->assertFalse(pr13PriorAttemptIsPreBackup($entries, $start, $complete, $pin, false, false));
    }

    public function test_readonly_diagnostic_rejects_an_unpinned_invocation_before_host_access(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=', dirname(__DIR__, 2).'/scripts/release/pr13-stop-diagnostic.php']);
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('PR13_READONLY_STOP_RECONCILIATION_STOP', $result['result']);
        $this->assertSame('invocation', $result['check']);
        $this->assertFalse($result['recoveryRetried']);
    }
}
