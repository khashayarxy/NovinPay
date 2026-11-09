<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RepairTracking
{
    public const FORMAT = '/\A[1-9][0-9]{7}\z/D';

    /** Reserve across online requests and physical cases, under the caller's transaction. */
    public static function reserve(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = (string) random_int(10000000, 99999999);
            if (DB::table('repair_tracking_codes')->insertOrIgnore(['code' => $code]) === 1) {
                return $code;
            }
        }
        throw new RuntimeException('Unable to reserve a unique repair tracking code.');
    }
}
