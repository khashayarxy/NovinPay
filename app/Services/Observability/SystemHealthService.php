<?php

namespace App\Services\Observability;

use App\Services\OperationalReadiness;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SystemHealthService
{
    public const CHANNELS = [
        'daily' => ['prefix' => 'laravel', 'label' => 'خطاهای عمومی برنامه (daily)'],
        'payments' => ['prefix' => 'payments', 'label' => 'پرداخت‌ها و زیبال (payments)'],
        'sms' => ['prefix' => 'sms', 'label' => 'پیامک‌ها (sms)'],
        'frontend' => ['prefix' => 'frontend', 'label' => 'خطاهای مرورگر (frontend)'],
        'performance' => ['prefix' => 'performance', 'label' => 'عملکرد و کوئری‌های کند (performance)'],
    ];

    public const LEVELS = ['ALL', 'CRITICAL', 'ERROR', 'WARNING', 'INFO'];

    public function __construct(
        private readonly OperationalReadiness $readiness,
        private readonly ObservabilityMetrics $metrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function checks(): array
    {
        $db = $this->checkDatabase();
        $migrations = $this->checkPendingMigrations($db['ok']);
        $writable = $this->checkWritablePaths();
        $cache = $this->checkCache();
        $disk = $this->checkDiskSpace();
        $scheduler = $this->checkScheduler();
        $backup = $this->metrics->latestBackup();

        return [
            'database' => $db,
            'migrations' => $migrations,
            'writable' => $writable,
            'cache' => $cache,
            'disk' => $disk,
            'scheduler' => $scheduler,
            'backup' => $backup,
            'metrics' => $this->metrics->summary(),
            'sms' => $this->metrics->smsBreakdown(),
            'telegram_topics_enabled' => (bool) config('observability.telegram_topics.enabled'),
            'client_telemetry_enabled' => (bool) config('observability.client_telemetry.enabled'),
            'php_version' => PHP_VERSION,
            'app_env' => (string) config('app.env'),
        ];
    }

    /**
     * @return array{channel: string, level: string, file: ?string, entries: array<int, array{timestamp: string, level: string, message: string}>, channels: array<string, array{prefix: string, label: string}>}
     */
    public function recentLogs(string $channel = 'daily', ?string $level = null, int $limit = 80): array
    {
        $channel = array_key_exists($channel, self::CHANNELS) ? $channel : 'daily';
        $levelFilter = strtoupper(trim((string) $level));
        if (! in_array($levelFilter, self::LEVELS, true)) {
            $levelFilter = 'ALL';
        }
        $limit = max(10, min(200, $limit));

        $file = $this->resolveLatestLogFile(self::CHANNELS[$channel]['prefix']);
        if ($file === null || ! is_readable($file)) {
            return [
                'channel' => $channel,
                'level' => $levelFilter,
                'file' => null,
                'entries' => [],
                'channels' => self::CHANNELS,
            ];
        }

        $tail = $this->readFileTail($file, 262144);
        $entries = $this->parseEntries($tail, $levelFilter, $limit);

        return [
            'channel' => $channel,
            'level' => $levelFilter,
            'file' => basename($file),
            'entries' => $entries,
            'channels' => self::CHANNELS,
        ];
    }

    public function redactLogText(string $text): string
    {
        $clean = TelegramTopicNotifier::redact($text);
        $clean = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $clean) ?? $clean;
        $clean = preg_replace('/\b09\d{5}(\d{4})\b/', '09•••••$1', $clean) ?? $clean;
        $clean = preg_replace('/\b(?:\+98|0098)9\d{5}(\d{4})\b/', '+989•••••$1', $clean) ?? $clean;
        $clean = preg_replace('/\b(APP_KEY|DB_PASSWORD|TELEGRAM_BOT_TOKEN|IPPANEL_API_KEY|ZIBAL_MERCHANT|PASSWORD)\s*[:=]\s*\S+/i', '$1=[REDACTED]', $clean) ?? $clean;

        return $clean;
    }

    /**
     * @return array{ok: bool, driver: string, latency_ms: ?int, error: ?string}
     */
    private function checkDatabase(): array
    {
        $driver = (string) DB::getDefaultConnection();
        $started = microtime(true);
        try {
            DB::select('SELECT 1');

            return [
                'ok' => true,
                'driver' => $driver,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'driver' => $driver,
                'latency_ms' => null,
                'error' => class_basename($e),
            ];
        }
    }

    /**
     * @return array{ok: bool, pending: ?int, total_files: int}
     */
    private function checkPendingMigrations(bool $dbOk): array
    {
        $files = glob(database_path('migrations/*.php')) ?: [];
        $names = array_map(static fn (string $path): string => basename($path, '.php'), $files);
        if (! $dbOk) {
            return ['ok' => false, 'pending' => null, 'total_files' => count($names)];
        }

        try {
            $ran = DB::table('migrations')->pluck('migration')->map(static fn ($m) => (string) $m)->all();
            $pending = array_diff($names, $ran);

            return [
                'ok' => count($pending) === 0,
                'pending' => count($pending),
                'total_files' => count($names),
            ];
        } catch (Throwable) {
            return ['ok' => false, 'pending' => null, 'total_files' => count($names)];
        }
    }

    /**
     * @return array{ok: bool, paths: array<string, bool>}
     */
    private function checkWritablePaths(): array
    {
        $targets = [
            'storage/logs' => storage_path('logs'),
            'storage/framework' => storage_path('framework'),
            'storage/framework/cache' => storage_path('framework/cache'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];
        $results = [];
        $allOk = true;
        foreach ($targets as $label => $path) {
            $writable = is_dir($path) && is_writable($path);
            $results[$label] = $writable;
            if (! $writable) {
                $allOk = false;
            }
        }

        return ['ok' => $allOk, 'paths' => $results];
    }

    /**
     * @return array{ok: bool, store: string}
     */
    private function checkCache(): array
    {
        $store = (string) config('cache.default');
        $key = 'obs:health:probe:'.bin2hex(random_bytes(4));
        try {
            Cache::put($key, 'ok', 15);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);

            return ['ok' => $ok, 'store' => $store];
        } catch (Throwable) {
            return ['ok' => false, 'store' => $store];
        }
    }

    /**
     * @return array{ok: bool, free_gb: ?float, total_gb: ?float, free_percent: ?float}
     */
    private function checkDiskSpace(): array
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return ['ok' => false, 'free_gb' => null, 'total_gb' => null, 'free_percent' => null];
        }

        $freeGb = round($free / 1073741824, 2);
        $totalGb = round($total / 1073741824, 2);
        $freePercent = round(($free / $total) * 100, 1);

        return [
            'ok' => $freePercent >= 10.0 && $freeGb >= 0.5,
            'free_gb' => $freeGb,
            'total_gb' => $totalGb,
            'free_percent' => $freePercent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkScheduler(): array
    {
        try {
            return $this->readiness->status();
        } catch (Throwable) {
            return [
                'scheduler_recent' => false,
                'scheduler_last_seen' => null,
                'stale_payment_attempts' => 0,
                'stale_notifications' => 0,
            ];
        }
    }

    private function resolveLatestLogFile(string $prefix): ?string
    {
        $dir = storage_path('logs');
        if (! is_dir($dir)) {
            return null;
        }

        $dated = glob($dir.DIRECTORY_SEPARATOR.$prefix.'-????-??-??.log') ?: [];
        if ($dated !== []) {
            rsort($dated, SORT_STRING);

            return $dated[0];
        }

        $plain = $dir.DIRECTORY_SEPARATOR.$prefix.'.log';

        return is_file($plain) ? $plain : null;
    }

    private function readFileTail(string $path, int $maxBytes): string
    {
        $size = @filesize($path);
        if (! is_int($size) || $size <= 0) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            if ($size > $maxBytes) {
                fseek($handle, -$maxBytes, SEEK_END);
                fgets($handle);
            }

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<int, array{timestamp: string, level: string, message: string}>
     */
    private function parseEntries(string $raw, string $levelFilter, int $limit): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $parsed = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}[^\]]*)\]\s+[^.]+\.([A-Z]+):\s*(.*)$/', $trimmed, $m)) {
                $entryLevel = strtoupper($m[2]);
                $parsed[] = [
                    'timestamp' => $m[1],
                    'level' => $entryLevel,
                    'message' => mb_substr($this->redactLogText($m[3]), 0, 500),
                ];
            }
        }

        if ($levelFilter !== 'ALL') {
            $parsed = array_values(array_filter(
                $parsed,
                static fn (array $entry): bool => $entry['level'] === $levelFilter
            ));
        }

        return array_slice(array_reverse($parsed), 0, $limit);
    }
}
