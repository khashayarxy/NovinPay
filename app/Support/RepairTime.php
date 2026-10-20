<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

final class RepairTime
{
    public static function input(?CarbonInterface $time = null): string
    {
        return ($time ?? now())->copy()->timezone('Asia/Tehran')->format('Y-m-d\TH:i');
    }

    public static function parse(string $value): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d\TH:i', $value, 'Asia/Tehran')->utc();
    }

    public static function display(?CarbonInterface $time): string
    {
        return $time ? FaNumber::digits($time->copy()->timezone('Asia/Tehran')->format('Y/m/d H:i')).' (تهران)' : '—';
    }
}
