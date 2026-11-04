<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../scripts/staging/http-basic.php';
require_once __DIR__.'/../../scripts/staging/trigger-inventory.php';
final class StagingDeploymentContractsTest extends TestCase
{
    public function test_bootstrap_database_credentials_are_not_http_basic_credentials(): void
    {
        $this->expectException(RuntimeException::class);
        np_basic_validate(['database' => 'isolated', 'migrator_user' => 'migrator', 'migrator_password' => 'synthetic', 'admin_password' => 'synthetic'], 'unused', 'stage-review');
    }

    public function test_private_basic_password_must_match_active_account(): void
    {
        $auth = ['username' => 'stage-review', 'password' => 'synthetic-only'];
        $pw = 'stage-review:'.password_hash($auth['password'], PASSWORD_BCRYPT)."\n";
        $this->assertSame($auth, np_basic_validate($auth, $pw, 'stage-review'));
        $this->expectException(RuntimeException::class);
        np_basic_validate(['username' => 'stage-review', 'password' => 'wrong'], $pw, 'stage-review');
    }

    public function test_empty_runtime_visibility_cannot_prove_trigger_absence(): void
    {
        $this->expectException(RuntimeException::class);
        np_verify_trigger_rows([], ['expected' => []]);
    }

    public function test_trigger_definition_and_definer_are_verified(): void
    {
        $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable'";
        $row = ['TRIGGER_NAME' => 'expected', 'EVENT_OBJECT_TABLE' => 'history', 'EVENT_MANIPULATION' => 'UPDATE', 'ACTION_TIMING' => 'BEFORE', 'ACTION_STATEMENT' => $body, 'DEFINER' => 'migrator@localhost'];
        $expected = ['expected' => ['table' => 'history', 'event' => 'UPDATE', 'timing' => 'BEFORE', 'bodySha256' => hash('sha256', $body), 'definer' => 'migrator@localhost']];
        $this->assertSame([$row], np_verify_trigger_rows([$row], $expected));
        $row['DEFINER'] = 'runtime@localhost';
        $this->expectException(RuntimeException::class);
        np_verify_trigger_rows([$row], $expected);
    }
}
