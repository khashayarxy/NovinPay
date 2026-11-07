<?php

declare(strict_types=1);
use Illuminate\Support\ConfigurationUrlParser;
use NovinPay\Release\GateFailure;

// Read-only identity parser extracted unchanged from the reviewed backup helper.
function need(bool $ok, string $code): void
{
    if (! $ok) {
        throw new GateFailure($code, 'verified production environment identity', [], 'Inspect the named identity assertion; do not change credentials.');
    }
}
function verifiedProductionEnvironment(): array
{
    require_once APP.'/vendor/autoload.php';
    $env = Dotenv\Dotenv::createArrayBacked(APP)->load();
    need(($env['APP_ENV'] ?? '') === 'production' && ($env['DB_CONNECTION'] ?? '') === 'mariadb'
        && ($env['DB_DATABASE'] ?? '') === SOURCE_DB && ($env['DB_USERNAME'] ?? '') === 'novinpay_app1', 'production_environment_fields');
    $parts = parse_url($env['DB_URL'] ?? '');
    need(is_array($parts) && ($parts['scheme'] ?? '') === 'mariadb' && ($parts['host'] ?? '') === 'localhost'
        && ($parts['path'] ?? '') === '/'.SOURCE_DB && ! array_key_exists('user', $parts) && ! array_key_exists('pass', $parts)
        && ! array_key_exists('fragment', $parts) && (! isset($parts['port']) || $parts['port'] === 3306), 'production_url_identity');
    parse_str($parts['query'] ?? '', $query);
    need($query === ['timezone' => '+00:00'], 'production_url_options');
    $parser = new ConfigurationUrlParser;
    $effective = $parser->parseConfiguration(['driver' => 'mariadb', 'url' => $env['DB_URL'], 'host' => $env['DB_HOST'] ?? '127.0.0.1',
        'port' => $env['DB_PORT'] ?? '3306', 'database' => $env['DB_DATABASE'], 'username' => $env['DB_USERNAME'],
        'password' => $env['DB_PASSWORD'] ?? '', 'unix_socket' => $env['DB_SOCKET'] ?? '', 'strict' => true]);
    need($effective['database'] === SOURCE_DB && $effective['username'] === 'novinpay_app1' && $effective['host'] === 'localhost'
        && $effective['driver'] === 'mariadb' && (int) $effective['port'] === 3306 && $effective['unix_socket'] === ''
        && $effective['timezone'] === '+00:00' && $effective['strict'] === true && $effective['password'] !== '', 'production_effective_identity');
    need(is_file(APP.'/bootstrap/cache/config.php'), 'production_cached_config_missing');
    $cached = require APP.'/bootstrap/cache/config.php';
    need(($cached['database']['default'] ?? null) === 'mariadb' && ($cached['app']['env'] ?? null) === 'production'
        && ! empty($env['APP_KEY']) && ($cached['app']['key'] ?? null) === $env['APP_KEY'], 'production_cached_application');
    $active = $parser->parseConfiguration($cached['database']['connections']['mariadb']);
    foreach (['driver', 'host', 'database', 'username', 'password', 'timezone', 'strict', 'unix_socket'] as $field) {
        need(array_key_exists($field, $active) && $active[$field] === $effective[$field], 'production_cached_connection_mismatch');
    }
    need((int) ($active['port'] ?? 0) === 3306, 'production_cached_port');
    $runtime = new PDO('mysql:host=localhost;port=3306;dbname='.SOURCE_DB.';charset=utf8mb4', $effective['username'], $effective['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $runtime->exec('SET TRANSACTION READ ONLY');
    $runtime->beginTransaction();
    try {
        $identity = $runtime->query('SELECT DATABASE() db, CURRENT_USER() account, VERSION() version')->fetch();
        need($identity['db'] === SOURCE_DB && explode('@', $identity['account'])[0] === 'novinpay_app1'
            && str_starts_with($identity['version'], '10.6.28'), 'production_connected_identity');
    } finally {
        $runtime->rollBack();
    }

    return $env;
}
