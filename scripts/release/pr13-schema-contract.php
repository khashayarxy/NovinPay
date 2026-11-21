<?php

declare(strict_types=1);

// Pure contracts for the migration-aware release. No entry point or DB writes.
function pr13ExpectedTriggerContracts(bool $upgraded = false): array
{
    require_once __DIR__.'/schema-contract.php';
    $contracts = json_decode(file_get_contents(__DIR__.'/trigger-contracts.json'), true, 32, JSON_THROW_ON_ERROR);
    $migrations = $upgraded ? array_column($contracts, 'migration') : [
        '2026_10_21_000003_create_financial_tables', '2026_10_21_000004_create_article_tables',
    ];

    return \NovinPay\Release\expectedTriggerContracts($migrations);
}

function pr13VerifyTriggerContracts(array $rows, bool $upgraded = false): array
{
    $expected = pr13ExpectedTriggerContracts($upgraded);
    $actual = [];
    foreach ($rows as $row) {
        $name = $row['name'] ?? '';
        if (isset($actual[$name]) || ! isset($expected[$name])) {
            throw new RuntimeException('trigger_identity_mismatch');
        }
        foreach ($expected[$name] as $key => $value) {
            if (($row[$key] ?? null) !== $value) {
                throw new RuntimeException('trigger_contract_mismatch');
            }
        }
        $actual[$name] = $expected[$name];
    }
    ksort($actual);
    if ($actual !== $expected) {
        throw new RuntimeException('trigger_inventory_incomplete');
    }

    return $actual;
}

// Code-only recovery is eligible only before traffic/new-format writes, after
// rehearsal proves old-column values and all original triggers are unchanged.
// It retains the upgraded schema and patched dependencies; never calls down().
function pr13PreAdmissionRecoveryAllowed(bool $trafficAdmitted, bool $completeUpgrade, bool $oldRowsPreserved, bool $baselineTriggersPreserved): bool
{
    return ! $trafficAdmitted && $completeUpgrade && $oldRowsPreserved && $baselineTriggersPreserved;
}
