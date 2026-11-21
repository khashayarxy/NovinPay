<?php

declare(strict_types=1);

namespace NovinPay\Release;

/** Names, not counts: usable without booting Laravel or connecting to a database. */
function migrationInventory(string $directory): array
{
    if (! is_dir($directory)) {
        throw new \RuntimeException('migration_directory_missing');
    }
    $names = array_map(static fn ($path) => basename($path, '.php'), glob($directory.'/*.php'));
    sort($names, SORT_STRING);

    return $names;
}

function verifyMigrationInventory(array $actual, array $expected): void
{
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);
    if ($expected === [] || $actual !== $expected || count(array_unique($actual)) !== count($actual)) {
        throw new \RuntimeException('migration_inventory_mismatch');
    }
}

/** Reviewed SQL contracts are versioned by the migration introducing each trigger. */
function expectedTriggerContracts(array $migrations): array
{
    $contracts = json_decode(file_get_contents(__DIR__.'/trigger-contracts.json'), true, 32, JSON_THROW_ON_ERROR);
    $expected = [];
    foreach ($contracts as $name => $contract) {
        $migration = $contract['migration'];
        unset($contract['migration']);
        if (in_array($migration, $migrations, true)) {
            $expected[$name] = $contract;
        }
    }
    ksort($expected);

    return $expected;
}
