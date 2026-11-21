<?php

namespace App\Enums;

enum ProductCategory: string
{
    case PosTerminal = 'pos_terminal';

    case Printer = 'printer';

    case Peripheral = 'peripheral';

    case Consumable = 'consumable';

    public function label(): string
    {
        return match ($this) {
            self::PosTerminal => 'دستگاه کارتخوان',
            self::Printer => 'پرینتر و چاپ رسید',
            self::Peripheral => 'لوازم جانبی',
            self::Consumable => 'رول کاغذ کارتخوان',
        };
    }

    /**
     * @return list<self>
     */
    public static function storefrontCases(): array
    {
        return [
            self::PosTerminal,
            self::Consumable,
            self::Peripheral,
        ];
    }
}
