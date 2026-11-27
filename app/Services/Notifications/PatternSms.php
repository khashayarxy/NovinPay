<?php

namespace App\Services\Notifications;

use App\Services\IpPanel;
use App\Services\Observability\ObservabilityEvents;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PatternSms
{
    public const PATTERNS = [
        'order_confirmed' => 'mfae2yqje8msw9e',
        'order_preparing' => '694jmegiuep02i6',
        'order_shipped' => 'yksd5440vew1loy',
        'repair_created_in_person' => '',
        'repair_created_postal' => '',
        'repair_created_courier' => '',
        'repair_payment' => 'a7h9o09htzyjk17',
        'repair_status' => 'hn7sgk679tfdxvt',
        'order_pickup_ready' => 'u2fc816s2qo1u5t',
        'order_cancelled' => 'xtawhyckra6k1tt',
        'repair_cancelled' => '8zvy6p1t482z3oy',
    ];

    public function __construct(private readonly IpPanel $ipPanel) {}

    public function provider(): string
    {
        $configured = config('notifications.pattern_sms.provider') ?? config('customer.sms_provider', 'ippanel');

        return match (strtolower(trim((string) $configured))) {
            'ippanel' => 'ippanel',
            'panelchi' => 'panelchi',
            default => 'unsupported',
        };
    }

    public function configurationIssue(): ?string
    {
        if (config('notifications.pattern_sms.enabled') === false) {
            return 'disabled';
        }

        return match ($this->provider()) {
            'ippanel' => $this->ipPanel->patternConfigurationIssue(),
            'panelchi' => $this->panelChiConfigurationIssue(),
            default => 'unsupported_provider',
        };
    }

    private function panelChiConfigurationIssue(): ?string
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

    public function resolvePattern(string $event): string
    {
        if (! array_key_exists($event, self::PATTERNS)) {
            return '';
        }

        $configured = trim((string) config('notifications.pattern_sms.patterns.'.$event));
        if ($configured !== '') {
            return $configured;
        }

        return self::PATTERNS[$event];
    }

    /** @param array<string, string> $variables */
    public function send(string $event, string $identity, ?string $recipient, array $variables): void
    {
        $context = ['event' => $event, 'provider' => $this->provider()];
        $issue = $this->configurationIssue();
        if ($issue !== null) {
            Log::log($issue === 'disabled' ? 'debug' : 'warning', 'SMS pattern skipped', $context + ['reason' => $issue]);

            return;
        }

        $pattern = $this->resolvePattern($event);
        if (! array_key_exists($event, self::PATTERNS) || $pattern === '') {
            Log::debug('SMS pattern skipped (unconfigured)', $context);

            return;
        }
        if (! preg_match('/\A[A-Za-z0-9]{1,80}\z/D', $pattern)) {
            Log::warning('SMS pattern skipped', $context + ['reason' => 'invalid_pattern']);

            return;
        }
        if (! $recipient || $recipient === '09000000000' || ! preg_match('/\A09[0-9]{9}\z/D', $recipient)) {
            Log::warning('SMS pattern skipped', $context + ['reason' => 'missing_or_invalid_recipient']);

            return;
        }

        $phase = 'deduplication';
        try {
            // Preserve existing claims across provider switches and method-specific repair_created variants.
            $dedupeEvent = str_starts_with($event, 'repair_created') ? 'repair_created' : $event;
            $key = 'panelchi:'.hash('sha256', $dedupeEvent.':'.$identity);
            if (! Cache::store('database')->add($key, true, now()->addYear())) {
                Log::debug('SMS pattern duplicate suppressed', $context);

                return;
            }

            $phase = 'provider_request';
            $outcome = $context['provider'] === 'ippanel'
                ? ['outcome' => $this->ipPanel->sendPattern($recipient, $pattern, $variables)]
                : $this->sendWithPanelChi($recipient, $pattern, $variables);
            Log::log($outcome['outcome'] === 'accepted' ? 'info' : 'warning', 'SMS pattern provider outcome', $context + $outcome);
            app(ObservabilityEvents::class)->sms(
                $this->categoryForEvent($event),
                (string) ($outcome['outcome'] ?? 'failed'),
                $context['provider'],
                $event,
            );
        } catch (Throwable $exception) {
            Log::warning('SMS pattern send failed safely', $context + ['phase' => $phase, 'exception' => $exception::class]);
            app(ObservabilityEvents::class)->sms(
                $this->categoryForEvent($event),
                'failed',
                $context['provider'],
                $event,
            );
        }
    }

    private function categoryForEvent(string $event): string
    {
        return match (true) {
            str_starts_with($event, 'order_') => 'order',
            str_starts_with($event, 'repair_') => 'repair',
            str_starts_with($event, 'admin_') => 'alert',
            default => 'other',
        };
    }

    /**
     * Dispatch an explicit pattern code (used for admin SMS notifications) via the active SMS provider.
     *
     * @param  array<string, scalar>  $variables
     */
    public function sendExplicitPattern(string $event, string $recipient, string $pattern, array $variables): string
    {
        if (! preg_match('/\A09[0-9]{9}\z/D', $recipient) || ! preg_match('/\A[A-Za-z0-9]{1,80}\z/D', $pattern) || $variables === []) {
            return 'rejected';
        }

        $stringVars = [];
        foreach ($variables as $k => $v) {
            $stringVars[(string) $k] = (string) $v;
        }

        $provider = $this->provider();
        try {
            if ($provider === 'panelchi' && $this->panelChiConfigurationIssue() === null) {
                return (string) ($this->sendWithPanelChi($recipient, $pattern, $stringVars)['outcome'] ?? 'failed');
            }
            if ($this->ipPanel->patternConfigurationIssue() === null) {
                return $this->ipPanel->sendPattern($recipient, $pattern, $stringVars);
            }
            Log::warning('Explicit SMS pattern skipped due to provider configuration', [
                'event' => $event,
                'provider' => $provider,
                'issue' => $provider === 'panelchi' ? $this->panelChiConfigurationIssue() : $this->ipPanel->patternConfigurationIssue(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Explicit SMS pattern dispatch failed safely', [
                'event' => $event,
                'provider' => $provider,
                'exception' => $e::class,
            ]);
        }

        return 'failed';
    }

    /** @param array<string, string> $variables */
    private function sendWithPanelChi(string $recipient, string $pattern, array $variables): array
    {
        $token = preg_replace('/\ABearer\s+/i', '', trim((string) config('notifications.panelchi.api_key')));
        $response = Http::acceptJson()->withToken($token)
            ->connectTimeout(2)->timeout(5)->withoutRedirecting()
            ->post('https://api.panelchi.com/sms/pattern', [
                'sourceNumber' => trim((string) config('notifications.panelchi.sender')),
                'recipient' => $recipient,
                'pattern' => $pattern,
                'variables' => $variables,
            ]);

        return [
            'outcome' => $response->successful() && ! empty($response->json('data.uid')) ? 'accepted' : 'unconfirmed',
            'http_status' => $response->status(),
        ];
    }
}
