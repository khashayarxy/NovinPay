<?php

namespace App\Enums;

enum StockStatus: string
{
    case InStock = 'in_stock';

    case Limited = 'limited';

    case OutOfStock = 'out_of_stock';

    case Unverified = 'unverified';

    public function permitsCheckout(): bool
    {
        return $this === self::InStock || $this === self::Limited;
    }

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'موجود',
            self::Limited => 'موجودی محدود',
            self::OutOfStock => 'ناموجود',
            self::Unverified => 'موجودی تأیید نشده',
        };
    }
}
