<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProductionReleaseInventoryTest extends TestCase
{
    private function privilegeRows(string $scope = 'novinpay_live1', string $grantee = "'novinpay_mig1'@'localhost'"): array
    {
        return array_map(fn ($privilege) => ['GRANTEE' => $grantee, 'TABLE_SCHEMA' => $scope, 'PRIVILEGE_TYPE' => $privilege],
            ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'INDEX', 'TRIGGER']);
    }

    public function test_literal_and_mariadb_escaped_schema_metadata_both_satisfy_the_same_privilege_contract(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-preflight.php';
        foreach (['novinpay_live1', 'novinpay\\_live1'] as $scope) {
            $this->assertTrue(pr13MigratorSchemaPrivilegesPresent($this->privilegeRows($scope), 'novinpay_live1', 'novinpay_mig1@localhost'));
        }
    }

    public function test_only_the_exact_quoted_grantee_and_verified_account_are_accepted(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-preflight.php';
        foreach (["'other'@'localhost'", "'novinpay_mig1'@'%'", 'novinpay_mig1@localhost', "'novinpay_mig1'@'127.0.0.1'"] as $grantee) {
            $this->assertFalse(pr13MigratorSchemaPrivilegesPresent($this->privilegeRows(grantee: $grantee), 'novinpay_live1', 'novinpay_mig1@localhost'));
        }
        $this->assertFalse(pr13MigratorSchemaPrivilegesPresent($this->privilegeRows(), 'novinpay_live1', 'other@localhost'));
    }

    public function test_other_database_identities_and_wildcard_or_ambiguous_scopes_cannot_satisfy_the_gate(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-preflight.php';
        foreach (['novinpay_DB', 'novinpay_live2', 'novinpay_%', 'novinpay\\_live%', 'novinpay\\\\_live1', '*'] as $scope) {
            $this->assertFalse(pr13MigratorSchemaPrivilegesPresent($this->privilegeRows($scope), 'novinpay_live1', 'novinpay_mig1@localhost'));
        }
        $this->assertFalse(pr13MigratorSchemaPrivilegesPresent($this->privilegeRows('novinpay_DB'), 'novinpay_DB', 'novinpay_mig1@localhost'));
    }

    public function test_each_required_privilege_remains_mandatory_for_escaped_schema_metadata(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-preflight.php';
        $rows = $this->privilegeRows('novinpay\\_live1');
        foreach (array_keys($rows) as $missing) {
            $subset = $rows;
            unset($subset[$missing]);
            $this->assertFalse(pr13MigratorSchemaPrivilegesPresent($subset, 'novinpay_live1', 'novinpay_mig1@localhost'));
        }
    }

    public function test_production_history_must_match_before_computing_the_eight_pending_migrations(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-preflight.php';
        $baseline = array_map(fn ($i) => 'baseline_'.$i, range(1, 14));
        $added = array_map(fn ($i) => 'candidate_'.$i, range(1, 8));
        $this->assertSame($added, pr13MigrationDelta(array_reverse($baseline), $baseline, array_merge($baseline, $added)));
        $this->expectException(RuntimeException::class);
        pr13MigrationDelta(array_merge(array_slice($baseline, 1), ['unexpected']), $baseline, array_merge($baseline, $added));
    }

    public function test_candidate_cannot_replace_a_historical_migration(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-preflight.php';
        $baseline = array_map(fn ($i) => 'baseline_'.$i, range(1, 14));
        $candidate = array_merge(array_slice($baseline, 1), array_map(fn ($i) => 'candidate_'.$i, range(1, 9)));
        $this->expectException(RuntimeException::class);
        pr13MigrationDelta($baseline, $baseline, $candidate);
    }
}
