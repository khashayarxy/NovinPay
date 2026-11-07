<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function NovinPay\Release\backupPolicy;
use function NovinPay\Release\nextBackupDueUnix;
use function NovinPay\Release\recoveryGate;

require_once __DIR__.'/../../scripts/release/lib.php';

final class BackupPolicyTest extends TestCase
{
    private function proof(): array
    {
        return ['authenticatedDecryption' => true, 'archiveContentsVerified' => true, 'isolatedRestoreVerified' => true,
            'recoveryKeyAvailable' => true, 'sourceDatabase' => 'novinpay_live1', 'snapshotUnix' => 1000];
    }

    public function test_owner_policy_sets_both_interval_and_freshness_to_72_hours(): void
    {
        $this->assertSame(['intervalSeconds' => 259200, 'maxSnapshotAgeSeconds' => 259200], backupPolicy());
        $this->assertSame(260200, nextBackupDueUnix(1000));
    }

    public function test_five_hours_and_exactly_72_hours_remain_eligible_with_verified_recovery(): void
    {
        foreach ([5 * 3600, 259200] as $age) {
            recoveryGate($this->proof(), ['automaticDatabaseRestore' => false], 1000 + $age);
            $this->addToAssertionCount(1);
        }
    }

    public function test_one_second_beyond_72_hours_fails_even_for_approved_presentation_reuse(): void
    {
        $this->expectExceptionMessage('backup_freshness');
        recoveryGate($this->proof(), ['automaticDatabaseRestore' => false, 'risk' => 'presentation', 'reviewedPathsOnly' => true,
            'codeRollbackVerified' => true, 'currentConfigurationPreserved' => true, 'persistentStatePreserved' => true, 'approvedReuse' => true], 260201);
    }

    public function test_age_never_substitutes_for_independent_restore_and_decryption_evidence(): void
    {
        foreach (['authenticatedDecryption', 'archiveContentsVerified', 'isolatedRestoreVerified', 'recoveryKeyAvailable'] as $key) {
            try {
                recoveryGate(array_replace($this->proof(), [$key => false]), ['automaticDatabaseRestore' => false], 1001);
                $this->fail('Missing recovery evidence accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame('recovery_'.$key, $error->getMessage());
            }
        }
    }

    public function test_a_caller_cannot_restore_an_obsolete_or_longer_age_policy(): void
    {
        foreach ([14400, 3600, 604800, null, '259200'] as $override) {
            try {
                recoveryGate($this->proof(), ['automaticDatabaseRestore' => false, 'maxSnapshotAgeSeconds' => $override], 1001);
                $this->fail('Caller changed the owner policy');
            } catch (\RuntimeException $error) {
                $this->assertSame('backup_policy_mismatch', $error->getMessage());
            }
        }
    }

    public function test_future_snapshot_and_automatic_database_restore_still_fail(): void
    {
        foreach ([[999, false], [1000, true]] as [$now, $restore]) {
            try {
                recoveryGate($this->proof(), ['automaticDatabaseRestore' => $restore], $now);
                $this->fail('Unsafe recovery identity accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame('recovery_identity', $error->getMessage());
            }
        }
    }
}
