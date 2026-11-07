<?php

declare(strict_types=1);
use NovinPay\Release\GateFailure;

// Diagnostic only: these candidate matches must never authorize a deployment.
function pr13PrivilegeEvidence(array $rows, string $account, string $database, array $original): array
{
    [$user, $host] = explode('@', $account, 2);
    $quoted = "'".$user."'@'".$host."'";
    $required = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'INDEX', 'TRIGGER'];
    $candidate = $scopes = $formats = [];
    foreach ($rows as $row) {
        $grantee = $row['GRANTEE'];
        if (! in_array($grantee, [$quoted, $account], true)) {
            continue;
        }
        $scope = $row['TABLE_SCHEMA'];
        // Display candidate literal-underscore encoding differences only.
        // No wildcard expansion and no authorization decision here.
        if (str_replace('\\_', '_', $scope) !== $database) {
            continue;
        }
        $scopes[] = $scope;
        $formats[] = $grantee === $quoted ? 'quoted' : 'unquoted';
        $candidate[] = $row['PRIVILEGE_TYPE'];
    }
    $candidate = array_values(array_unique($candidate));
    sort($candidate);
    $missingOriginal = array_values(array_diff($required, $original));
    $missingCandidate = array_values(array_diff($required, $candidate));
    $scopes = array_values(array_unique($scopes));
    $formats = array_values(array_unique($formats));
    $finding = $missingOriginal === [] ? 'original_policy_satisfied' : 'requires_review';
    if ($missingOriginal !== [] && $missingCandidate === []) {
        $finding = $scopes !== [$database] ? 'escaped_schema_metadata' : 'grantee_representation';
    }

    return ['finding' => $finding, 'originalPrivileges' => $original, 'missingOriginalPrivileges' => $missingOriginal,
        'candidateLiteralScopePrivileges' => $candidate, 'missingCandidateLiteralScopePrivileges' => $missingCandidate,
        'targetScopeRepresentations' => $scopes, 'granteeRepresentations' => $formats];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once __DIR__.'/runtime.php';
$check = 'invocation';
$db = null;
try {
    $need = static function (bool $ok, string $name): void {
        if (! $ok) {
            throw new GateFailure($name, 'read-only diagnostic identity', [], 'Inspect the named assertion without changing permissions.');
        }
    };
    $need(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && function_exists('posix_geteuid') && posix_geteuid() === 1321 && count($argv) === 3, $check);
    $check = 'manifest';
    $need(is_file($argv[1]) && ! is_link($argv[1]) && hash_equals($argv[2], hash_file('sha256', $argv[1])), $check);
    $m = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    $need(hash_equals($m['diagnostic']['sha256'], hash_file('sha256', __FILE__)), 'diagnostic_integrity');
    foreach (['lib.php', 'runtime.php'] as $name) {
        $need(! is_link(__DIR__.'/'.$name) && hash_equals($m['tools'][$name], hash_file('sha256', __DIR__.'/'.$name)), 'library_integrity');
    }
    $check = 'production_head';
    $git = \NovinPay\Release\command(['git', 'rev-parse', 'HEAD'], '/home/novinpay/novinpay', $check, 30);
    $need($git['exit'] === 0 && trim($git['stdout']) === $m['baseline'] && $m['baseline'] === '1f39bb476fd31880600564e58599c4488622c4c0', $check);
    $check = 'migrator_identity';
    $path = '/home/novinpay/novinpay-releases/155fc9f30feb5b9d0c41445b33f1118d5e07da8e/.stage2-credentials.json';
    $need(is_file($path) && ! is_link($path) && fileowner($path) === 1321 && (fileperms($path) & 0777) === 0600, $check);
    $credential = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['migrator'];
    $need($credential['user'] === 'novinpay_mig1', $check);
    $db = new PDO('mysql:host=localhost;port=3306;dbname=novinpay_live1;charset=utf8mb4', $credential['user'], $credential['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    unset($credential);
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    $account = $db->query('SELECT CURRENT_USER()')->fetchColumn();
    $need($db->query('SELECT DATABASE()')->fetchColumn() === 'novinpay_live1' && str_starts_with($account, 'novinpay_mig1@'), $check);
    $role = $db->query('SELECT CURRENT_ROLE()')->fetchColumn();
    $need($role === null || $role === 'NONE', 'active_role');
    [$user, $host] = explode('@', $account, 2);
    $check = 'privilege_metadata';
    $original = $db->prepare('SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE TABLE_SCHEMA=? AND GRANTEE=?');
    $original->execute(['novinpay_live1', "'".$user."'@'".$host."'"]);
    $rows = $db->query('SELECT GRANTEE,TABLE_SCHEMA,PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES')->fetchAll();
    $need(count($rows) <= 4096, 'bounded_metadata');
    $report = pr13PrivilegeEvidence($rows, $account, 'novinpay_live1', $original->fetchAll(PDO::FETCH_COLUMN));
    $db->rollBack();
    echo json_encode(['result' => 'PR13_READONLY_PRIVILEGE_DIAGNOSTIC', 'database' => 'novinpay_live1', 'account' => $account,
        'activeRole' => 'none', 'evidence' => $report, 'databaseWrites' => false, 'privilegesChanged' => false,
        'deploymentExecuted' => false, 'releaseReady' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['result' => 'PR13_PRIVILEGE_DIAGNOSTIC_STOP', 'check' => $error instanceof GateFailure ? $error->check : $check,
        'category' => $error instanceof PDOException ? 'database_details_withheld' : 'identity_or_contract', 'databaseWrites' => false, 'deploymentExecuted' => false]).PHP_EOL;
    exit(1);
}
