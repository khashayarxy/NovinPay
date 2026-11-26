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
            $toman = intdiv((int) $attempt->amount_minor, 100);
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
                default => '💳 رویداد پرداخت',
            };

            $lines = [
                $label,
                "شناسه تلاش: #{$shortId}",
                'مبلغ: '.number_format($toman).' تومان',
                'وضعیت: '.self::label($attempt->state),
                "کد رهگیری زیبال: {$track}",
            ];
            if ($note) {
                $lines[] = 'جزئیات: '.self::label($note);
            }

            $this->topics->send('payments', implode("\n", $lines), "pay:{$stage}:{$shortId}");
        } catch (Throwable) {
        }
    }

    public function orderCreated(SalesOrder $order): void
    {
        try {
            $this->metrics->increment('orders.created');
        } catch (Throwable) {
        }
    }

    public function orderStatusChanged(SalesOrder $order, ?string $from, string $to, bool $fullConfirmation = false): void
    {
        try {
            $shortId = strtoupper(substr((string) $order->public_id, 0, 8));
            $this->metrics->increment("orders.status.{$to}");
            if ($to === 'confirmed' && $fullConfirmation) {
                return;
            }

            $fromLabel = SalesOrder::STATUSES[$from] ?? 'جدید';
            $toLabel = SalesOrder::STATUSES[$to] ?? 'به‌روزرسانی سفارش';
            $this->topics->send(
                'orders',
                "📦 تغییر وضعیت سفارش #{$shortId}\nاز: {$fromLabel}\nبه: {$toLabel}",
                "order_status:{$shortId}:{$to}",
                adminUrl: route('admin.orders.show', $order),
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

            $lines = [
                '🛠️ درخواست تعمیر جدید',
                "کد پیگیری: {$ref}",
                "دستگاه: {$brand} {$repair->device_model}",
            ];
            if (method_exists($repair, 'parsedIssue')) {
                $parsed = $repair->parsedIssue();
                if (! empty($parsed['handover_label'])) {
                    $lines[] = 'نحوه تحویل: '.$parsed['handover_label'];
                }
                if (($parsed['handover_key'] ?? null) === 'doorstep_pickup') {
                    if (! empty($parsed['pickup_address'])) {
                        $lines[] = 'نشانی پیک: '.mb_substr((string) $parsed['pickup_address'], 0, 180);
                    }
                    if (! empty($parsed['responsive_phone'])) {
                        $lines[] = 'تلفن هماهنگی: '.$parsed['responsive_phone'];
                    }
                    if (! empty($parsed['pickup_notes'])) {
                        $lines[] = 'توضیحات پیک: '.mb_substr((string) $parsed['pickup_notes'], 0, 160);
                    }
                }
            }

            $this->topics->send(
                'repairs',
                implode("\n", $lines),
                "repair_created:{$ref}",
                adminUrl: route('admin.repairs.show', $repair),
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

            $lines = [
                '🚚 تغییر روش تحویل تعمیر',
                "کد پیگیری: {$ref}",
                "روش جدید: {$label}",
            ];
            if ($method === 'doorstep_pickup' && method_exists($repair, 'parsedIssue')) {
                $parsed = $repair->parsedIssue();
                if (! empty($parsed['pickup_address'])) {
                    $lines[] = 'نشانی پیک: '.mb_substr((string) $parsed['pickup_address'], 0, 180);
                }
                if (! empty($parsed['responsive_phone'])) {
                    $lines[] = 'تلفن هماهنگی: '.$parsed['responsive_phone'];
                }
                if (! empty($parsed['pickup_notes'])) {
                    $lines[] = 'توضیحات پیک: '.mb_substr((string) $parsed['pickup_notes'], 0, 160);
                }
            }

            $this->topics->send(
                'repairs',
                implode("\n", $lines),
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
            $categoryLabel = self::label($category);
            $outcomeLabel = self::label($outcome);
            $providerLabel = self::label($provider);
            $eventLabel = self::label($event);

            if ($outcome !== 'accepted') {
                $this->topics->send(
                    'sms',
                    "⚠️ خطا در ارسال پیامک\nدسته: {$categoryLabel}\nرویداد: {$eventLabel}\nسرویس‌دهنده: {$providerLabel}\nنتیجه: {$outcomeLabel}",
                    "sms_fail:{$category}:{$event}:{$outcome}",
                );

                return;
            }

            if ($category !== 'otp') {
                $this->topics->send(
                    'sms',
                    "📨 ارسال پیامک موفق\nدسته: {$categoryLabel}\nرویداد: {$eventLabel}\nسرویس‌دهنده: {$providerLabel}",
                    "sms_ok:{$category}:{$event}:".now()->format('YmdHi'),
                    30,
                );
            }
        } catch (Throwable) {
        }
    }

    public function consultationCreated(): void
    {
        try {
            $this->metrics->increment('consultations.created');
        } catch (Throwable) {
        }
    }

    public function reviewCreated(): void
    {
        try {
            $this->metrics->increment('reviews.created');
        } catch (Throwable) {
        }
    }

    public function supportChatMessageReceived(): void
    {
        try {
            $this->metrics->increment('support_chat.messages');
        } catch (Throwable) {
        }
    }

    public function inventoryChanged(InventoryItem $item, int $onHandDelta, int $reservedDelta, string $kind, string $reason = ''): void
    {
        try {
            $available = (int) $item->available;
            $configuredDefault = (int) config('observability.inventory.low_stock_threshold', 3);
            $itemThreshold = (int) ($item->low_stock_threshold ?? 0);
            $threshold = $itemThreshold > 0 ? $itemThreshold : max(0, $configuredDefault);
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

            $availableDelta = $onHandDelta - $reservedDelta;
            if (in_array($kind, ['receive', 'adjust', 'dispatch', 'reserve', 'release'], true) && ($onHandDelta !== 0 || $reservedDelta !== 0)) {
                $deltaValue = $availableDelta !== 0 ? $availableDelta : $onHandDelta;
                $sign = $deltaValue > 0 ? "+{$deltaValue}" : (string) $deltaValue;
                $noteLine = $reason !== '' ? "\nتوضیح: ".mb_substr($reason, 0, 120) : '';
                $this->topics->send(
                    'inventory',
                    '📊 تغییر موجودی انبار ('.self::label($kind).")\nکالا: {$item->name}\nشناسه کالا: {$item->sku}\nتغییر موجودی آزاد: {$sign} | موجودی آزاد: {$available}{$noteLine}",
                    "inv_chg:{$item->id}:{$kind}:{$available}",
                    60,
                );
            }
        } catch (Throwable) {
        }
    }

    private static function label(string $value): string
    {
        return match ($value) {
            'pending', 'requesting', 'started' => 'در انتظار پرداخت',
            'processing' => 'در حال بررسی',
            'settled' => 'تسویه‌شده',
            'failed', 'rejected', 'request_rejected' => 'ناموفق',
            'review', 'mismatch' => 'نیازمند بررسی',
            'uncertain', 'unconfirmed', 'unexpected_response' => 'نتیجه نامشخص',
            'cancelled', 'canceled', 'gateway_canceled' => 'لغوشده',
            'accepted' => 'پذیرفته‌شده توسط سرویس‌دهنده',
            'ippanel' => 'آی‌پی‌پنل',
            'panelchi' => 'پنل‌چی',
            'otp', 'customer_otp' => 'کد تأیید',
            'alert', 'admin_alert' => 'هشدار مدیریتی',
            'order' => 'سفارش',
            'repair' => 'تعمیر',
            'pattern_notification', 'notification' => 'اطلاع‌رسانی',
            'order_confirmed' => 'تأیید سفارش',
            'order_preparing' => 'آماده‌سازی سفارش',
            'order_shipped' => 'ارسال سفارش',
            'order_pickup_ready' => 'آمادهٔ تحویل حضوری',
            'order_cancelled' => 'لغو سفارش',
            'repair_created' => 'ثبت تعمیر',
            'repair_created_in_person' => 'ثبت تعمیر (حضوری)',
            'repair_created_postal' => 'ثبت تعمیر (پستی)',
            'repair_created_courier' => 'ثبت تعمیر (پیک)',
            'repair_payment' => 'پرداخت تعمیر',
            'repair_status' => 'تغییر مرحلهٔ تعمیر',
            'repair_cancelled' => 'لغو تعمیر',
            'insufficient_credit' => 'اعتبار ناکافی',
            'authentication_or_permission' => 'خطای دسترسی سرویس‌دهنده',
            'transport_or_response', 'provider_unavailable' => 'اختلال ارتباط با سرویس‌دهنده',
            'provider_rate_limited' => 'محدودیت تعداد درخواست',
            'receive' => 'دریافت',
            'adjust' => 'اصلاح',
            'dispatch' => 'خروج',
            'reserve' => 'رزرو سفارش',
            'release' => 'آزادسازی رزرو',
            default => preg_match('/\A[a-z0-9_]+\z/i', $value) ? 'نیازمند بررسی در پنل مدیریت' : $value,
        };
    }
}
