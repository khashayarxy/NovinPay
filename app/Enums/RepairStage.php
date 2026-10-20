<?php

namespace App\Enums;

enum RepairStage: string
{
    case Received = 'received';
    case WaitingInspection = 'waiting_inspection';
    case Inspecting = 'inspecting';
    case Inspected = 'inspected';
    case WaitingApproval = 'waiting_approval';
    case Approved = 'approved';
    case Repairing = 'repairing';
    case Repaired = 'repaired';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case ReturnPending = 'return_pending';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'دستگاه دریافت شده', self::WaitingInspection => 'در انتظار بررسی',
            self::Inspecting => 'در حال بررسی', self::Inspected => 'بررسی تکمیل شده',
            self::WaitingApproval => 'در انتظار تأیید برآورد مشتری', self::Approved => 'مجاز برای تعمیر',
            self::Repairing => 'در حال تعمیر', self::Repaired => 'تعمیر تکمیل شده',
            self::Ready => 'آمادهٔ مراجعه', self::Delivered => 'تحویل مشتری شده',
            self::ReturnPending => 'تعمیر لغو شده؛ منتظر بازگرداندن', self::Returned => 'بازگردانده‌شده بدون تعمیر',
        };
    }

    public function closed(): bool
    {
        return in_array($this, [self::Delivered, self::Returned], true);
    }
}
