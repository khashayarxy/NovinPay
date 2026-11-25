<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\SalesOrder;
use App\Services\Observability\TelegramTopicNotifier;
use Illuminate\Console\Command;

final class TelegramSalesInventoryReportCommand extends Command
{
    protected $signature = 'novinpay:telegram-sales-inventory-report {--hours=24 : Rolling window in hours}';

    protected $description = 'Send periodic sales and inventory summary reports to the orders and inventory Telegram topics.';

    public function handle(TelegramTopicNotifier $topics): int
    {
        $hours = max(1, min(720, (int) $this->option('hours')));
        $since = now()->subHours($hours);

        $ordersQuery = SalesOrder::query()->where('created_at', '>=', $since);
        $ordersCount = (clone $ordersQuery)->count();
        $confirmedOrders = (clone $ordersQuery)->whereIn('status', ['confirmed', 'fulfilled'])->count();
        $cancelledOrders = (clone $ordersQuery)->where('status', 'cancelled')->count();

        $settledPayments = PaymentAttempt::query()
            ->where('state', 'settled')
            ->where('verified_at', '>=', $since);
        $paidCount = (clone $settledPayments)->count();
        $revenueToman = intdiv((int) (clone $settledPayments)->sum('amount_minor'), 100);

        $salesLines = [
            "📈 گزارش دوره‌ای فروش ({$hours} ساعت اخیر)",
            "• کل سفارش‌ها: {$ordersCount}",
            "• تأیید/تکمیل‌شده: {$confirmedOrders} | لغوشده: {$cancelledOrders}",
            "• پرداخت‌های تسویه‌شده: {$paidCount}",
            '• مجموع درآمد: '.number_format($revenueToman).' تومان',
        ];
        $topics->send('orders', implode("\n", $salesLines), "sales_report:{$hours}:".now()->format('YmdH'), 60);

        $items = InventoryItem::query()->orderBy('name')->get(['sku', 'name', 'on_hand', 'reserved', 'low_stock_threshold']);
        $totalSkus = $items->count();
        $outOfStock = $items->filter(fn (InventoryItem $i) => max(0, (int) $i->on_hand - (int) $i->reserved) <= 0);
        $lowStock = $items->filter(function (InventoryItem $i): bool {
            $avail = max(0, (int) $i->on_hand - (int) $i->reserved);

            return $avail > 0 && $avail <= (int) $i->low_stock_threshold;
        });

        $inventoryLines = [
            '📦 گزارش دوره‌ای وضعیت موجودی انبار',
            "• کل کالاهای ثبت‌شده (SKU): {$totalSkus}",
            '• 🔴 ناموجود: '.$outOfStock->count().' کالا',
            '• 🟡 موجودی کم: '.$lowStock->count().' کالا',
        ];

        $flagged = $outOfStock->concat($lowStock)->take(8);
        if ($flagged->isNotEmpty()) {
            $inventoryLines[] = '';
            $inventoryLines[] = 'کالاهای نیازمند توجه:';
            foreach ($flagged as $item) {
                $avail = max(0, (int) $item->on_hand - (int) $item->reserved);
                $badge = $avail <= 0 ? '🔴' : '🟡';
                $inventoryLines[] = "{$badge} {$item->name} ({$item->sku}) — آزاد: {$avail}";
            }
        }

        $topics->send('inventory', implode("\n", $inventoryLines), 'inventory_report:'.now()->format('YmdH'), 60);

        $this->line("Sales & inventory report completed (orders={$ordersCount}, skus={$totalSkus}, oos={$outOfStock->count()}, low={$lowStock->count()}).");

        return self::SUCCESS;
    }
}
