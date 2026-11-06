<?php

// Loaded only by NovinPay's host-filtered Herd guard, before Laravel boots.
// No .env parsing or configurable SQL server/DSN is permitted here.
return static function (string $root): array {
    $root = str_replace('\\', '/', $root);
    $fail = static function (): never {
        throw new RuntimeException('NovinPay dedicated local environment is unavailable; database access blocked.');
    };
    if (! is_dir($root) || is_link($root) || str_replace('\\', '/', (string) realpath($root)) !== $root) {
        $fail();
    }
    $path = $root.'/herd-environment.json';
    $database = $root.'/development.sqlite';
    if (! is_file($path) || is_link($path) || ! is_file($database) || is_link($database)
        || str_replace('\\', '/', (string) realpath($database)) !== $database) {
        $fail();
    }
    try {
        $settings = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $fail();
    }
    if (! is_array($settings)) {
        $fail();
    }
    $required = [
        'APP_ENV' => 'local', 'APP_URL' => 'https://novinpaypos.test',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => 'sqlite:///'.$database,
        'LARAVEL_STORAGE_PATH' => $root.'/storage', 'APP_CONFIG_CACHE' => $root.'/config.php',
        'APP_ROUTES_CACHE' => $root.'/routes.php', 'APP_EVENTS_CACHE' => $root.'/events.php',
        'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'database', 'SESSION_CONNECTION' => 'sqlite', 'SESSION_SECURE_COOKIE' => 'true',
        'SUPPORT_CHAT_ENABLED' => 'true', 'SMSIR_ENABLED' => 'false', 'SMSIR_NOTIFICATIONS_ENABLED' => 'false',
        'ZIBAL_ENABLED' => 'false', 'ZIBAL_LIVE_REQUESTS' => 'false',
        'POSTEX_QUOTES_ENABLED' => 'false', 'POSTEX_INDEPENDENT_POSTAGE_VERIFIED' => 'false',
        'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    ];
    foreach ($required as $key => $value) {
        if (($settings[$key] ?? null) !== $value) {
            $fail();
        }
    }
    foreach (['config.php', 'routes.php', 'events.php'] as $cache) {
        if (file_exists($root.'/'.$cache) || is_link($root.'/'.$cache)) {
            $fail();
        }
    }
    if (! is_dir($root.'/storage') || is_link($root.'/storage')
        || str_replace('\\', '/', (string) realpath($root.'/storage')) !== $root.'/storage') {
        $fail();
    }

    return $settings;
};
