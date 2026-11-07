<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionPrivilegeDiagnosticTest extends TestCase
{
    public function test_escaped_literal_schema_is_distinguished_from_missing_privileges_without_granting_readiness(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-privilege-diagnostic.php';
        $privileges = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'INDEX', 'TRIGGER'];
        $rows = array_map(fn ($p) => ['GRANTEE' => "'novinpay_mig1'@'localhost'", 'TABLE_SCHEMA' => 'novinpay\\_live1', 'PRIVILEGE_TYPE' => $p], $privileges);
        $report = pr13PrivilegeEvidence($rows, 'novinpay_mig1@localhost', 'novinpay_live1', []);
        $this->assertSame('escaped_schema_metadata', $report['finding']);
        $this->assertSame([], $report['missingCandidateLiteralScopePrivileges']);
        $this->assertSame($privileges, $report['missingOriginalPrivileges']);
        $this->assertArrayNotHasKey('releaseReady', $report);
    }

    public function test_unrelated_accounts_and_wildcard_scopes_do_not_provide_candidate_privileges(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-privilege-diagnostic.php';
        $rows = [['GRANTEE' => "'other'@'localhost'", 'TABLE_SCHEMA' => 'novinpay_live1', 'PRIVILEGE_TYPE' => 'ALTER'],
            ['GRANTEE' => "'novinpay_mig1'@'localhost'", 'TABLE_SCHEMA' => 'other_private_schema', 'PRIVILEGE_TYPE' => 'ALTER'],
            ['GRANTEE' => "'novinpay_mig1'@'localhost'", 'TABLE_SCHEMA' => 'novinpay_%', 'PRIVILEGE_TYPE' => 'ALTER']];
        $report = pr13PrivilegeEvidence($rows, 'novinpay_mig1@localhost', 'novinpay_live1', []);
        $this->assertSame([], $report['candidateLiteralScopePrivileges']);
        $this->assertStringNotContainsString('other_private_schema', json_encode($report));
        $this->assertSame('requires_review', $report['finding']);
    }
}
