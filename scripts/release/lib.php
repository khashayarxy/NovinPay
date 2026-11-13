<?php

declare(strict_types=1);

namespace NovinPay\Release;

use PDOException;
use RuntimeException;
use Throwable;

require_once __DIR__.'/schema-contract.php';

/** Only controlled identifiers and explicitly constructed metadata may reach stdout. */
final class GateFailure extends RuntimeException
{
    public function __construct(public readonly string $check, public readonly string $expected, public readonly array $actual, public readonly string $next)
    {
        parent::__construct($check);
    }
}

function requireGate(bool $ok, string $check, string $expected, array $actual, string $next): void
{
    if (! $ok) {
        throw new GateFailure($check, $expected, $actual, $next);
    }
}

/** SQL metadata escapes literal underscores. Never interpret grant wildcards. */
function databasePrivilegesPresent(array $rows, string $database, string $account, array $required): bool
{
    if (! preg_match('/\Anovinpay_[a-z0-9]+\z/D', $database) || $account !== 'novinpay_mig1@localhost' || $required === []) {
        return false;
    }
    $scopes = [$database, str_replace('_', '\\_', $database)];
    $found = [];
    foreach ($rows as $row) {
        if (($row['GRANTEE'] ?? null) === "'novinpay_mig1'@'localhost'" && in_array($row['TABLE_SCHEMA'] ?? null, $scopes, true)) {
            $found[] = $row['PRIVILEGE_TYPE'] ?? null;
        }
    }

    return array_diff($required, $found) === [];
}

function failureReport(Throwable $error, string $phase, string $check, bool $modified = false): array
{
    $category = match (true) {
        $error instanceof GateFailure => 'guard',
        $error instanceof PDOException => 'database',
        $error instanceof \JsonException => 'invalid_json',
        $error instanceof \TypeError => 'type_error',
        $error instanceof \Error => 'php_error',
        default => 'operation_failure',
    };
    // Never serialize the exception message, trace, arguments, SQL or stderr.
    $actual = $error instanceof GateFailure ? $error->actual : ['exceptionCategory' => $category];
    if ($error instanceof PDOException) {
        $actual['sqlState'] = preg_match('/\A[A-Z0-9]{5}\z/', (string) $error->getCode()) ? (string) $error->getCode() : null;
        $actual['driverCode'] = isset($error->errorInfo[1]) && is_int($error->errorInfo[1]) ? $error->errorInfo[1] : null;
    }

    $identifier = $error instanceof GateFailure ? $error->check : $check;
    if ($error instanceof RuntimeException && ! $error instanceof PDOException && preg_match('/\A[a-z0-9_]+\z/', $error->getMessage())) {
        $identifier = $error->getMessage();
    }

    return ['result' => 'STOP', 'phase' => $phase, 'check' => $identifier,
        'category' => $category, 'expected' => $error instanceof GateFailure ? $error->expected : 'operation completes successfully',
        'actual' => $actual, 'productionModified' => $modified, 'retrySafe' => false,
        'nextAction' => $error instanceof GateFailure ? $error->next : 'Preserve evidence; resolve the named operation before resuming.'];
}

/** A matching restore database needs no ALTER privilege and no metadata write. */
function restoreDefaultsPlan(array $source, array $restore, array $privileges): string
{
    foreach ([$source, $restore] as $defaults) {
        requireGate(isset($defaults['charset'], $defaults['collation']) && preg_match('/\A[a-z0-9_]+\z/', $defaults['charset']) === 1
            && preg_match('/\A[a-z0-9_]+\z/', $defaults['collation']) === 1,
            'restore_defaults_metadata', 'known charset and collation', [], 'Inspect database metadata read-only.');
    }
    if ($source === $restore) {
        return 'already_matches_no_alter';
    }
    requireGate(in_array('ALTER', $privileges, true), 'restore_defaults_privilege', 'matching defaults or explicitly scoped ALTER privilege',
        ['defaultsMatch' => false, 'alterPrivilege' => false],
        'Correct defaults only on the approved empty disposable database through the supported provisioning workflow, then verify them.');

    return 'disposable_alter_required';
}

