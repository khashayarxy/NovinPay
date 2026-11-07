<?php

declare(strict_types=1);

// Packaging evidence only. This never changes the backup's hash-equality guard
// or authorizes a retry; a newly reviewed manifest is still required.
function pr13MaintenanceSnapshotEvidence(string $stateText, string $auditText, array $historicalHashes): array
{
    $need = static function (bool $ok, string $label): void {
        if (! $ok) {
            throw new RuntimeException($label);
        }
    };
    $need(strlen($stateText) <= 524288 && strlen($auditText) <= 8388608 && str_ends_with($auditText, "\n"), 'maintenance_snapshot_bounds_or_incomplete');
    foreach (['state.json', 'audit.jsonl'] as $file) {
        $need(is_string($historicalHashes[$file] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $historicalHashes[$file]) === 1, 'maintenance_anchor_invalid');
    }
    $state = json_decode($stateText, true, 16, JSON_THROW_ON_ERROR);
    $need(is_array($state) && ($state['format'] ?? null) === 1 && ($state['enabled'] ?? null) === false
        && array_key_exists('html', $state) && $state['html'] === null && array_key_exists('html_sha256', $state) && $state['html_sha256'] === null,
        'maintenance_not_verified_off');
    $lines = explode("\n", substr($auditText, 0, -1));
    $need(count($lines) > 0 && count($lines) % 2 === 0, 'maintenance_audit_incomplete_pair');
    $previous = str_repeat('0', 64);
    $seen = [];
    $hash = hash_init('sha256');
    $anchorPairs = null;
    $last = null;
    $transitions = [];
    foreach (array_chunk($lines, 2) as $index => [$preparedText, $appliedText]) {
        $prepared = json_decode($preparedText, true, 16, JSON_THROW_ON_ERROR);
        $applied = json_decode($appliedText, true, 16, JSON_THROW_ON_ERROR);
        $need(is_array($prepared) && is_array($applied) && ($prepared['phase'] ?? null) === 'prepared' && ($applied['phase'] ?? null) === 'applied', 'maintenance_audit_phase');
        unset($prepared['phase'], $applied['phase']);
        ksort($prepared);
        ksort($applied);
        $need($prepared === $applied && ($applied['target'] ?? null) === 'public_website'
            && in_array($applied['action'] ?? null, ['maintenance.enabled', 'maintenance.disabled'], true)
            && is_int($applied['actor_id'] ?? null) && $applied['actor_id'] > 0, 'maintenance_audit_pair_mismatch');
        $version = $applied['version'] ?? null;
        $need(is_string($version) && preg_match('/\A[a-f0-9]{64}\z/D', $version) === 1 && $version !== str_repeat('0', 64)
            && ! isset($seen[$version]) && ($applied['previous_version'] ?? null) === $previous, 'maintenance_audit_chain');
        $need(is_string($applied['at'] ?? null), 'maintenance_audit_timestamp');
        try {
            $date = DateTimeImmutable::createFromFormat('!'.DATE_ATOM, $applied['at']);
            $valid = $date !== false && DateTimeImmutable::getLastErrors() === false && $date->format(DATE_ATOM) === $applied['at'];
        } catch (Throwable) {
            $valid = false;
        }
        $need($valid, 'maintenance_audit_timestamp');
        $seen[$version] = true;
        $previous = $version;
        hash_update($hash, $preparedText."\n".$appliedText."\n");
        if (hash_equals($historicalHashes['audit.jsonl'], hash_final(hash_copy($hash)))) {
            $anchorPairs = $index + 1;
        }
        $transitions[] = ['action' => $applied['action'], 'at' => $applied['at']];
        $last = $applied;
    }
    $need($anchorPairs !== null, 'maintenance_history_not_preserved');
    $need($last['action'] === 'maintenance.disabled' && ($state['version'] ?? null) === $last['version']
        && ($state['previous_version'] ?? null) === $last['previous_version'] && ($state['updated_at'] ?? null) === $last['at']
        && array_key_exists('actor_id', $state) && array_key_exists('actor_id', $last) && $state['actor_id'] === $last['actor_id'], 'maintenance_state_audit_mismatch');
    $current = ['state.json' => hash('sha256', $stateText), 'audit.jsonl' => hash('sha256', $auditText)];

    return ['maintenanceOff' => true, 'historyPrefixPreserved' => true, 'completedTransitions' => count($transitions),
        'historicalTransitions' => $anchorPairs, 'appendedTransitions' => array_slice($transitions, $anchorPairs),
        'currentHashes' => $current, 'requiresNewManifest' => ! hash_equals($historicalHashes['state.json'], $current['state.json'])
            || ! hash_equals($historicalHashes['audit.jsonl'], $current['audit.jsonl']), 'retryAuthorized' => false];
}

function pr13PriorAttemptIsPreBackup(array $entries, array $start, array $complete, string $manifest, bool $backupExists, bool $rehearsalExists): bool
{
    sort($entries);
    $expected = ['pipeline.lock', 'restore_defaults.complete.json', 'restore_defaults.started.json'];
    sort($expected);

    return $entries === $expected && ! $backupExists && ! $rehearsalExists
        && preg_match('/\A[a-f0-9]{64}\z/D', $manifest) === 1
        && ($start['manifest'] ?? null) === $manifest && ($complete['manifest'] ?? null) === $manifest
        && ($start['phase'] ?? null) === 'restore_defaults' && ($complete['phase'] ?? null) === 'restore_defaults'
        && ($complete['result'] ?? null) === ['charset_name' => 'latin1', 'collation_name' => 'latin1_swedish_ci'];
}
