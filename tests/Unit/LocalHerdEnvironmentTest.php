<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class LocalHerdEnvironmentTest extends TestCase
{
    private string $root;

    private array $settings;

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/novinpay-herd-'.bin2hex(random_bytes(12));
        mkdir($this->root, 0700);
        $this->root = str_replace('\\', '/', realpath($this->root));
        mkdir($this->root.'/storage', 0700);
        touch($this->root.'/development.sqlite');
        $this->settings = [
            'APP_ENV' => 'local', 'APP_URL' => 'https://novinpaypos.test',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->root.'/development.sqlite',
            'DB_URL' => 'sqlite:///'.$this->root.'/development.sqlite',
            'LARAVEL_STORAGE_PATH' => $this->root.'/storage', 'APP_CONFIG_CACHE' => $this->root.'/config.php',
            'APP_ROUTES_CACHE' => $this->root.'/routes.php', 'APP_EVENTS_CACHE' => $this->root.'/events.php',
            'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'database', 'SESSION_CONNECTION' => 'sqlite', 'SESSION_SECURE_COOKIE' => 'true',
            'SUPPORT_CHAT_ENABLED' => 'true', 'SMSIR_ENABLED' => 'false', 'SMSIR_NOTIFICATIONS_ENABLED' => 'false',
            'ZIBAL_ENABLED' => 'false', 'ZIBAL_LIVE_REQUESTS' => 'false',
            'POSTEX_QUOTES_ENABLED' => 'false', 'POSTEX_INDEPENDENT_POSTAGE_VERIFIED' => 'false',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        ];
        $this->writeSettings();
    }

    protected function tearDown(): void
    {
        // Only known files in this test's freshly created directory are removed.
        foreach (['herd-environment.json', 'development.sqlite', 'config.php'] as $file) {
            if (file_exists($this->root.'/'.$file)) {
                unlink($this->root.'/'.$file);
            }
        }
        rmdir($this->root.'/storage');
        rmdir($this->root);
        parent::tearDown();
    }

    private function writeSettings(): void
    {
        file_put_contents($this->root.'/herd-environment.json', json_encode($this->settings, JSON_THROW_ON_ERROR));
    }

    public function test_accepts_only_the_pinned_local_environment_without_modifying_it(): void
    {
        $load = require dirname(__DIR__, 2).'/scripts/local/herd-environment.php';
        $before = hash_file('sha256', $this->root.'/herd-environment.json');
        $this->assertSame($this->settings, $load($this->root));
        $this->assertSame($before, hash_file('sha256', $this->root.'/herd-environment.json'));
    }

    public function test_rejects_remote_database_provider_activation_and_path_substitution(): void
    {
        $load = require dirname(__DIR__, 2).'/scripts/local/herd-environment.php';
        $original = $this->settings;
        foreach (['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => '/other/database.sqlite', 'DB_URL' => 'mysql://invalid', 'APP_ENV' => 'production', 'APP_URL' => 'https://novinpaypos.com', 'LARAVEL_STORAGE_PATH' => '/other/storage', 'CACHE_STORE' => 'database', 'SMSIR_ENABLED' => 'true', 'ZIBAL_LIVE_REQUESTS' => 'true', 'POSTEX_INDEPENDENT_POSTAGE_VERIFIED' => 'true'] as $key => $value) {
            $this->settings = array_replace($original, [$key => $value]);
            $this->writeSettings();
            try {
                $load($this->root);
                $this->fail('Unsafe local configuration was accepted: '.$key);
            } catch (RuntimeException $error) {
                $this->assertSame('NovinPay dedicated local environment is unavailable; database access blocked.', $error->getMessage());
            }
        }
    }

    public function test_missing_database_cannot_fall_back_to_project_environment(): void
    {
        unlink($this->root.'/development.sqlite');
        $this->expectException(RuntimeException::class);
        (require dirname(__DIR__, 2).'/scripts/local/herd-environment.php')($this->root);
    }

    public function test_cached_configuration_cannot_override_the_database_pin(): void
    {
        touch($this->root.'/config.php');
        $this->expectException(RuntimeException::class);
        (require dirname(__DIR__, 2).'/scripts/local/herd-environment.php')($this->root);
    }

    public function test_malformed_configuration_is_denied_without_echoing_contents(): void
    {
        file_put_contents($this->root.'/herd-environment.json', '{invalid synthetic configuration');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database access blocked');
        (require dirname(__DIR__, 2).'/scripts/local/herd-environment.php')($this->root);
    }

    public function test_a_symlink_cannot_substitute_the_pinned_database(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Windows symlink privileges are not requested; Linux CI covers this boundary.');
        }
        unlink($this->root.'/development.sqlite');
        symlink($this->root.'/herd-environment.json', $this->root.'/development.sqlite');
        $this->expectException(RuntimeException::class);
        (require dirname(__DIR__, 2).'/scripts/local/herd-environment.php')($this->root);
    }
}
