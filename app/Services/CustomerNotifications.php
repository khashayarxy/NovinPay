<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CustomerNotifications
{
    public static function eventKey(string $event, string $key): string
    {
        return hash_hmac('sha256', $event.':'.$key, config('app.key'));
    }

    public function record(?int $account, string $event, string $key, string $path = '/account'): void
    {
        if ($account === null) {
            return;
        }
        abort_unless(preg_match('~\A/account(?:/documents/[a-f0-9-]{36}(?:/delivery)?)?\z~D', $path), 422);
        DB::table('customer_notifications')->insertOrIgnore(['customer_account_id' => $account, 'event' => $event, 'path' => $path, 'event_key' => self::eventKey($event, $key), 'created_at' => now()]);
    }
}
