<?php

namespace App\Console\Commands;

use App\Models\Consultation;
use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\ProductFeedback;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use App\Services\Notifications\BaleNotifier;
use App\Services\Notifications\UnifiedNotifier;
use App\Services\Observability\ObservabilityMetrics;
use App\Services\Observability\TelegramTopicNotifier;
use App\Support\FaNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class TelegramDailyDigestCommand extends Command
{
    protected $signature = 'novinpay:telegram-daily-digest
        {--date= : Target date in Y-m-d format (or "yesterday")}
        {--if-unsent : Skip if a digest was already delivered for the target date}';

    protected $description = 'Send the consolidated 24-hour operational digest to the Telegram digest topic (and Bale if enabled).';

    public function handle(ObservabilityMetrics $metrics, TelegramTopicNotifier $topics, BaleNotifier $bale): int
    {
        $dateOption = trim((string) $this->option('date'));
        if ($dateOption === 'yesterday') {
            $target = Carbon::now('Asia/Tehran')->subDay();
        } elseif ($dateOption !== '' && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $dateOption)) {
            $target = Carbon::createFromFormat('Y-m-d', $dateOption, 'Asia/Tehran');
        } else {
            $target = Carbon::now('Asia/Tehran');
        }

        $dateKey = $target->format('Y-m-d');
        $sentMarkerKey = 'novinpay:daily_digest_sent:'.$dateKey;

        if ($this->option('if-unsent')) {
            try {
                if (Cache::get($sentMarkerKey) === true) {
                    $this->line("Daily digest already sent for {$dateKey}; skipping.");

                    return self::SUCCESS;
                }
            } catch (Throwable) {
            }
        }

        $appTz = (string) config('app.timezone', 'UTC');
        $start = $target->copy()->startOfDay()->setTimezone($appTz);
        $end = $target->copy()->endOfDay()->setTimezone($appTz);

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
        $newConsultations = Consultation::query()->whereBetween('created_at', [$start, $end])->count();
        $newReviews = ProductFeedback::query()->whereBetween('created_at', [$start, $end])->count();
        $pendingReviews = ProductFeedback::query()->where('status', 'pending')->count();

        $defaultThreshold = (int) config('observability.inventory.low_stock_threshold', 3);
        $inventory = InventoryItem::query()->get(['on_hand', 'reserved', 'low_stock_threshold']);
        $outOfStock = $inventory->filter(fn (InventoryItem $i) => max(0, (int) $i->on_hand - (int) $i->reserved) <= 0)->count();
        $lowStock = $inventory->filter(function (InventoryItem $i) use ($defaultThreshold): bool {
            $avail = max(0, (int) $i->on_hand - (int) $i->reserved);
            $threshold = (int) $i->low_stock_threshold > 0 ? (int) $i->low_stock_threshold : $defaultThreshold;

            return $avail > 0 && $avail <= $threshold;
        })->count();

        $summary = $metrics->summary($dateKey);
        $sms = $metrics->smsBreakdown($dateKey);
        $chatMessages = $metrics->get('support_chat.messages', $dateKey);
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
            '🛠️ تعمیرات، مشاوره و دیدگاه‌ها:',
            "• درخواست تعمیر جدید: {$newRepairs} | پرونده‌های باز: {$openRepairCases}",
            "• مشاوره جدید: {$newConsultations} | پیام چت پشتیبانی: {$chatMessages}",
            "• دیدگاه جدید: {$newReviews} (در انتظار تأیید: {$pendingReviews})",
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
        $baseAdminUrl = rtrim((string) config('app.url', 'https://novinpaypos.com'), '/');
        $settledFa = FaNumber::digits((string) $settledCount).' مورد';
        $repairsFa = FaNumber::digits((string) $newRepairs).' مورد';
        $consultsFa = FaNumber::digits((string) $newConsultations).' مورد';
        $revenueFa = FaNumber::digits(number_format($revenueToman)).' تومان';

        $digestHeader = "📊 گزارش جمع‌بندی روزانه نوین‌پی ({$dateKey})\n"
            .'خلاصهٔ شاخص‌های کلیدی فروش، تعمیرات، مشاوره و وضعیت بکاپ:';
        $digestTableKeyboard = [
            [
                ['text' => $settledFa, 'url' => $baseAdminUrl.'/admin/orders'],
                ['text' => '🛒 تعداد سفارش‌های موفق امروز', 'url' => $baseAdminUrl.'/admin/orders'],
            ],
            [
                ['text' => $repairsFa, 'url' => $baseAdminUrl.'/admin/repair-requests'],
                ['text' => '🛠️ تعداد درخواست‌های تعمیر جدید', 'url' => $baseAdminUrl.'/admin/repair-requests'],
            ],
            [
                ['text' => $consultsFa, 'url' => $baseAdminUrl.'/admin/consultations'],
                ['text' => '💬 تعداد درخواست‌های مشاوره / تماس جدید', 'url' => $baseAdminUrl.'/admin/consultations'],
            ],
            [
                ['text' => $revenueFa, 'url' => $baseAdminUrl.'/admin/orders'],
                ['text' => '💳 مجموع فروش آنلاین (زیبال)', 'url' => $baseAdminUrl.'/admin/orders'],
            ],
            [
                ['text' => $backupStatus, 'url' => $baseAdminUrl.'/admin/system-health'],
                ['text' => '🔐 وضعیت آخرین بکاپ دیتابیس', 'url' => $baseAdminUrl.'/admin/system-health'],
            ],
        ];

        $sentTelegram = $topics->sendWithKeyboard('digest', $digestHeader, $digestTableKeyboard, "daily_digest:{$dateKey}", 60);
        $sentBale = $bale->send($text, "daily_digest:{$dateKey}", 60);

        $emailFields = [
            'تاریخ گزارش' => $dateKey,
            'پرداخت‌های موفق' => "{$settledCount} مورد (".number_format($revenueToman).' تومان)',
            'پرداخت‌های ناموفق / نیازمند بررسی' => "ناموفق: {$failedPayments} | نیازمند بررسی: {$reviewPayments}",
            'سفارش‌های جدید' => "{$newOrders} مورد",
            'وضعیت موجودی انبار' => "ناموجود: {$outOfStock} کالا | موجودی کم: {$lowStock} کالا",
            'تعمیرات' => "درخواست جدید: {$newRepairs} | پرونده‌های باز: {$openRepairCases}",
            'مشاوره و چت پشتیبانی' => "مشاوره جدید: {$newConsultations} | پیام چت: {$chatMessages}",
            'دیدگاه‌ها و کاربران' => "دیدگاه جدید: {$newReviews} (منتظر تأیید: {$pendingReviews}) | کاربر جدید: {$newUsers}",
            'پیامک‌ها' => "کل: {$sms['total']} (ناموفق: {$sms['failed']})",
            'سلامت سرور (۲۴ ساعت)' => "خطای ۵۰۰: {$summary['http_500']} | درخواست کند: {$summary['slow_requests']}",
            'وضعیت آخرین بکاپ' => $backupStatus,
        ];
        $hasAttention = $reviewPayments > 0 || $summary['http_500'] > 0 || in_array($backup['status'] ?? null, ['failed', 'missing', 'stale'], true);
        $sentEmail = false;
        try {
            $sentEmail = app(UnifiedNotifier::class)->dailyDigestEmail($dateKey, $emailFields, $hasAttention);
        } catch (Throwable) {
        }

        $delivered = $sentTelegram || $sentBale || $sentEmail;

        if ($delivered) {
            try {
                Cache::put($sentMarkerKey, true, now()->addDays(3));
            } catch (Throwable) {
            }
        }

        $this->line("Daily digest generated for {$dateKey} (telegram_sent=".($sentTelegram ? 'true' : 'false').', bale_sent='.($sentBale ? 'true' : 'false').', email_sent='.($sentEmail ? 'true' : 'false').').');

        return self::SUCCESS;
    }
}
