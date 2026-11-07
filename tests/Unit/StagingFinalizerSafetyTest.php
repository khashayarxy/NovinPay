<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class StagingFinalizerSafetyTest extends TestCase
{
    public function test_finalizer_does_not_replay_installation_or_migrations(): void
    {
        $code = file_get_contents(__DIR__.'/../../scripts/staging/finalize-v1.php');
        $this->assertDoesNotMatchRegularExpression('/->call\(\s*[\x27\x22]migrate/', $code);
        $this->assertStringNotContainsString('install_reviewed_files', $code);
        $this->assertStringNotContainsString('->initialize()', $code);
        $this->assertStringContainsString('backup_authenticated_decryption', $code);
        $this->assertStringContainsString('np_stage_trigger_inventory($observer', $code);
        $this->assertLessThan(strpos($code, "'traffic_admission'"), strpos($code, "'exact_migrations'"));
        $this->assertLessThan(strpos($code, "'ISOLATED_STAGING_FINALIZATION_PASS'"), strpos($code, "'auth_isolation'"));
    }
}
