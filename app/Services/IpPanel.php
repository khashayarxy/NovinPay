<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class IpPanel
{
    private const BASE = 'https://edge.ippanel.com/v1/api';

    public function configured(): bool
    {
        $config = config('customer.ippanel', []);

        return $this->hasKey()
            && preg_match('/\A[A-Za-z0-9]{1,80}\z/D', (string) ($config['pattern_code'] ?? '')) === 1
            && preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,39}\z/D', (string) ($config['parameter'] ?? '')) === 1
            && preg_match('/\A\+98[1-9][0-9]{5,11}\z/D', (string) ($config['sender'] ?? '')) === 1;
    }

    private function hasKey(): bool
    {
        $key = config('customer.ippanel.api_key');

        return is_string($key) && $key !== '' && ! preg_match('/[\x00-\x20\x7f]/', $key);
    }

    private function client(): PendingRequest
    {
        return Http::withoutRedirecting()->connectTimeout(4)->timeout(10)->acceptJson()
            ->withHeaders(['Authorization' => config('customer.ippanel.api_key')]);
    }

    private function accepted(Response $response): bool
    {
        return $response->successful() && $response->json('meta.status') === true;
    }

    private function failure(Response $response): string
    {
        $message = $response->json('meta.message');
        if ($response->status() === 402 || (is_string($message) && preg_match('/insufficient\s+(?:credit|balance)|اعتبار\s+ناکافی|اعتبار.{0,32}کافی\s+نیست|موجودی.{0,32}کافی\s+نیست/iu', $message))) {
            return 'insufficient_credit';
        }

        return match ($response->status()) {
            401, 403 => 'authentication_or_permission',
            400, 404, 422 => 'request_rejected',
            429 => 'provider_rate_limited',
            default => match (true) {
                $response->serverError() => 'provider_unavailable',
                $response->json('meta.status') === false => 'request_rejected',
                default => 'unexpected_response',
            },
        };
    }

    public function send(string $phone, string $code): bool
    {
        if (! config('customer.ippanel.enabled') || ! $this->configured()
            || ! preg_match('/\A09[0-9]{9}\z/D', $phone) || ! preg_match('/\A[0-9]{6}\z/D', $code)) {
            return false;
        }

        $started = hrtime(true);
        try {
            $response = $this->client()->post(self::BASE.'/send', [
                'sending_type' => 'pattern',
                'from_number' => config('customer.ippanel.sender'),
                'code' => config('customer.ippanel.pattern_code'),
                'recipients' => ['+98'.substr($phone, 1)],
                'params' => [config('customer.ippanel.parameter') => $code],
            ]);
            $ids = $response->json('data.message_outbox_ids');
            if ($this->accepted($response) && is_array($ids) && array_is_list($ids) && count($ids) === 1
                && (is_int($ids[0]) || is_string($ids[0])) && preg_match('/\A[1-9][0-9]{0,19}\z/D', (string) $ids[0])) {
                $this->record('accepted', $started, ['outbox_id' => (string) $ids[0]]);

                return true;
            }
            $this->record($this->failure($response), $started, ['http_status' => $response->status()]);
        } catch (\Throwable) {
            // The outcome can be unknown after a timeout. Never retry or fail over.
            $this->record('transport_or_response', $started);
        }

        return false;
    }

    private function record(string $category, int $started, array $context = []): void
    {
        try {
            Log::log($category === 'accepted' ? 'info' : 'warning', 'Customer OTP provider outcome', $context + [
                'provider' => 'ippanel', 'category' => $category, 'request_ms' => (int) ((hrtime(true) - $started) / 1000000),
            ]);
        } catch (\Throwable) {
            // Logging availability cannot change a provider acknowledgement.
        }
    }

    public function inspect(): array
    {
        if (! $this->configured()) {
            return ['accessible' => false, 'reason' => 'configuration_incomplete'];
        }

        try {
            $pattern = config('customer.ippanel.pattern_code');
            $parameter = config('customer.ippanel.parameter');
            $response = $this->client()->get(self::BASE.'/patterns/'.$pattern);
            if (! $this->accepted($response) || $response->json('data.pattern_code') !== $pattern) {
                return ['accessible' => false, 'reason' => $this->failure($response), 'http_status' => $response->status()];
            }
            $variables = $response->json('data.variable');
            $delimiter = $response->json('data.delimiter');
            $message = $response->json('data.pattern_message');
            $placeholder = is_string($delimiter) && $delimiter !== '' ? $delimiter.$parameter.$delimiter : null;
            $lines = is_string($message) ? preg_split('/\r\n|\n|\r/', rtrim($message, "\r\n")) : [];
            $host = parse_url(config('app.url'), PHP_URL_HOST);

            return [
                'accessible' => true,
                'pattern_active' => $response->json('data.pattern_status') === 'active',
                'exact_parameter_match' => is_array($variables) && array_is_list($variables) && count($variables) === 1
                    && ($variables[0]['name'] ?? null) === $parameter,
                'webotp_template_match' => is_string($host) && $placeholder !== null
                    && end($lines) === '@'.$host.' #'.$placeholder && substr_count($message, $placeholder) === 2,
            ];
        } catch (\Throwable) {
            return ['accessible' => false, 'reason' => 'transport_or_response'];
        }
    }

    public function delivery(string $reference): array
    {
        if (! $this->hasKey() || ! preg_match('/\A[1-9][0-9]{0,19}\z/D', $reference)) {
            return ['accessible' => false, 'reason' => 'configuration_or_reference_invalid'];
        }
        try {
            $outbox = $this->client()->get(self::BASE.'/report/by_bulk', ['messages_outbox_id' => $reference]);
            if (! $this->accepted($outbox) || (string) $outbox->json('data.messages_outbox_id') !== $reference
                || (string) $outbox->json('data.rcpts_count') !== '1') {
                return ['accessible' => false, 'reason' => 'outbox_access_or_identity'];
            }
            $state = (string) $outbox->json('data.state_id');
            if ($state !== '6') {
                return ['accessible' => true, 'status' => match ($state) {
                    '8' => 'insufficient_credit',
                    '3', '4', '7', '9' => 'not_delivered',
                    default => 'pending',
                }];
            }
            $recipients = $this->client()->get(self::BASE.'/report/recipients', ['page' => 1, 'per_page' => 10, 'bulk_id' => $reference]);
            $data = $recipients->json('data');
            if (! $this->accepted($recipients) || ! is_array($data) || ! array_is_list($data) || count($data) !== 1) {
                return ['accessible' => false, 'reason' => 'delivery_report_unavailable'];
            }

            return ['accessible' => true, 'status' => match ((string) ($data[0]['message_status'] ?? '')) {
                '2' => 'provider_reported_delivered',
                '3' => 'not_delivered',
                '4' => 'blacklisted',
                default => 'pending',
            }];
        } catch (\Throwable) {
            return ['accessible' => false, 'reason' => 'transport_or_response'];
        }
    }
}
