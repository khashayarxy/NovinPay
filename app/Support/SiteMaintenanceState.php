<?php

namespace App\Support;

require_once __DIR__.'/MaintenanceStateFault.php';
require_once __DIR__.'/MaintenanceStateConflict.php';

// Plain PHP: also required by public/index.php before Composer or Laravel.
class SiteMaintenanceState
{
    public const INITIAL = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(public readonly string $directory) {}

    public static function adminCorridor(string $uri): bool
    {
        // Inspect the raw origin-form target; parse_url would turn //host/admin
        // into /admin. Never decode or normalize a target into an exemption.
        if (! str_starts_with($uri, '/') || str_starts_with($uri, '//')
            || str_contains($uri, '\\') || str_contains($uri, '#')
            || preg_match('/[\x00-\x20\x7f]/', $uri)) {
            return false;
        }
        $path = explode('?', $uri, 2)[0];

        // No decoded dots/slashes, prefix lookalikes, cookie or query bypasses.
        return is_string($path) && preg_match('~\A/admin(?:/[A-Za-z0-9_-]+)*/?\z~D', $path) === 1;
    }

    public function read(): array
    {
        clearstatcache();
        if (! is_dir(dirname($this->directory)) || ! is_readable(dirname($this->directory))) {
            throw new MaintenanceStateFault('state_parent_unavailable');
        }
        if (! file_exists($this->directory) && ! is_link($this->directory)) {
            return ['enabled' => false, 'version' => self::INITIAL, 'updated_at' => null];
        }
        $this->privateDirectory();
        $file = $this->directory.'/state.json';
        if (! is_file($file) || is_link($file) || ! is_readable($file) || filesize($file) > 524288) {
            throw new MaintenanceStateFault('state_unreadable');
        }
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($file) & 0077) !== 0) {
            throw new MaintenanceStateFault('state_permissions');
        }
        try {
            $state = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new MaintenanceStateFault('state_invalid');
        }
        if (! is_array($state) || ($state['format'] ?? null) !== 1 || ! is_bool($state['enabled'] ?? null)
            || ! is_string($state['version'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $state['version'])
            || ! self::validUpdatedAt($state['updated_at'] ?? null)
            || ($state['enabled'] && (! is_string($state['html'] ?? null) || ! is_string($state['html_sha256'] ?? null)
                || ! hash_equals(hash('sha256', $state['html']), $state['html_sha256'])))) {
            throw new MaintenanceStateFault('state_invalid');
        }

        return $state;
    }

    private static function validUpdatedAt(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-](?:[01]\d|2[0-3]):[0-5]\d\z/', $value)) {
            return false;
        }
        try {
            $date = \DateTimeImmutable::createFromFormat('!'.DATE_ATOM, $value);
            $errors = \DateTimeImmutable::getLastErrors();

            return $date !== false && $errors === false && $date->format(DATE_ATOM) === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    public function change(string $expected, bool $enabled, ?int $actor, string $html = '', bool $emergency = false): array
    {
        $fresh = false;
        if (! file_exists($this->directory) && ! is_link($this->directory)) {
            $fresh = @mkdir($this->directory, 0700);
            if (! $fresh && ! is_dir($this->directory)) {
                throw new MaintenanceStateFault('directory_unavailable');
            }
        }
        $this->privateDirectory();
        $lockPath = $this->directory.'/state.lock';
        if (is_link($lockPath)) {
            throw new MaintenanceStateFault('lock_invalid');
        }
        $mask = umask(0077);
        try {
            $lock = @fopen($lockPath, 'c+b');
        } finally {
            umask($mask);
        }
        if (! $lock || ! chmod($lockPath, 0600)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new MaintenanceStateFault('lock_unavailable');
        }
        $deadline = microtime(true) + 3;
        try {
            while (! flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new MaintenanceStateConflict('maintenance_busy');
                }
                usleep(10000);
            }
            try {
                $old = $fresh && ! file_exists($this->directory.'/state.json')
                    ? ['enabled' => false, 'version' => self::INITIAL] : $this->read();
            } catch (MaintenanceStateFault $exception) {
                if (! $emergency) {
                    throw $exception;
                }
                $old = ['enabled' => true, 'version' => 'unreadable'];
            }
            if (! $emergency && ! hash_equals($old['version'], $expected)) {
                throw new MaintenanceStateConflict('maintenance_changed');
            }
            if ($enabled && ($html === '' || strlen($html) > 350000)) {
                throw new MaintenanceStateFault('template_invalid');
            }
            $state = ['format' => 1, 'enabled' => $enabled, 'version' => bin2hex(random_bytes(32)),
                'previous_version' => $old['version'], 'updated_at' => gmdate('c'), 'actor_id' => $actor,
                'html' => $enabled ? $html : null, 'html_sha256' => $enabled ? hash('sha256', $html) : null];
            $event = ['action' => $emergency ? 'maintenance.emergency_disabled' : ($enabled ? 'maintenance.enabled' : 'maintenance.disabled'),
                'actor_id' => $actor, 'target' => 'public_website', 'at' => $state['updated_at'],
                'version' => $state['version'], 'previous_version' => $state['previous_version']];
            // Durable intent precedes publication. The state version links it to
            // the atomic commit, even if the process dies before the applied line.
            $this->journal($event + ['phase' => 'prepared']);
            $this->publish($state);
            try {
                $this->journal($event + ['phase' => 'applied']);
            } catch (MaintenanceStateFault) {
                throw new MaintenanceStateFault('audit_completion_pending', true);
            }

            return $state;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function privateDirectory(): void
    {
        if (! is_dir($this->directory) || is_link($this->directory) || ! is_readable($this->directory)
            || (PHP_OS_FAMILY !== 'Windows' && (fileperms($this->directory) & 0077) !== 0)) {
            throw new MaintenanceStateFault('directory_invalid');
        }
    }

    protected function journal(array $event): void
    {
        $path = $this->directory.'/audit.jsonl';
        if (is_link($path) || (is_file($path) && filesize($path) > 8388608)) {
            throw new MaintenanceStateFault('audit_unavailable');
        }
        $mask = umask(0077);
        try {
            $stream = @fopen($path, 'a+b');
        } finally {
            umask($mask);
        }
        if (! $stream) {
            throw new MaintenanceStateFault('audit_unavailable');
        }
        try {
            if (! chmod($path, 0600)) {
                throw new MaintenanceStateFault('audit_permissions');
            }
            fseek($stream, 0, SEEK_END);
            $offset = ftell($stream);
            if ($offset > 0) {
                fseek($stream, -1, SEEK_END);
                if (fread($stream, 1) !== "\n") {
                    throw new MaintenanceStateFault('audit_incomplete');
                }
            }
            try {
                $this->flush($stream, json_encode($event, JSON_THROW_ON_ERROR)."\n");
            } catch (MaintenanceStateFault $exception) {
                // Remove only this attempted append while holding the writer
                // lock, never an earlier durable event. Crash remnants are
                // detected above and require explicit operator inspection.
                ftruncate($stream, $offset);
                fflush($stream);
                throw $exception;
            }
        } finally {
            fclose($stream);
        }
    }

    protected function publish(array $state): void
    {
        $temp = $this->directory.'/.state-'.bin2hex(random_bytes(12));
        $mask = umask(0077);
        try {
            $stream = @fopen($temp, 'xb');
        } finally {
            umask($mask);
        }
        if (! $stream) {
            throw new MaintenanceStateFault('state_write_failed');
        }
        try {
            if (! chmod($temp, 0600)) {
                throw new MaintenanceStateFault('state_permissions');
            }
            $this->flush($stream, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            fclose($stream);
            $stream = null;
            if (! @rename($temp, $this->directory.'/state.json')) {
                throw new MaintenanceStateFault('state_publish_failed');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function flush($stream, string $bytes): void
    {
        if (fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
            throw new MaintenanceStateFault('durable_write_failed');
        }
    }

    public static function render(string $root): string
    {
        ob_start();
        try {
            require $root.'/resources/maintenance/page.php';

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public static function response(array $state, string $root, bool $json): array
    {
        return ['status' => 503, 'headers' => ['Content-Type' => $json ? 'application/json; charset=UTF-8' : 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Retry-After' => '60',
            'X-Robots-Tag' => 'noindex, nofollow', 'X-Content-Type-Options' => 'nosniff'],
            'body' => $json ? '{"message":"Service Unavailable"}' : ($state['html'] ?? self::render($root))];
    }
}
