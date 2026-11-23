<?php

namespace App\Console\Commands;

use App\Services\Notifications\BaleNotifier;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Console\Command;

class NotifyTestCommand extends Command
{
    protected $signature = 'novinpay:notify-test {--channel=telegram : Channel to test (telegram, bale, or all)}';

    protected $description = 'Send a safe test notification to Telegram and/or Bale without creating fake orders or database writes.';

    public function handle(TelegramNotifier $telegram, BaleNotifier $bale): int
    {
        $channel = strtolower(trim((string) $this->option('channel')));
        if (! in_array($channel, ['telegram', 'bale', 'all'], true)) {
            $this->error('Invalid --channel option. Use telegram, bale, or all.');

            return self::FAILURE;
        }

        $message = "✅ تست اتصال اعلان‌های نوین‌پی\n"
            .'محیط: '.config('app.env')."\n"
            .'زمان (UTC): '.now('UTC')->format('Y-m-d H:i:s');

        $targets = $channel === 'all' ? ['telegram', 'bale'] : [$channel];
        $allOk = true;

        foreach ($targets as $target) {
            $driver = $target === 'telegram' ? $telegram : $bale;
            if (! $driver->enabled()) {
                $this->warn(sprintf('[%s] Channel is not enabled or missing bot_token/chat_id.', $target));
                $allOk = false;

                continue;
            }

            if ($driver->send($message)) {
                $this->info(sprintf('[%s] Test notification delivered successfully.', $target));
            } else {
                $this->error(sprintf('[%s] Failed to deliver test notification. Check storage/logs/laravel.log.', $target));
                $allOk = false;
            }
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }
}
