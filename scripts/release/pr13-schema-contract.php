<?php

declare(strict_types=1);

// Pure contracts for the migration-aware release. No entry point or DB writes.
function pr13ExpectedTriggerContracts(bool $upgraded = false): array
{
    $contracts = [];
    $add = static function (string $name, string $table, string $event, string $body) use (&$contracts): void {
        $contracts[$name] = ['tableName' => $table, 'eventName' => $event, 'timing' => 'BEFORE',
            'bodySha256' => hash('sha256', $body), 'definer' => 'novinpay_mig1@localhost'];
    };
    foreach (['UPDATE', 'DELETE'] as $event) {
        $add('financial_entries_no_'.$event, 'financial_entries', $event, "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial history is immutable'");
        $add('article_versions_no_'.$event, 'article_versions', $event, "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Article versions are immutable'");
    }
    $fields = ['public_id', 'customer_id', 'sales_order_id', 'repair_case_id', 'source_version', 'source_amount_minor', 'created_by', 'created_at'];
    $changed = implode(' OR ', array_map(fn ($field) => "NOT (NEW.{$field} <=> OLD.{$field})", $fields));
    $add('financial_document_identity', 'financial_documents', 'UPDATE', "BEGIN IF {$changed} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial identity is immutable'; END IF; END");
    if ($upgraded) {
        foreach (['payment_transactions', 'payment_credit_events', 'payment_events'] as $table) {
            foreach (['update', 'delete'] as $event) {
                $add($table.'_no_'.$event, $table, strtoupper($event), "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment history is immutable'");
            }
        }
        foreach (['update', 'delete'] as $event) {
            $add('repair_revisions_no_'.$event, 'repair_record_revisions', strtoupper($event), "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Repair revisions are immutable'");
        }
    }
    ksort($contracts);

    return $contracts;
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
