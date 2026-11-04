<?php

declare(strict_types=1);
// Staging HTTP credentials have their own private source; bootstrap secrets are database/admin only.
function np_basic_need(bool $ok, string $code): void
{
    if (! $ok) {
        throw new RuntimeException($code);
    }
}
function np_basic_validate(array $auth, string $passwordFile, string $expectedUser): array
{
    $keys = array_keys($auth);
    sort($keys);
    np_basic_need($keys === ['password', 'username'], 'basic_credential_schema');
    np_basic_need(is_string($auth['username']) && $auth['username'] === $expectedUser && $expectedUser === 'stage-review', 'basic_credential_username');
    np_basic_need(is_string($auth['password']) && strlen($auth['password']) >= 1 && strlen($auth['password']) <= 1024 && preg_match('/[\x00-\x1f\x7f]/', $auth['password']) === 0, 'basic_credential_password_format');
    $entries = [];
    foreach (explode("\n", $passwordFile) as $line) {
        $pair = explode(':', rtrim($line, "\r"), 2);
        if (count($pair) === 2 && $pair[0] === $expectedUser) {
            $entries[] = $pair[1];
        }
    }
    np_basic_need(count($entries) === 1 && preg_match('/\A\$2[aby]\$/', $entries[0]) === 1, 'basic_active_account');
    np_basic_need(password_verify($auth['password'], $entries[0]), 'basic_credential_active_hash_mismatch');

    return $auth;
}
function np_basic_private_file(string $path, ?int $mode = null): string
{
    clearstatcache(true, $path);
    np_basic_need(is_file($path) && ! is_link($path), 'basic_private_file_missing_or_substituted');
    if (PHP_OS_FAMILY !== 'Windows') {
        np_basic_need(fileowner($path) === posix_geteuid() && ($mode === null ? ((fileperms($path) & 0022) === 0) : ((fileperms($path) & 0777) === $mode)), 'basic_private_file_permissions');
    }
    np_basic_need(filesize($path) > 0 && filesize($path) <= 4096, 'basic_private_file_size');
    $bytes = file_get_contents($path);
    np_basic_need(is_string($bytes), 'basic_private_file_read');

    return $bytes;
}
function np_basic_load(string $kit, array $manifest): array
{
    $authFile = $kit.'/.staging-http-basic.json';
    $passwordFile = $kit.'/.htpasswd';
    $raw = np_basic_private_file($authFile, 0600);
    $hashes = np_basic_private_file($passwordFile);
    np_basic_need(hash_equals($manifest['basicPasswordFileSha256'], hash('sha256', $hashes)), 'basic_active_password_file_changed');
    try {
        $auth = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('basic_credential_json');
    }
    np_basic_need(is_array($auth),'basic_credential_schema');

    return np_basic_validate($auth,$hashes,$manifest['basicUser']);
}
