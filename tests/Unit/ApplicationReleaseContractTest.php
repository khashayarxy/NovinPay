<?php

namespace Tests\Unit;

use NovinPay\Release\GateFailure;
use PHPUnit\Framework\TestCase;

use function NovinPay\Release\applicationReleasePlan;
use function NovinPay\Release\preservedConfigurationDigest;

require_once __DIR__.'/../../scripts/release/recovery-contract.php';

class ApplicationReleaseContractTest extends TestCase
{
    private function plan(): array
    {
        $migration = '2026_11_13_000001_add_payment_resolution_metadata';
        $path = 'database/migrations/'.$migration.'.php';

        return ['migrations' => ['2026_11_09_000001_add_customer_password'],
            'reviewedRuntimeChanges' => [$path => str_repeat('a', 64), 'config/operations.php' => str_repeat('b', 64)],
            'reviewedMigrations' => [$path => str_repeat('a', 64)],
            'candidateMigrations' => ['2026_11_09_000001_add_customer_password', $migration]];
    }

    public function test_only_exact_reviewed_additive_inventory_is_accepted(): void
    {
        applicationReleasePlan($this->plan());
        $this->addToAssertionCount(1);
        $plan = $this->plan();
        $plan['candidateMigrations'][] = 'unexpected_migration';
        $this->expectException(GateFailure::class);
        applicationReleasePlan($plan);
    }

    public function test_historical_migration_edits_are_rejected(): void
    {
        $plan = $this->plan();
        $path = 'database/migrations/'.$plan['migrations'][0].'.php';
        $plan['reviewedRuntimeChanges'][$path] = str_repeat('c', 64);
        $this->expectExceptionMessage('application_migration_scope');
        applicationReleasePlan($plan);
    }

    public function test_dependency_paths_cannot_be_smuggled_into_a_runtime_review(): void
    {
        $plan = $this->plan();
        $plan['reviewedRuntimeChanges']['composer.lock'] = str_repeat('d', 64);
        $this->expectExceptionMessage('application_reviewed_path');
        applicationReleasePlan($plan);
    }

    public function test_only_reviewed_new_defaults_may_differ_in_cached_configuration(): void
    {
        $baseline = ['app' => ['key' => 'synthetic-secret'], 'customer' => ['sms_provider' => 'ippanel'],
            'payments' => ['zibal' => ['enabled' => false, 'live_requests' => false]], 'database' => ['default' => 'mariadb']];
        $after = $baseline;
        $after['operations'] = ['scheduled_payments' => false, 'scheduled_notifications' => false];
        $after['payments']['zibal']['review_after_minutes'] = 30;
        $after['payments']['zibal']['reconciliation_limit'] = 8;
        $this->assertSame(preservedConfigurationDigest($baseline), preservedConfigurationDigest($after));
        foreach (['app' => ['key' => 'changed'], 'customer' => ['sms_provider' => 'smsir'], 'database' => ['default' => 'wrong']] as $section => $value) {
            $changed = $after;
            $changed[$section] = $value;
            $this->assertNotSame(preservedConfigurationDigest($baseline), preservedConfigurationDigest($changed));
        }
    }
}
