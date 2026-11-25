<?php

namespace App\Services\Observability;

use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ObservabilityEvents
{
    public function __construct(
        private readonly ObservabilityMetrics $metrics,
        private readonly TelegramTopicNotifier $topics,
    ) {}

    public function payment(string $stage, PaymentAttempt $attempt, ?string $note = null): void
    {
        try {
            $toman = intdiv((int) $attempt->amount_minor, 10);
            $shortId = strtoupper(substr((string) $attempt->public_id, 0, 8));
            $track = $attempt->track_id ?: '—';

            $level = in_array($stage, ['failed', 'review', 'mismatch', 'rejected', 'uncertain'], true)
                ? 'warning'
                : 'info';

            Log::channel('payments')->log($level, 'Payment lifecycle event', [
                'stage' => $stage,
                'attempt' => $shortId,
                'state' => $attempt->state,
                'amount_toman' => $toman,
                'track_id' => $attempt->track_id,
                'note' => $note,
            ]);

            $this->metrics->increment("payments.{$stage}");

            $label = match ($stage) {
                'started' => '💳 شروع پرداخت آنلاین',
                'settled' => '✅ پرداخت موفق و تسویه‌شده',
                'canceled', 'cancelled' => '⚪ انصراف از پرداخت',
                'failed' => '❌ پرداخت ناموفق',
                'retry' => '🔁 تلاش مجدد برای پرداخت',
                'review', 'mismatch' => '⚠️ مغایرت / نیازمند بررسی پرداخت',
                'uncertain' => '⏳ وضعیت نامشخص درگاه',
                default => "💳 رویداد پرداخت ({$stage})",
            };

            $lines = [
                $label,
                "شناسه تلاش: #{$shortId}",
                'مبلغ: '.number_format($toman).' تومان',
                "وضعیت: {$attempt->state}",
                "کد رهگیری زیبال: {$track}",
            ];
            if ($note) {
                $lines[] = 'جزئیات: '.$note;
            }

            $this->topics->send('payments', implode("\n", $lines), "pay:{$stage}:{$shortId}");
        } catch (Throwable) {
        }
    }

    public function orderCreated(SalesOrder $order): void
    {
        try {
            $shortId = strtoupper(substr((string) $order->public_id, 0, 8));
            $total = number_format((int) round((float) $order->total));
            $this->metrics->increment('orders.created');

            $this->topics->send(
                'orders',
                "🛒 سفارش جدید ثبت شد\nکد سفارش: #{$shortId}\nمبلغ کل: {$total} تومان\nوضعیت: {$order->status}",
                "order_created:{$shortId}",
            );
        } catch (Throwable) {
        }
    }

    public function orderStatusChanged(SalesOrder $order, ?string $from, string $to): void
    {
        try {
            $shortId = strtoupper(substr((string) $order->public_id, 0, 8));
            $this->metrics->increment("orders.status.{$to}");

            $fromLabel = $from ?: 'جدید';
            $this->topics->send(
                'orders',
                "📦 تغییر وضعیت سفارش #{$shortId}\nاز: {$fromLabel} ← به: {$to}",
                "order_status:{$shortId}:{$to}",
            );
        } catch (Throwable) {
        }
    }

    public function repairCreated(RepairRequest $repair): void
    {
        try {
            $ref = (string) $repair->tracking_reference;
            $brand = $repair->device_brand?->value ?? (string) $repair->device_brand;
            $this->metrics->increment('repairs.created');

            $this->topics->send(
                'repairs',
                "🛠️ درخواست تعمیر جدید\nکد پیگیری: {$ref}\nدستگاه: {$brand} {$repair->device_model}",
                "repair_created:{$ref}",
            );
        } catch (Throwable) {
        }
    }

    public function repairHandoverUpdated(RepairRequest $repair, string $method): void
    {
        try {
            $ref = (string) $repair->tracking_reference;
            $label = RepairRequest::HANDOVER_METHODS[$method] ?? $method;
            $this->metrics->increment('repairs.handover_updated');

            $this->topics->send(
                'repairs',
                "🚚 تغییر روش تحویل تعمیر\nکد پیگیری: {$ref}\nروش جدید: {$label}",
                "repair_handover:{$ref}:{$method}",
            );
        } catch (Throwable) {
        }
    }

    public function repairQuoteDecision(RepairCase $case, string $decision): void
    {
        try {
            $ref = (string) ($case->tracking_reference ?: substr((string) $case->public_id, 0, 8));
            $label = $decision === 'approved' ? 'تأیید برآورد توسط مشتری' : 'انصراف / بازگرداندن دستگاه';
            $this->metrics->increment("repairs.decision.{$decision}");

            $this->topics->send(
                'repairs',
                "📋 ثبت تصمیم برآورد تعمیر\nپرونده: {$ref}\nنتیجه: {$label}",
                "repair_decision:{$ref}:{$decision}",
            );
        } catch (Throwable) {
        }
    }

    public function userAuth(string $action, CustomerAccount $account): void
    {
        try {
            $shortId = strtoupper(substr((string) $account->public_id, 0, 8));
            $isRegister = $action === 'registered';
            $this->metrics->increment($isRegister ? 'users.registered' : 'users.login');

            $title = match ($action) {
                'registered' => '👤 ثبت‌نام کاربر جدید',
                'login_password' => '🔐 ورود کاربر با رمز عبور',
                default => '🔑 ورود کاربر با کد یک‌بارمصرف',
            };

            $this->topics->send(
                'users',
                "{$title}\nشناسه حساب: #{$shortId}",
                "user_auth:{$action}:{$shortId}",
                60,
            );
        } catch (Throwable) {
        }
    }

    public function sms(string $category, string $outcome, string $provider, string $event): void
    {
        try {
            $this->metrics->recordSms($category, $outcome, $provider, $event);

            if ($outcome !== 'accepted') {
                $this->topics->send(
                    'sms',
                    "⚠️ خطا در ارسال پیامک\nدسته: {$category}\nرویداد: {$event}\nسرویس‌دهنده: {$provider}\nنتیجه: {$outcome}",
                    "sms_fail:{$category}:{$event}:{$outcome}",
                );

                return;
            }

            if ($category !== 'otp') {
                $this->topics->send(
                    'sms',
                    "📨 ارسال پیامک موفق\nدسته: {$category}\nرویداد: {$event}\nسرویس‌دهنده: {$provider}",
                    "sms_ok:{$category}:{$event}:".now()->format('YmdHi'),
                    30,
                );
            }
        } catch (Throwable) {
        }
    }

    public function inventoryChanged(InventoryItem $item, int $onHandDelta, int $reservedDelta, string $kind, string $reason = ''): void
    {
        try {
            $available = (int) $item->available;
            $threshold = max(0, (int) ($item->low_stock_threshold ?? config('observability.inventory.default_low_stock_threshold', 2)));
            $this->metrics->increment('inventory.movements');

            if ($available <= 0) {
                $this->metrics->increment('inventory.out_of_stock_alerts');
                $this->topics->send(
                    'inventory',
                    "🔴 اتمام موجودی کالا\nکالا: {$item->name}\nSKU: {$item->sku}\nموجودی آزاد: 0 (رزرو: {$item->reserved})",
                    "inv_oos:{$item->id}",
                );

                return;
            }

            if ($available <= $threshold) {
                $this->metrics->increment('inventory.low_stock_alerts');
                $this->topics->send(
                    'inventory',
                    "🟡 هشدار موجودی کم\nکالا: {$item->name}\nSKU: {$item->sku}\nموجودی آزاد: {$available} (آستانه: {$threshold})",
                    "inv_low:{$item->id}:{$available}",
                );

                return;
            }

            if (in_array($kind, ['receive', 'adjust', 'dispatch'], true) && ($onHandDelta !== 0 || $reservedDelta !== 0)) {
                $sign = $onHandDelta > 0 ? "+{$onHandDelta}" : (string) $onHandDelta;
                $noteLine = $reason !== '' ? "\nتوضیح: ".mb_substr($reason, 0, 120) : '';
                $this->topics->send(
                    'inventory',
                    "📊 تغییر موجودی انبار ({$kind})\nکالا: {$item->name}\nSKU: {$item->sku}\nتغییر: {$sign} | موجودی آزاد: {$available}{$noteLine}",
                    "inv_chg:{$item->id}:{$kind}:{$available}",
                    60,
                );
            }
        } catch (Throwable) {
        }
    }
}
