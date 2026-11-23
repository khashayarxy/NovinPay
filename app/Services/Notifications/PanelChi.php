<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PanelChi
{
    /** @param array<string, string> $variables */
    public function send(string $event, string $identity, ?string $recipient, array $variables): void
    {
        $config = config('notifications.panelchi');
        $pattern = $config['patterns'][$event] ?? null;
        if (! $config['enabled'] || ! $pattern || ! $recipient
            || trim($config['api_key']) === '' || ! preg_match('/\A\+?[0-9]{5,20}\z/D', $config['sender'])) {
            return;
        }

        try {
            $key = 'panelchi:'.hash('sha256', $event.':'.$identity);
            // Claim before network I/O. A timeout can occur after the provider accepted the SMS.
            if (! Cache::store('database')->add($key, true, now()->addYear())) {
                return;
            }

            $response = Http::acceptJson()->withToken(trim($config['api_key']))
                ->connectTimeout(2)->timeout(5)->withoutRedirecting()
                ->post('https://api.panelchi.com/sms/pattern', [
                    'sourceNumber' => $config['sender'],
                    'recipient' => $recipient,
                    'pattern' => $pattern,
                    'variables' => $variables,
                ]);

            if (! $response->successful() || empty($response->json('data.uid'))) {
                Log::warning('PanelChi pattern send was not confirmed', ['event' => $event, 'http_status' => $response->status()]);
            }
        } catch (Throwable $exception) {
            Log::warning('PanelChi pattern send failed safely', ['event' => $event, 'exception' => $exception::class]);
        }
    }
}
