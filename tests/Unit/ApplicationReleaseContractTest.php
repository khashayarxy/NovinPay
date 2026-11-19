<?php

namespace Tests\Unit;

use NovinPay\Release\GateFailure;
use PHPUnit\Framework\TestCase;

use function NovinPay\Release\applicationReleasePlan;
use function NovinPay\Release\preservedConfigurationDigest;
use function NovinPay\Release\reviewedMigrationReceipt;

require_once __DIR__.'/../../scripts/release/recovery-contract.php';

class ApplicationReleaseContractTest extends TestCase
{
    public function test_release_cli_overrides_invalid_host_session_divisor_before_php_startup(): void
    {
        $script = file_get_contents(__DIR__.'/../../scripts/release/activate-source.sh');
        $this->assertSame(1, preg_match('/^p\(\) \{[^\r\n]+/m', $script, $function));
        preg_match_all('/-d ([^ ]+)/', $function[0], $settings);
        $ini = tempnam(sys_get_temp_dir(), 'np-session-ini-');
        file_put_contents($ini, "session.gc_divisor=0\n");
        try {
            $args = [PHP_BINARY, '-c', $ini];
            foreach ($settings[1] as $setting) {
                array_push($args, '-d', $setting);
            }
            array_push($args, '-r', 'echo json_encode(["divisor" => (int) ini_get("session.gc_divisor"), "cliCache" => (bool) ini_get("opcache.enable_cli")]);');
            $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process));
            $this->assertSame('', $errors);
            $this->assertSame(['divisor' => 100, 'cliCache' => false], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            unlink($ini);
        }
    }

    public function test_migration_receipts_use_the_shared_durable_api_and_cannot_be_replayed(): void
    {
        $dir = sys_get_temp_dir().'/np-migration-journal-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $manifest = ['_sha256' => str_repeat('a', 64), 'candidateMigrations' => ['synthetic']];
        try {
            reviewedMigrationReceipt($dir, 'started', $manifest);
            reviewedMigrationReceipt($dir, 'complete', $manifest);
            $receipt = json_decode(file_get_contents($dir.'/migration-complete.json'), true, 8, JSON_THROW_ON_ERROR);
            $this->assertSame($manifest['_sha256'], $receipt['manifestSha256']);
            $this->assertSame(['synthetic'], $receipt['expectedMigrations']);
            $this->assertFalse($receipt['retrySafe']);
            $this->expectExceptionMessage('receipt_exists');
            reviewedMigrationReceipt($dir, 'started', $manifest);
        } finally {
            foreach (glob($dir.'/*.json') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

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
        $after['operations'] = ['scheduled_payments' => false];
        $after['payments']['zibal']['review_after_minutes'] = 30;
        $after['payments']['zibal']['reconciliation_limit'] = 8;
        $this->assertSame(preservedConfigurationDigest($baseline), preservedConfigurationDigest($after));
        foreach (['app' => ['key' => 'changed'], 'customer' => ['sms_provider' => 'unsupported-value'], 'database' => ['default' => 'wrong']] as $section => $value) {
            $changed = $after;
            $changed[$section] = $value;
            $this->assertNotSame(preservedConfigurationDigest($baseline), preservedConfigurationDigest($changed));
        }
    }

    public function test_zibal_cart_release_accepts_exact_new_defaults_without_relaxing_protected_configuration(): void
    {
        $before = ['payments' => ['zibal' => ['min_rial' => 1001, 'merchant' => 'synthetic', 'enabled' => false]]];
        $after = $before;
        $after['payments']['zibal']['min_rial'] = 1000;
        $after['payments']['connectivity'] = [
            'ranges_path' => '/home/novinpay/novinpay/resources/payments/country-ranges.json',
            'trusted_proxies' => [''],
        ];
        $this->assertNotSame(preservedConfigurationDigest($before), preservedConfigurationDigest($after));
        $this->assertSame(preservedConfigurationDigest($before, true), preservedConfigurationDigest($after, true));
        $changed = $after;
        $changed['payments']['connectivity']['trusted_proxies'] = ['10.0.0.1'];
        $this->assertNotSame(preservedConfigurationDigest($before, true), preservedConfigurationDigest($changed, true));
        $changed = $after;
        $changed['payments']['zibal']['merchant'] = 'different-synthetic';
        $this->assertNotSame(preservedConfigurationDigest($before, true), preservedConfigurationDigest($changed, true));
    }

    public function test_retired_sms_settings_may_disappear_but_active_ippanel_and_identity_remain_bound(): void
    {
        $current = ['customer' => ['sms_provider' => 'ippanel', 'test_reset_on_logout' => true,
            'ippanel' => ['enabled' => true, 'api_key' => 'synthetic-key', 'pattern_code' => 'syntheticpattern']]];
        $old = $current;
        $old['customer'] += ['sms_enabled' => false, 'sms_api_key' => 'retired-synthetic-key', 'otp_template' => 1,
            'otp_parameter' => 'CODE', 'approved_template_ids' => [1], 'notifications_enabled' => false,
            'notification_parameter' => 'URL', 'notification_templates' => []];
        $this->assertSame(preservedConfigurationDigest($old), preservedConfigurationDigest($current));
        foreach (['enabled' => false, 'api_key' => 'changed', 'pattern_code' => 'changed'] as $key => $value) {
            $changed = $current;
            $changed['customer']['ippanel'][$key] = $value;
            $this->assertNotSame(preservedConfigurationDigest($current), preservedConfigurationDigest($changed));
        }
        $current['customer']['test_reset_on_logout'] = false;
        $this->assertNotSame(preservedConfigurationDigest($old), preservedConfigurationDigest($current));
    }
}
