<?php

namespace App\Support;

use Carbon\CarbonInterface;
use IntlCalendar;
use IntlDateFormatter;

final class FinanceTime
{
    public static function startOfMonth(CarbonInterface $time): CarbonInterface
    {
        $local = $time->copy()->timezone('Asia/Tehran');
        $calendar = class_exists(IntlCalendar::class) ? IntlCalendar::createInstance('Asia/Tehran', 'fa_IR@calendar=persian') : null;
        if ($calendar === null || ! $calendar->setTime($time->getTimestamp() * 1000)) {
            return $local->startOfMonth();
        }

        return $local->subDays((int) $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH) - 1)->startOfDay();
    }

    public static function display(?CarbonInterface $time, bool $withTime = true): string
    {
        if ($time === null) {
            return '—';
        }

        if (class_exists(IntlDateFormatter::class)) {
            static $formatters = [];
            $formatter = $formatters[$withTime] ??= new IntlDateFormatter('fa_IR@calendar=persian', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Asia/Tehran', IntlDateFormatter::TRADITIONAL, $withTime ? 'HH:mm | yyyy/MM/dd' : 'yyyy/MM/dd');
            $formatted = $formatter->format($time->getTimestamp());
            if ($formatted !== false) {
                return FaNumber::digits($formatted);
            }
        }

        // An explicit fallback never presents a Gregorian date as a Persian one.
        return FaNumber::digits($time->copy()->timezone('Asia/Tehran')->format($withTime ? 'H:i | Y/m/d' : 'Y/m/d')).' (میلادی)';
    }
}
