<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PerformanceAlerts
{
    public function record(string $metric, string $message, array $context): void
    {
        try {
            Log::channel('performance')->warning($message, $context);
            app(ObservabilityMetrics::class)->increment('performance.slow_'.$metric);

            if (! app(TelegramTopicNotifier::class)->enabledFor('performance')) {
                return;
            }

            $directory = $this->directory();
            File::ensureDirectoryExists($directory, 0700, true);
            $path = $directory.'/'.bin2hex(random_bytes(16));
            $payload = json_encode(['metric' => $metric, 'context' => $context], JSON_THROW_ON_ERROR);
            if (file_put_contents($path.'.tmp', $payload) !== strlen($payload) || ! rename($path.'.tmp', $path.'.json')) {
                throw new \RuntimeException('Could not save alert');
            }
        } catch (Throwable $error) {
            try {
                Log::warning('Performance alert recording failed', ['exception' => $error::class]);
            } catch (Throwable) {
                // Monitoring must never determine the response.
            }
        }
    }

    public function deliver(): bool
    {
        $telegram = app(TelegramTopicNotifier::class);
        if (! $telegram->enabledFor('performance') || ! is_dir($this->directory())) {
            return true;
        }

        $lock = fopen($this->directory().'/delivery.lock', 'c');
        if (! $lock) {
            return false;
        }

        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                return true;
            }

            $paths = array_slice(glob($this->directory().'/*.json') ?: [], 0, 15);
            if ($paths === []) {
                return true;
            }
            $lines = [];
            foreach ($paths as $path) {
                $alert = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $context = $alert['context'];
                $lines[] = $alert['metric'] === 'requests'
                    ? "{$context['method']} {$context['path']} | {$context['duration_ms']}ms | HTTP {$context['status']} | {$context['incident_id']}"
                    : "SQL {$context['connection']} | {$context['duration_ms']}ms | {$context['path']} | {$context['incident_id']}";
            }
            if (! $telegram->send('performance', "⏱ رخدادهای کند نوین‌پی\n".implode("\n", $lines))) {
                return false;
            }

            return File::delete($paths);
        } finally {
            fclose($lock);
        }
    }

    private function directory(): string
    {
        return (string) config('observability.performance.outbox_path', storage_path('app/private/performance-alerts'));
    }
}
