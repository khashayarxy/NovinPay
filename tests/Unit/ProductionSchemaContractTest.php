<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProductionSchemaContractTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-schema-contract.php';
    }

    private function rows(bool $upgraded = false): array
    {
        $rows = [];
        foreach (pr13ExpectedTriggerContracts($upgraded) as $name => $contract) {
            $rows[] = ['name' => $name] + $contract;
        }

        return $rows;
    }

    public function test_all_five_original_contracts_are_retained_in_the_thirteen_trigger_candidate(): void
    {
        $before = pr13VerifyTriggerContracts($this->rows());
        $after = pr13VerifyTriggerContracts(array_reverse($this->rows(true)), true);
        $this->assertCount(5, $before);
        $this->assertCount(13, $after);
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertSame(hash('sha256', "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial history is immutable'"), $before['financial_entries_no_DELETE']['bodySha256']);
    }

    public function test_body_table_event_timing_and_definer_changes_are_all_rejected(): void
    {
        foreach (['bodySha256', 'tableName', 'eventName', 'timing', 'definer'] as $key) {
            $rows = $this->rows();
            $rows[0][$key] = 'unexpected';
            try {
                pr13VerifyTriggerContracts($rows);
                $this->fail('Changed trigger '.$key.' was accepted');
            } catch (RuntimeException $error) {
                $this->assertSame('trigger_contract_mismatch', $error->getMessage());
            }
        }
    }

    public function test_missing_duplicate_and_unexpected_triggers_are_rejected(): void
    {
        $original = $this->rows();
        $extra = $original;
        $extra[0]['name'] = 'unexpected_trigger';
        foreach ([array_slice($original, 1), array_merge($original, [$original[0]]), $extra] as $rows) {
            try {
                pr13VerifyTriggerContracts($rows);
                $this->fail('Invalid inventory accepted');
            } catch (RuntimeException $error) {
                $this->assertStringStartsWith('trigger_', $error->getMessage());
            }
        }
    }

    public function test_partial_ddl_or_post_admission_activity_never_authorizes_automatic_code_rollback(): void
    {
        $this->assertTrue(pr13PreAdmissionRecoveryAllowed(false, true, true, true));
        foreach ([[true, true, true, true], [false, false, true, true], [false, true, false, true], [false, true, true, false]] as $state) {
            $this->assertFalse(pr13PreAdmissionRecoveryAllowed(...$state));
        }
    }
}
