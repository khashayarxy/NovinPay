<?php

namespace App\Console\Commands;

use App\Services\Observability\BackupReporter;
use Illuminate\Console\Command;

final class TelegramBackupReportCommand extends Command
{
    protected $signature = 'novinpay:telegram-backup-report
        {--status= : Explicit backup status (ok|failed|stale|missing)}
        {--path= : Explicit backup file or directory path}
        {--size= : Explicit backup size in bytes}
        {--note= : Optional summary note}
        {--force : Send a fresh report even within the report deduplication window}';

    protected $description = 'Report latest backup status to the Telegram backups topic and alert errors on failure or staleness.';

    public function handle(BackupReporter $reporter): int
    {
        $sizeOption = $this->option('size');
        $snapshot = $reporter->report([
            'status' => $this->option('status') ? (string) $this->option('status') : null,
            'path' => $this->option('path') ? (string) $this->option('path') : null,
            'size' => is_numeric($sizeOption) ? (int) $sizeOption : null,
            'note' => $this->option('note') ? (string) $this->option('note') : null,
            'force' => (bool) $this->option('force'),
        ]);

        $this->line(sprintf(
            'Backup status: %s | file: %s | size_mb: %s',
            $snapshot['status'],
            $snapshot['file'] ?? 'none',
            $snapshot['size_mb'] ?? 'n/a',
        ));
        $this->line($snapshot['notification_sent'] ? 'Telegram backups topic: sent' : 'Telegram backups topic: not sent (configuration, duplicate, or delivery failure).');

        return $snapshot['status'] === 'ok' && $snapshot['notification_sent'] ? self::SUCCESS : self::FAILURE;
    }
}
