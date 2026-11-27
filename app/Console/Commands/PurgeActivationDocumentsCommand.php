<?php

namespace App\Console\Commands;

use App\Services\ActivationDocumentsService;
use Illuminate\Console\Command;

final class PurgeActivationDocumentsCommand extends Command
{
    protected $signature = 'novinpay:purge-activation-documents';

    protected $description = 'Automatically delete expired or cancelled order activation document ZIPs from Telegram and private staging.';

    public function handle(ActivationDocumentsService $service): int
    {
        $purged = $service->purgeExpiredDocuments();
        $this->line(sprintf('Activation documents auto-purge completed: purged=%d', $purged));

        return self::SUCCESS;
    }
}
