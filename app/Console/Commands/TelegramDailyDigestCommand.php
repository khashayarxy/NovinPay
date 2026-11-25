<?php

namespace App\Console\Commands;

use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use App\Services\Observability\ObservabilityMetrics;
use App\Services\Observability\TelegramTopicNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class TelegramDailyDigestCommand extends Command
{
    protected $signature = 'novinpay:telegram-daily-digest {--date= : Target date in Y-m-d format}';

    protected $description = 'Send the consolidated 24-hour operational digest to the Telegram digest topic.';

    public function handle(ObservabilityMetrics $metrics, TelegramTopicNotifier $topics): int
    {
        $dateOption = $this->option('date');
        $target = is_string($dateOption) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $dateOption)
            ? Carbon::parse($dateOption)
            : now();
        $dateKey = $target->format('Y-m-d');
        $start = $target->copy()->startOfDay();
        $end = $target->copy()->endOfDay();

        $settledPayments = PaymentAttempt::query()
            ->where('state', 'settled')
            ->whereBetween('verified_at', [$start, $end]);
        $settledCount = (clone $settledPayments)->count();
        $revenueMinor = (int) (clone $settledPayments)->sum('amount_minor');
        $revenueToman = intdiv($revenueMinor, 100);
        $failedPayments = PaymentAttempt::query()
            ->where('state', 'failed')
            ->whereBetween('updated_at', [$start, $end])
            ->count();
        $reviewPayments = PaymentAttempt::query()
            ->where('state', 'review')
            ->whereBetween('updated_at', [$start, $end])
            ->count();

        $newOrders = SalesOrder::query()->whereBetween('created_at', [$start, $end])->count();
        $newRepairs = RepairRequest::query()->whereBetween('created_at', [$start, $end])->count();
        $openRepairCases = RepairCase::query()->whereNotIn('status', ['delivered', 'returned'])->count();
        $newUsers = CustomerAccount::query()->whereBetween('created_at', [$start, $end])->count();

        $inventory = InventoryItem::query()->get(['on_hand', 'reserved', 'low_stock_threshold']);
        $outOfStock = $inventory->filter(fn (InventoryItem $i) => max(0, (int) $i->on_hand - (int) $i->reserved) <= 0)->count();
        $lowStock = $inventory->filter(function (InventoryItem $i): bool {
            $avail = max(0, (int) $i->on_hand - (int) $i->reserved);

            return $avail > 0 && $avail <= (int) $i->low_stock_threshold;
        })->count();

        $summary = $metrics->summary($dateKey);
        $sms = $metrics->smsBreakdown($dateKey);
        $backup = $metrics->latestBackup();
        $backupStatus = match ($backup['status'] ?? null) {
            'ok' => 'به‌روز',
            'stale' => 'خارج از بازهٔ مجاز',
            'missing' => 'یافت نشد',
            'failed' => 'ناموفق',
            default => 'ثبت نشده',
        };

        $lines = [
            "📊 گزارش جمع‌بندی روزانه نوین‌پی ({$dateKey})",
            '',
            '💳 پرداخت‌ها:',
            "• موفق: {$settledCount} مورد (".number_format($revenueToman).' تومان)',
            "• ناموفق: {$failedPayments} | نیازمند بررسی: {$reviewPayments}",
            '',
            '🛒 سفارش‌ها و انبار:',
            "• سفارش جدید: {$newOrders}",
            "• ناموجود: {$outOfStock} کالا | موجودی کم: {$lowStock} کالا",
            '',
            '🛠️ تعمیرات و کاربران:',
            "• درخواست تعمیر جدید: {$newRepairs} | پرونده‌های باز: {$openRepairCases}",
            "• کاربر جدید: {$newUsers} | ورودها: {$summary['user_logins']}",
            '',
            '📨 پیامک‌ها:',
            "• کل: {$sms['total']} (ناموفق: {$sms['failed']})",
            "• کد تأیید: {$sms['otp']} | سفارش: {$sms['order']} | تعمیر: {$sms['repair']} | هشدار: {$sms['alert']} | سایر: {$sms['other']}",
            '',
            '🛡️ سلامت و خطاها:',
            "• خطای سرور (500): {$summary['http_500']} | خطای JS کلاینت: {$summary['client_js']}",
            "• درخواست کند: {$summary['slow_requests']} | کوئری کند: {$summary['slow_queries']}",
            "• وضعیت آخرین بکاپ: {$backupStatus}",
        ];

        $text = implode("\n", $lines);
        $sent = $topics->send('digest', $text, "daily_digest:{$dateKey}", 60);

        $this->line("Daily digest generated for {$dateKey} (telegram_sent=".($sent ? 'true' : 'false').').');

        return self::SUCCESS;
    }
}
