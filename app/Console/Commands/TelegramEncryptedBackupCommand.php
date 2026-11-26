<?php

namespace App\Console\Commands;

use App\Services\Observability\EncryptedDatabaseBackupService;
use Illuminate\Console\Command;

final class TelegramEncryptedBackupCommand extends Command
{
    protected $signature = 'novinpay:telegram-encrypted-backup {--force : Bypass hourly deduplication}';

    protected $description = 'Create an AES-256-GCM encrypted SQL database backup and upload it to the Telegram backups topic.';

    public function handle(EncryptedDatabaseBackupService $backups): int
    {
        $result = $backups->runAndSend((bool) $this->option('force'));
        $status = (string) ($result['status'] ?? 'failed');

        $this->line(sprintf(
            'Encrypted DB backup status=%s file=%s size_mb=%s telegram_sent=%s',
            $status,
            (string) ($result['file'] ?? 'none'),
            (string) ($result['size_mb'] ?? '0'),
            ! empty($result['notification_sent']) ? 'true' : 'false',
        ));

        return $status === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
