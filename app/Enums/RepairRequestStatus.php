<?php

namespace App\Enums;

enum RepairRequestStatus: string
{
    case OnlineSubmitted = 'online_submitted';

    public function label(): string
    {
        return 'درخواست آنلاین ثبت شده؛ دریافت فیزیکی تأیید نشده است';
    }
}
