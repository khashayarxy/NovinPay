<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use IntlCalendar;
use IntlDateFormatter;

final class FinanceTime
{
    public static function persianDate(?CarbonInterface $date): ?string
    {
        if ($date === null || ! class_exists(IntlDateFormatter::class)) {
            return null;
        }

        static $formatter;
        $formatter ??= new IntlDateFormatter('fa_IR@calendar=persian', IntlDateFormatter::NONE,
            IntlDateFormatter::NONE, 'Asia/Tehran', IntlDateFormatter::TRADITIONAL, 'd MMMM yyyy');
        $formatted = $formatter->format($date->getTimestamp());

        return $formatted === false ? null : FaNumber::digits($formatted);
    }

    public static function parsePersianDate(string $value): ?CarbonImmutable
    {
        if (! class_exists(IntlCalendar::class)
            || ! preg_match('~\A(1[34][0-9]{2})[/-](0[1-9]|1[0-2])[/-](0[1-9]|[12][0-9]|3[01])\z~', FaNumber::normalizeDigits($value), $parts)) {
            return null;
        }

        $calendar = IntlCalendar::createInstance('Asia/Tehran', 'fa_IR@calendar=persian');
        $calendar->clear();
        $calendar->setLenient(false);
        $calendar->setDateTime((int) $parts[1], (int) $parts[2] - 1, (int) $parts[3], 0, 0, 0);
        $millis = $calendar->getTime();

        return $millis === false ? null : CarbonImmutable::createFromTimestampUTC((int) ($millis / 1000));
    }

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
