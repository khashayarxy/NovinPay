<?php

namespace App\Services;

use App\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CustomerNotifications
{
    public function record(?int $account, string $event, string $key, string $path = '/account'): void
    {
        if ($account === null) {
            return;
        }
        abort_unless(preg_match('~\A/account(?:/documents/[a-f0-9-]{36}(?:/delivery)?)?\z~D', $path), 422);
        DB::table('customer_notifications')->insertOrIgnore(['customer_account_id' => $account, 'event' => $event, 'path' => $path, 'event_key' => hash_hmac('sha256', $event.':'.$key, config('app.key')), 'created_at' => now()]);
    }

    public function sendPending(): int
    {
        if (! config('customer.notifications_enabled') || ! config('customer.sms_api_key')) {
            return 0;
        }
        // A worker may have died after the provider accepted its request. Retain
        // that uncertainty for review; never automatically send the message again.
        DB::table('customer_notifications')->where('state', 'sending')->where('claimed_at', '<=', now()->subMinutes(10))->update(['state' => 'review']);
        $events = array_keys(array_filter(config('customer.notification_templates', []), fn ($id) => is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0));
        $sent = 0;
        foreach (DB::table('customer_notifications')->where('state', 'pending')->whereIn('event', $events)->orderBy('id')->limit(20)->get() as $item) {
            $template = config('customer.notification_templates.'.$item->event);
            if (! $template) {
                continue;
            }
            // Do not replay a lost/uncertain provider response automatically.
            if (! DB::table('customer_notifications')->where('id', $item->id)->where('state', 'pending')->update(['state' => 'sending', 'claimed_at' => now()])) {
                continue;
            }
            $account = CustomerAccount::find($item->customer_account_id);
            if (! $account || ! $account->is_active) {
                DB::table('customer_notifications')->where('id', $item->id)->update(['state' => 'cancelled']);

                continue;
            }
            try {
                $response = Http::withoutRedirecting()->connectTimeout(4)->timeout(10)->withHeaders(['X-API-KEY' => config('customer.sms_api_key')])->post('https://api.sms.ir/v1/send/verify', ['mobile' => $account->phone, 'templateId' => (int) $template, 'parameters' => [['name' => config('customer.notification_parameter'), 'value' => rtrim(config('app.url'), '/').$item->path]]]);
                $ok = $response->successful() && $response->json('status') === 1;
            } catch (\Throwable) {
                $ok = false;
            }
            DB::table('customer_notifications')->where('id', $item->id)->update(['state' => $ok ? 'sent' : 'review', 'sent_at' => $ok ? now() : null]);
            $sent += $ok ? 1 : 0;
        }

        return $sent;
    }
}
