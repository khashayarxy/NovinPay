<?php

namespace App\Services\Notifications;

use App\Services\Observability\TelegramTopicNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BaleNotifier
{
    public function enabled(): bool
    {
        return (bool) config('notifications.bale.enabled', false)
            && $this->validToken((string) config('notifications.bale.bot_token', ''))
            && trim((string) config('notifications.bale.chat_id', '')) !== '';
    }

    public function send(
        string $text,
        ?string $dedupeKey = null,
        ?int $throttleSeconds = null,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
    ): bool {
        if (! $this->enabled()) {
            return false;
        }

        $clean = trim(TelegramTopicNotifier::redact($text));
        if ($clean === '') {
            return false;
        }

        if ($dedupeKey !== null && $dedupeKey !== '') {
            $ttl = max(1, $throttleSeconds ?? 300);
            $cacheKey = 'bale_dedupe:'.hash('sha256', $dedupeKey);
            try {
                if (! Cache::add($cacheKey, true, now()->addSeconds($ttl))) {
                    return false;
                }
            } catch (Throwable) {
            }
        }

        $base = rtrim((string) config('notifications.bale.api_base', 'https://tapi.bale.ai'), '/');
        $token = (string) config('notifications.bale.bot_token');
        $chatId = trim((string) config('notifications.bale.chat_id'));

        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($clean, 0, 3900),
        ];

        if ($actionUrl !== null && $actionUrl !== '') {
            $parts = parse_url($actionUrl);
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            if (is_array($parts) && ($parts['host'] ?? null) === $appHost && ($parts['scheme'] ?? '') === 'https') {
                $payload['reply_markup'] = [
                    'inline_keyboard' => [[['text' => $actionLabel ?: 'مشاهده در پنل مدیریت', 'url' => $actionUrl]]],
                ];
            }
        }

        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(5)
                ->acceptJson()
                ->post($base.'/bot'.$token.'/sendMessage', $payload);

            if ($response->successful() && $response->json('ok') === true) {
                return true;
            }

            Log::warning('Bale notification failed', [
                'http_status' => $response->status(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Bale notification transport error', [
                'exception' => $e::class,
            ]);
        }

        return false;
    }

    private function validToken(string $token): bool
    {
        return $token !== '' && preg_match('/[\x00-\x20\x7f]/', $token) !== 1;
    }
}
