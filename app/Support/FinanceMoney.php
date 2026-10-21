<?php

namespace App\Support;

final class FinanceMoney
{
    public static function minor(string $value): int
    {
        $value = self::normalize($value);
        abort_unless(preg_match('/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/', $value), 422, 'مبلغ دقیق تومان با حداکثر دو رقم اعشار وارد کنید.');
        $parts = explode('.', $value);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public static function normalize(string $value): string
    {
        return str_replace('٫', '.', FaNumber::normalizeDigits(trim($value)));
    }

    public static function display(int|string $minor): string
    {
        $value = (string) $minor;
        $negative = str_starts_with($value, '-');
        $digits = str_pad(ltrim($value, '-'), 3, '0', STR_PAD_LEFT);

        // Group the decimal string without converting exact ledger sums to floats.
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', substr($digits, 0, -2));

        return ($negative ? '−' : '').$whole.'.'.substr($digits, -2);
    }
}