/** Evidence is fresh at consumption, not when a manual command was prepared. */
function quotaGate(array $proof, int $now, string $nonce, string $commit, int $physicalFree, int $required = 805306368): void
{
    requireGate($required >= 805306368 && ($proof['account'] ?? null) === 'novinpay'
        && ($proof['apiHost'] ?? null) === 'leda.asemanhost.com:2083' && ($proof['method'] ?? null) === 'Quota/get_quota_info'
        && ($proof['httpsCertificateValidated'] ?? null) === true && ($proof['underQuota'] ?? null) === true
        && ($proof['nonce'] ?? null) === $nonce && ($proof['sourceCommit'] ?? null) === $commit,
        'quota_identity', 'authenticated request-bound account quota', [], 'Refresh via the authenticated cPanel API; do not use local uapi.');
    $time = $proof['observedAtUnix'] ?? null;
    requireGate(is_int($time) && $time <= $now && $now - $time <= 120, 'quota_freshness', 'UTC evidence at most 120 seconds old',
        ['ageSeconds' => is_int($time) ? $now - $time : null], 'Refresh only quota evidence, then revalidate before the fence.');
    $free = $proof['freeBytes'] ?? null;
    $used = $proof['usedBytes'] ?? null;
    $limit = $proof['limitBytes'] ?? null;
    requireGate(is_int($free) && is_int($used) && is_int($limit) && $used >= 0 && $limit > 0
        && $free + $used <= $limit + 1048576 && $free >= $required + 2147483648 && $physicalFree >= $required,
        'quota_headroom', 'measured requirement plus 2 GiB account reserve and sufficient physical space',
        ['freeBytes' => is_int($free) ? $free : null, 'physicalFreeBytes' => $physicalFree, 'requiredBytes' => $required],
        'Preserve recovery assets; inspect quota before further writes.');
}

/** Incomplete readiness publication may be awaited; an integrity mismatch fails. */
function quotaReply(?string $ready, ?string $reply): ?array
{
    if ($ready === null || $reply === null) {
        return null;
    }
    try {
        $marker = json_decode($ready, true, 8, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        return null;
    }
    if (! is_array($marker) || ! is_string($marker['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $marker['sha256'])) {
        return null;
    }
    requireGate(hash_equals($marker['sha256'], hash('sha256', $reply)), 'quota_response_hash', 'complete authenticated quota reply', [], 'Preserve the mismatched reply; never retry an integrity failure.');

    return json_decode($reply, true, 16, JSON_THROW_ON_ERROR);
}

/** Central owner policy; neither environment variables nor manifests override it. */
function backupPolicy(): array
{
    static $policy;

    return $policy ??= require __DIR__.'/backup-policy.php';
}

/** Advisory scheduling metadata only; never creates a backup or restore target. */
function nextBackupDueUnix(int $snapshotUnix): int
{
    requireGate($snapshotUnix >= 0 && $snapshotUnix <= PHP_INT_MAX - backupPolicy()['intervalSeconds'],
        'backup_schedule_timestamp', 'valid backup timestamp', [], 'Inspect the recorded backup receipt.');

    return $snapshotUnix + backupPolicy()['intervalSeconds'];
}

/** A code rollback is never permission to restore an old live database snapshot. */
function recoveryGate(array $proof, array $policy, int $now): void
{
    foreach (['authenticatedDecryption', 'archiveContentsVerified', 'isolatedRestoreVerified', 'recoveryKeyAvailable'] as $field) {
        requireGate(($proof[$field] ?? null) === true, 'recovery_'.$field, 'independently verified recovery evidence', [], 'Complete independent recovery verification.');
    }
    requireGate(($proof['sourceDatabase'] ?? null) === 'novinpay_live1' && is_int($proof['snapshotUnix'] ?? null)
        && $proof['snapshotUnix'] <= $now && ($policy['automaticDatabaseRestore'] ?? null) === false,
        'recovery_identity', 'live database identity, valid timestamp and no automatic data rollback', [], 'Inspect the pinned recovery evidence.');
    $maxAge = backupPolicy()['maxSnapshotAgeSeconds'];
    requireGate(! array_key_exists('maxSnapshotAgeSeconds', $policy) || $policy['maxSnapshotAgeSeconds'] === $maxAge,
        'backup_policy_mismatch', 'owner-approved 72-hour policy', [], 'Update obsolete release configuration; do not regenerate a valid backup to satisfy an old age rule.');
    requireGate($now - $proof['snapshotUnix'] <= $maxAge,
        'backup_freshness', 'verified recovery snapshot no older than 72 hours',
        ['ageSeconds' => $now - $proof['snapshotUnix'], 'maximumAgeSeconds' => $maxAge],
        'Preserve existing evidence. Refresh recovery only for an imminent authorized release when required; never provision a restore target automatically.');
}

function resumeDecision(string $recorded, bool $postconditionMatches): string
{
    return match ($recorded) {
        'pending' => 'run',
        'complete' => $postconditionMatches ? 'reuse' : 'stop_evidence_changed',
        'started', 'failed' => 'stop_reconcile_partial_operation',
        default => 'stop_invalid_journal',
    };
}

function healthDecision(string $result, bool $localReady, bool $preserved): string
{
    if (! $localReady || ! $preserved || $result === 'application_failure') {
        return 'fence_stop';
    }

    return match ($result) {
        'pass' => 'pass',
        'transport_failure' => 'live_verification_pending_75',
        default => 'fence_stop',
    };
}
