<?php

namespace App\Support;

final class AdminRepairMoney
{
    public static function format(?int $toman): string
    {
        return $toman === null ? '' : FaNumber::digits(preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/', '٬', (string) $toman));
    }

    public static function display(?int $toman): string
    {
        return $toman === null ? 'هزینه هنوز ثبت نشده است' : self::format($toman).' تومان';
    }

    /** Exact decimal thousands of toman -> whole toman; no floating point. */
    public static function toman(string $input, int $scale = 1000): int
    {
        $input = str_replace(['٬', ','], '', FaNumber::normalizeDigits(trim($input)));
        $input = str_replace('٫', '.', $input);
        if ($scale === 1) {
            abort_unless(preg_match('/\A[0-9]{1,10}\z/D', $input) && (int) $input <= 1000000000, 422, 'مبلغ معتبر وارد کنید.');

            return (int) $input;
        }
        abort_unless($scale === 1000, 422);
        abort_unless(preg_match('/\A[0-9]{1,7}(?:\.[0-9]{1,3})?\z/D', $input), 422, 'مبلغ معتبر وارد کنید.');
        [$whole, $fraction] = array_pad(explode('.', $input, 2), 2, '');
        $amount = (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
        abort_if($amount > 1000000000, 422, 'مبلغ بیش از حد مجاز است.');

        return $amount;
    }

    public static function input(int $toman): string
    {
        return intdiv($toman, 1000).($toman % 1000 ? '.'.rtrim(str_pad((string) ($toman % 1000), 3, '0', STR_PAD_LEFT), '0') : '');
    }
}
