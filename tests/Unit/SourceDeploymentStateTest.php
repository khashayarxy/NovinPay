<?php

namespace Tests\Unit;

use const NovinPay\Release\SOURCE_CHECKPOINTS;

use NovinPay\Release\GateFailure;
use PHPUnit\Framework\TestCase;

use function NovinPay\Release\publishSourceCompletion;
use function NovinPay\Release\recordSourceCheckpoint;
use function NovinPay\Release\sourceDeploymentStatus;
use function NovinPay\Release\sourceHttpChecks;
use function NovinPay\Release\writeNew;

require_once __DIR__.'/../../scripts/release/recovery-contract.php';
require_once __DIR__.'/../../scripts/release/source-deployment-state.php';

class SourceDeploymentStateTest extends TestCase
{
    public function test_incident_manifest_uses_the_actual_expected_http_status(): void
    {
        $input = ['httpChecks' => ['/' => 200, '/login' => ['status' => 200, 'contains' => ['form']], '/payments/zibal/callback' => 404]];
        $checks = sourceHttpChecks($input);
        $this->assertSame(['status' => 200], $checks['/']);
        $this->assertSame(['status' => 404], $checks['/payments/zibal/callback']);
        $this->assertSame(['status' => 200, 'contains' => ['form']], $checks['/login']);
        $this->assertSame(200, $input['httpChecks']['/']);
    }

    public function test_invalid_http_expectation_fails_before_execution(): void
    {
        $this->expectExceptionMessage('http_probe_expectation');
        sourceHttpChecks(['httpChecks' => ['/' => ['contains' => ['form']], '/login' => 200]]);
    }

    public function test_durable_state_separates_interruption_from_acceptance_and_receipt_publication(): void
    {
        $manifest = ['_sha256' => str_repeat('a', 64), 'baseline' => str_repeat('b', 40), 'target' => str_repeat('c', 40),
            'successResult' => 'APPLICATION_RELEASE_PASS', 'reviewedMigrations' => ['migration.php' => str_repeat('d', 64)]];
        $temp = sys_get_temp_dir().'/novinpay-source-states-'.bin2hex(random_bytes(8));
        mkdir($temp, 0700);
        try {
            foreach (SOURCE_CHECKPOINTS as $phase) {
                recordSourceCheckpoint($temp, $manifest, $phase);
                if ($phase === 'maintenance_entered') {
                    writeNew($temp.'/migration-started.json', ['manifestSha256' => $manifest['_sha256']]);
                    $state = sourceDeploymentStatus($temp, $manifest, null);
                    $this->assertTrue($state['migrationStarted']);
                    $this->assertFalse($state['migrationCompleted']);
                    $this->assertFalse($state['retrySafe']);
                }
                if ($phase === 'schema_verified') {
                    writeNew($temp.'/migration-complete.json', ['manifestSha256' => $manifest['_sha256']]);
                }
                if (in_array($phase, ['traffic_reopen_intent', 'traffic_reopened'], true)) {
                    $state = sourceDeploymentStatus($temp, $manifest, ['exitCode' => 1]);
                    $this->assertSame('SOURCE_RECONCILIATION_REQUIRED', $state['result']);
                    $this->assertSame($phase, $state['lastCheckpoint']);
                    try {
                        publishSourceCompletion($temp, $manifest);
                        $this->fail('A traffic checkpoint must not authorize a successful receipt.');
                    } catch (GateFailure $failure) {
                        $this->assertSame('source_acceptance_unproven', $failure->check);
                    }
                }
            }
            $this->assertSame('ACCEPTED_RECEIPT_MISSING', sourceDeploymentStatus($temp, $manifest, ['exitCode' => 1])['result']);
            $receipt = publishSourceCompletion($temp, $manifest);
            $this->assertSame($receipt, publishSourceCompletion($temp, $manifest));
            $observed = sourceDeploymentStatus($temp, $manifest, ['exitCode' => 1]);
            $this->assertSame('APPLICATION_RECEIPT_VERIFIED', $observed['result']);
            $this->assertSame(1, $observed['workerExitCode']);
        } finally {
            foreach (glob($temp.'/*.json') as $path) {
                unlink($path);
            }
            rmdir($temp);
        }
    }
}
