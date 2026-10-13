<?php

namespace App\Support;

final class FaNumber
{
    private const DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    public static function digits(string|int $value): string
    {
        return strtr((string) $value, array_combine(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], self::DIGITS));
    }
}
