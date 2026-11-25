<?php

namespace App\Services\Observability;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BackupReporter
{
    public function __construct(
        private readonly ObservabilityMetrics $metrics,
        private readonly TelegramTopicNotifier $topics,
    ) {}

    /**
     * @param  array{status?: ?string, path?: ?string, size?: ?int, note?: ?string, force?: bool}  $options
     * @return array<string, mixed>
     */
    public function report(array $options = []): array
    {
        $maxAgeHours = max(1, (int) config('observability.backups.max_age_hours', 72));
        $targetPath = trim((string) ($options['path'] ?? config('observability.backups.path', '')));
        $explicitStatus = isset($options['status']) && $options['status'] !== '' ? strtolower(trim((string) $options['status'])) : null;
        $explicitSize = isset($options['size']) && $options['size'] !== null ? max(0, (int) $options['size']) : null;
        $note = trim((string) ($options['note'] ?? ''));

        $fileName = null;
        $sizeBytes = $explicitSize;
        $modifiedAt = null;
        $ageHours = null;

        if ($explicitStatus === null) {
            $detected = $this->inspectPath($targetPath);
            $fileName = $detected['file'];
            $sizeBytes = $sizeBytes ?? $detected['size_bytes'];
            $modifiedAt = $detected['modified_at'];
            $ageHours = $detected['age_hours'];

            if ($detected['file'] === null) {
                $status = 'missing';
            } elseif (($sizeBytes ?? 0) <= 0) {
                $status = 'failed';
            } elseif ($ageHours !== null && $ageHours > $maxAgeHours) {
                $status = 'stale';
            } else {
                $status = 'ok';
            }
        } else {
            $status = in_array($explicitStatus, ['ok', 'failed', 'stale', 'missing'], true) ? $explicitStatus : 'failed';
            if ($targetPath !== '') {
                $fileName = basename($targetPath);
                if ($sizeBytes === null && is_file($targetPath)) {
                    $sizeBytes = (int) (filesize($targetPath) ?: 0);
                }
            }
            $modifiedAt = now()->toIso8601String();
            $ageHours = 0.0;
        }

        $snapshot = [
            'status' => $status,
            'file' => $fileName,
            'size_bytes' => $sizeBytes,
            'size_mb' => $sizeBytes !== null ? round($sizeBytes / 1048576, 2) : null,
            'modified_at' => $modifiedAt,
            'age_hours' => $ageHours,
            'max_age_hours' => $maxAgeHours,
            'note' => $note !== '' ? mb_substr($note, 0, 200) : null,
            'checked_at' => now()->toIso8601String(),
        ];

        try {
            $this->metrics->storeLatestBackup($snapshot);
            Log::channel('daily')->log(
                $status === 'ok' ? 'info' : 'warning',
                'Backup status reported',
                $snapshot
            );
        } catch (Throwable) {
        }

        $icon = match ($status) {
            'ok' => '✅',
            'stale' => '⚠️',
            default => '🚨',
        };
        $statusLabel = match ($status) {
            'ok' => 'فایل بکاپ به‌روز',
            'stale' => 'قدیمی (خارج از بازه مجاز)',
            'missing' => 'بکاپ یافت نشد',
            default => 'ناموفق',
        };

        $lines = [
            "{$icon} گزارش بکاپ نوین‌پی",
            "وضعیت: {$statusLabel}",
            'فایل: '.($fileName ?: 'نامشخص'),
            'حجم: '.($snapshot['size_mb'] !== null ? $snapshot['size_mb'].' مگابایت' : 'نامشخص'),
            'سن بکاپ: '.($ageHours !== null ? round($ageHours, 1)." ساعت (سقف مجاز: {$maxAgeHours} ساعت)" : 'نامشخص'),
        ];
        if ($note !== '') {
            $lines[] = 'یادداشت: '.mb_substr($note, 0, 200);
        }

        $message = implode("\n", $lines);
        $snapshot['notification_sent'] = $this->topics->send('backups', $message,
            ($options['force'] ?? false) ? null : 'backup_report:'.$status.':'.$fileName.':'.$modifiedAt.':'.now()->format('YmdH'));

        if ($status !== 'ok') {
            $this->topics->send('errors', "🚨 هشدار بکاپ ({$statusLabel})\n{$message}", 'backup_error:'.$status.':'.now()->format('YmdH'));
        }

        $this->metrics->storeLatestBackup($snapshot);

        return $snapshot;
    }

    /**
     * @return array{file: ?string, size_bytes: ?int, modified_at: ?string, age_hours: ?float}
     */
    private function inspectPath(string $path): array
    {
        if ($path === '') {
            return ['file' => null, 'size_bytes' => null, 'modified_at' => null, 'age_hours' => null];
        }

        if (is_link($path)) {
            return ['file' => null, 'size_bytes' => null, 'modified_at' => null, 'age_hours' => null];
        }
        $specific = null;
        if (is_file($path)) {
            $specific = realpath($path);
            $path = dirname($path);
        }

        if (! is_dir($path)) {
            return ['file' => null, 'size_bytes' => null, 'modified_at' => null, 'age_hours' => null];
        }

        $latestFile = null;
        $latestMtime = -1;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        $iterator->setMaxDepth(3);
        foreach ($iterator as $entry) {
            if ($entry->isLink() || ! $entry->isFile() || ! $this->isArchive($entry->getPathname())) {
                continue;
            }
            $full = $entry->getPathname();
            if ($specific !== null && realpath($full) !== $specific) {
                continue;
            }
            $mtime = $entry->getMTime();
            // A copied archive's mtime can be newer than its actual snapshot.
            if (basename($full) === 'backup.npb') {
                $receiptPath = dirname($full).DIRECTORY_SEPARATOR.'receipt.json';
                if (! is_file($receiptPath) || is_link($receiptPath) || filesize($receiptPath) > 65536) {
                    continue;
                }
                $receipt = json_decode((string) file_get_contents($receiptPath), true);
                if (($receipt['result'] ?? null) !== 'BACKUP_AND_DISPOSABLE_RESTORE_PASS'
                    || ($receipt['archiveBytes'] ?? null) !== $entry->getSize()
                    || ! is_int($receipt['snapshotUnix'] ?? null) || $receipt['snapshotUnix'] > now()->timestamp + 5) {
                    continue;
                }
                $mtime = $receipt['snapshotUnix'];
            }
            if ($mtime > $latestMtime) {
                $latestMtime = $mtime;
                $latestFile = $full;
            }
        }

        if ($latestFile === null) {
            return ['file' => null, 'size_bytes' => null, 'modified_at' => null, 'age_hours' => null];
        }

        $carbon = Carbon::createFromTimestamp($latestMtime);

        return [
            'file' => basename($latestFile),
            'size_bytes' => (int) (filesize($latestFile) ?: 0),
            'modified_at' => $carbon->toIso8601String(),
            'age_hours' => round($carbon->diffInMinutes(now()) / 60, 2),
        ];
    }

    private function isArchive(string $path): bool
    {
        return preg_match('/(?:\.npb|\.sql(?:\.gz)?|\.(?:tar|tar\.gz|tgz|zip)(?:\.enc)?|\.sql\.gz\.enc)\z/i', $path) === 1;
    }
}
