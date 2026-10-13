<?php

namespace App\Enums;

enum StockStatus: string
{
    case InStock = 'in_stock';

    case Limited = 'limited';

    case OutOfStock = 'out_of_stock';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'موجود',
            self::Limited => 'موجودی محدود',
            self::OutOfStock => 'ناموجود',
        };
    }
}
