<?php

declare(strict_types=1);

namespace NovinPay\Release;

require_once __DIR__.'/runtime.php';

/** These are durable postconditions, except for explicit intent markers. */
const SOURCE_CHECKPOINTS = [
    'initialized', 'maintenance_entered', 'schema_verified', 'source_installed',
    'runtime_verified', 'assets_published', 'traffic_reopen_intent', 'traffic_reopened', 'acceptance_verified',
];

function sourceCheckpointIdentity(array $manifest): array
{
    return ['manifestSha256' => $manifest['_sha256'], 'baseline' => $manifest['baseline'], 'commit' => $manifest['target']];
}

function sourceCheckpoints(string $journal, array $manifest): array
{
    $identity = sourceCheckpointIdentity($manifest);
    $phases = [];
    $missing = false;
    foreach (SOURCE_CHECKPOINTS as $phase) {
        $path = $journal.'/checkpoint-'.$phase.'.json';
        if (! file_exists($path) && ! is_link($path)) {
            $missing = true;

            continue;
        }
        $record = json_decode(privateRead($path), true, 16, JSON_THROW_ON_ERROR);
        requireGate(! $missing && ($record['phase'] ?? null) === $phase
            && array_intersect_key($record, $identity) === $identity, 'source_checkpoint_identity',
            'ordered checkpoints for this exact attempt', [], 'Preserve the journal and independently reconcile the actual deployment state.');
        $phases[] = $phase;
    }

    return $phases;
}

function recordSourceCheckpoint(string $journal, array $manifest, string $phase): void
{
    $completed = sourceCheckpoints($journal, $manifest);
    requireGate((SOURCE_CHECKPOINTS[count($completed)] ?? null) === $phase, 'source_checkpoint_order',
        'the next durable postcondition', [], 'Inspect the journal; do not repeat production-changing steps.');
    writeNew($journal.'/checkpoint-'.$phase.'.json', sourceCheckpointIdentity($manifest) + ['phase' => $phase, 'atUtc' => gmdate('c')]);
}

function sourceCompletionEvidence(array $manifest): array
{
    return sourceCheckpointIdentity($manifest) + [
        'result' => $manifest['successResult'],
        'migrationsApplied' => count($manifest['reviewedMigrations'] ?? []),
        'smsConfigurationPreserved' => true, 'activeProvider' => 'ippanel', 'otpReady' => true,
        'testResetOnLogoutPreserved' => true, 'testSmsDeliveryPreserved' => true, 'sendingExecuted' => false,
    ];
}

/** Reporting can be retried once only read-only acceptance and persisted postconditions exist. */
function publishSourceCompletion(string $journal, array $manifest): array
{
    requireGate(sourceCheckpoints($journal, $manifest) === SOURCE_CHECKPOINTS, 'source_acceptance_unproven',
        'all durable postconditions through acceptance', [], 'Use the authorized read-only reconciliation for the last recorded boundary.');
    $desired = sourceCompletionEvidence($manifest);
    $path = $journal.'/complete.json';
    if (file_exists($path) || is_link($path)) {
        $record = json_decode(privateRead($path), true, 16, JSON_THROW_ON_ERROR);
        requireGate(array_intersect_key($record, $desired) === $desired, 'source_completion_evidence_conflict',
            'the existing successful receipt for this attempt', [], 'Preserve the conflicting receipt.');

        return $record;
    }
    $record = $desired + ['completedUtc' => gmdate('c')];
    writeNew($path, $record);

    return $record;
}

function sourceDeploymentStatus(string $journal, array $manifest, ?array $worker): array
{
    $phases = sourceCheckpoints($journal, $manifest);
    $last = $phases[count($phases) - 1] ?? null;
    $path = $journal.'/complete.json';
    $published = false;
    if (file_exists($path) || is_link($path)) {
        $record = json_decode(privateRead($path), true, 16, JSON_THROW_ON_ERROR);
        $desired = sourceCompletionEvidence($manifest);
        $verified = $last === 'acceptance_verified';
        if (($record['reconciled'] ?? false) === true) {
            $observed = json_decode(privateRead($journal.'/reconciliation-verified.json'), true, 32, JSON_THROW_ON_ERROR);
            $verified = ($observed['manifestSha256'] ?? null) === $manifest['_sha256'] && ($observed['commit'] ?? null) === $manifest['target']
                && ($record['evidenceSha256'] ?? null) === hash('sha256', json_encode($observed, JSON_THROW_ON_ERROR));
        }
        requireGate($verified && array_intersect_key($record, $desired) === $desired,
            'source_completion_evidence_conflict', 'a matching receipt and durable acceptance', [], 'Preserve the receipt and reconcile independently.');
        $published = true;
    }

    return sourceCheckpointIdentity($manifest) + [
        'result' => $published ? 'APPLICATION_RECEIPT_VERIFIED' : ($last === 'acceptance_verified' ? 'ACCEPTED_RECEIPT_MISSING' : 'SOURCE_RECONCILIATION_REQUIRED'),
        'lastCheckpoint' => $last, 'workerExitCode' => $worker['exitCode'] ?? null,
        'migrationStarted' => is_file($journal.'/migration-started.json'), 'migrationCompleted' => is_file($journal.'/migration-complete.json'),
        'retrySafe' => false,
    ];
}
