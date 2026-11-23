<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PanelChi
{
    public const PATTERNS = [
        'order_confirmed' => 'mfae2yqje8msw9e',
        'order_preparing' => '694jmegiuep02i6',
        'order_shipped' => 'yksd5440vew1loy',
        'repair_created' => '309p3u2jo5b7qds',
        'repair_payment' => 'a7h9o09htzyjk17',
        'repair_status' => 'hn7sgk679tfdxvt',
        'order_pickup_ready' => 'u2fc816s2qo1u5t',
        'order_cancelled' => 'xtawhyckra6k1tt',
        'repair_cancelled' => '8zvy6p1t482z3oy',
    ];

    public function configurationIssue(): ?string
    {
        $config = config('notifications.panelchi', []);

        return match (true) {
            ($config['enabled'] ?? null) === false => 'disabled',
            trim((string) ($config['api_key'] ?? '')) === '' => 'missing_api_key',
            trim((string) ($config['sender'] ?? '')) === '' => 'missing_sender',
            ! preg_match('/\A\+?[0-9]{5,20}\z/D', trim($config['sender'])) => 'invalid_sender',
            default => null,
        };
    }

    /** @param array<string, string> $variables */
    public function send(string $event, string $identity, ?string $recipient, array $variables): void
    {
        $config = config('notifications.panelchi', []);
        $issue = $this->configurationIssue();
        if ($issue !== null) {
            Log::log($issue === 'disabled' ? 'debug' : 'warning', 'PanelChi pattern skipped', ['event' => $event, 'reason' => $issue]);

            return;
        }

        $pattern = trim((string) ($config['patterns'][$event] ?? '')) ?: (self::PATTERNS[$event] ?? '');
        if (! isset(self::PATTERNS[$event]) || ! preg_match('/\A[A-Za-z0-9]{1,80}\z/D', $pattern)) {
            Log::warning('PanelChi pattern skipped', ['event' => $event, 'reason' => 'invalid_pattern']);

            return;
        }
        if (! $recipient || ! preg_match('/\A09[0-9]{9}\z/D', $recipient)) {
            Log::warning('PanelChi pattern skipped', ['event' => $event, 'reason' => 'missing_or_invalid_recipient']);

            return;
        }

        $phase = 'deduplication';
        try {
            $key = 'panelchi:'.hash('sha256', $event.':'.$identity);
            // Claim before network I/O. A timeout can occur after the provider accepted the SMS.
            if (! Cache::store('database')->add($key, true, now()->addYear())) {
                Log::debug('PanelChi duplicate suppressed', ['event' => $event]);

                return;
            }

            $phase = 'provider_request';
            $token = preg_replace('/\ABearer\s+/i', '', trim($config['api_key']));
            $response = Http::acceptJson()->withToken($token)
                ->connectTimeout(2)->timeout(5)->withoutRedirecting()
                ->post('https://api.panelchi.com/sms/pattern', [
                    'sourceNumber' => trim($config['sender']),
                    'recipient' => $recipient,
                    'pattern' => $pattern,
                    'variables' => $variables,
                ]);

            if (! $response->successful() || empty($response->json('data.uid'))) {
                Log::warning('PanelChi pattern send was not confirmed', [
                    'event' => $event, 'http_status' => $response->status(),
                    'reason' => $response->successful() ? 'missing_provider_receipt' : 'provider_http_error',
                ]);
            } else {
                Log::info('PanelChi pattern accepted', ['event' => $event]);
            }
        } catch (Throwable $exception) {
            Log::warning('PanelChi pattern send failed safely', ['event' => $event, 'phase' => $phase, 'exception' => $exception::class]);
        }
    }
}
