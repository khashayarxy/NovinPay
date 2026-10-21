<?php

namespace App\Support;

use Carbon\CarbonInterface;
use IntlDateFormatter;

final class FinanceTime
{
    public static function display(?CarbonInterface $time): string
    {
        if ($time === null) {
            return '—';
        }

        if (class_exists(IntlDateFormatter::class)) {
            static $formatter = new IntlDateFormatter('fa_IR@calendar=persian', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Asia/Tehran', IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd HH:mm');
            $formatted = $formatter->format($time->getTimestamp());
            if ($formatted !== false) {
                return FaNumber::digits($formatted).' (شمسی، تهران)';
            }
        }

        // An explicit fallback never presents a Gregorian date as a Persian one.
        return FaNumber::digits($time->copy()->timezone('Asia/Tehran')->format('Y/m/d H:i')).' (میلادی، تهران)';
    }
}
