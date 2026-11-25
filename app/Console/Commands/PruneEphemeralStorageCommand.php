<?php

namespace App\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

final class PruneEphemeralStorageCommand extends Command
{
    protected $signature = 'novinpay:prune-ephemeral {--max-files=5000 : Maximum files inspected per run}';

    protected $description = 'Prune expired session and file-cache artifacts out-of-band without blocking HTTP requests';

    public function handle(): int
    {
        $maxFiles = max(100, min(50000, (int) $this->option('max-files')));
        $now = now()->getTimestamp();

        $sessionsPruned = $this->pruneSessions($now, $maxFiles);
        $cachePruned = $this->pruneFileCache($now, $maxFiles);

        $this->line(json_encode([
            'sessions_pruned' => $sessionsPruned,
            'cache_files_pruned' => $cachePruned,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function pruneSessions(int $now, int $maxFiles): int
    {
        $driver = (string) config('session.driver');
        $lifetimeSeconds = max(60, ((int) config('session.lifetime', 120)) * 60);
        $cutoff = $now - $lifetimeSeconds;

        if ($driver === 'file') {
            $dir = (string) config('session.files', storage_path('framework/sessions'));
            if ($dir === '' || ! is_dir($dir) || is_link($dir)) {
                return 0;
            }

            $pruned = 0;
            $inspected = 0;
            foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $entry) {
                if (! $entry instanceof SplFileInfo || $entry->isLink() || ! $entry->isFile()) {
                    continue;
                }
                if (str_starts_with($entry->getFilename(), '.')) {
                    continue;
                }
                $inspected++;
                if ($entry->getMTime() <= $cutoff && @unlink($entry->getPathname())) {
                    $pruned++;
                }
                if ($inspected >= $maxFiles) {
                    break;
                }
            }

            return $pruned;
        }

        if ($driver === 'database') {
            $connection = config('session.connection');
            $table = (string) config('session.table', 'sessions');
            $pruned = 0;
            try {
                // Bounded primary-key batches avoid wide range/gap locks if DB sessions are ever used.
                for ($batch = 0; $batch < 5; $batch++) {
                    $ids = DB::connection($connection)
                        ->table($table)
                        ->where('last_activity', '<=', $cutoff)
                        ->limit(250)
                        ->pluck('id')
                        ->all();
                    if ($ids === []) {
                        break;
                    }
                    $pruned += DB::connection($connection)->table($table)->whereIn('id', $ids)->delete();
                    if (count($ids) < 250) {
                        break;
                    }
                }
            } catch (Throwable) {
                return $pruned;
            }

            return $pruned;
        }

        return 0;
    }

    private function pruneFileCache(int $now, int $maxFiles): int
    {
        $paths = array_values(array_unique(array_filter([
            config('cache.stores.file.path'),
            config('cache.stores.file.lock_path'),
        ], static fn ($path): bool => is_string($path) && $path !== '' && is_dir($path) && ! is_link($path))));

        $pruned = 0;
        $inspected = 0;

        foreach ($paths as $root) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $entry) {
                if (! $entry instanceof SplFileInfo || $entry->isLink()) {
                    continue;
                }
                if ($entry->isDir()) {
                    $dirPath = $entry->getPathname();
                    if ($dirPath !== $root) {
                        @rmdir($dirPath);
                    }

                    continue;
                }
                if (! $entry->isFile() || str_starts_with($entry->getFilename(), '.')) {
                    continue;
                }

                $inspected++;
                if ($this->isExpiredCacheFile($entry->getPathname(), $now) && @unlink($entry->getPathname())) {
                    $pruned++;
                }
                if ($inspected >= $maxFiles) {
                    return $pruned;
                }
            }
        }

        return $pruned;
    }

    private function isExpiredCacheFile(string $path, int $now): bool
    {
        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            return false;
        }
        try {
            $header = @fread($handle, 10);
        } finally {
            fclose($handle);
        }

        if (! is_string($header) || strlen($header) !== 10 || ! ctype_digit($header)) {
            return false;
        }

        $expiresAt = (int) $header;

        return $expiresAt > 0 && $expiresAt !== 9999999999 && $expiresAt <= $now;
    }
}
