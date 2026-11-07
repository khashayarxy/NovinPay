<?php

declare(strict_types=1);
// Staging-only metadata observer: no grants, DDL, Laravel boot or application writes.
function np_trigger_need(bool $ok, string $code): void
{
    if (! $ok) {
        throw new RuntimeException($code);
    }
}
function np_verify_trigger_rows(array $rows, array $expected): array
{
    np_trigger_need(count($rows) === count($expected), 'trigger_contract_count');
    $seen = [];
    foreach ($rows as $row) {
        $name = $row['TRIGGER_NAME'] ?? null;
        np_trigger_need(is_string($name) && isset($expected[$name]) && ! isset($seen[$name]), 'trigger_contract_name');
        $e = $expected[$name];
        foreach (['EVENT_OBJECT_TABLE' => 'table', 'EVENT_MANIPULATION' => 'event', 'ACTION_TIMING' => 'timing', 'DEFINER' => 'definer'] as $actual => $field) {
            np_trigger_need(is_string($row[$actual] ?? null) && $row[$actual] === ($e[$field] ?? null), 'trigger_contract_'.$field.'_'.$name);
        }
        np_trigger_need(is_string($row['ACTION_STATEMENT'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $e['bodySha256'] ?? '') === 1
            && hash_equals($e['bodySha256'], hash('sha256', $row['ACTION_STATEMENT'])), 'trigger_contract_body_'.$name);
        $seen[$name] = $row;
    }
    ksort($seen);

    return array_values($seen);
}
function np_stage_trigger_inventory(PDO $observer, array $expected): array
{
    np_trigger_need(! $observer->inTransaction(), 'trigger_observer_transaction');
    $observer->exec('SET TRANSACTION READ ONLY');
    $observer->beginTransaction();
    try {
        $id = $observer->query('SELECT DATABASE() db,CURRENT_USER() account,CURRENT_ROLE() role,VERSION() version')->fetch(PDO::FETCH_ASSOC);
        np_trigger_need($id['db'] === 'novinpay_stg38' && $id['account'] === 'novinpay_s38mig@localhost'
            && ($id['role'] === null || $id['role'] === 'NONE') && str_starts_with($id['version'], '10.6.28'), 'trigger_observer_identity');
        $rows = $observer->query('SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_STATEMENT,DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC);

        return np_verify_trigger_rows($rows, $expected);
    } finally {
        if ($observer->inTransaction()) {
            $observer->rollBack();
        }
    }
}
