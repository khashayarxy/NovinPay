<?php

namespace App\Support;

final class OrderMoney
{
    public static function minor(string $value): int
    {
        abort_unless(preg_match('/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/', $value), 422, 'مبلغ تومان معتبر وارد کنید.');
        $parts = explode('.', $value);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public static function decimal(int $minor): string
    {
        abort_if($minor < 0 || $minor > 999999999999999, 422, 'مبلغ کل بیش از حد مجاز است.');

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
