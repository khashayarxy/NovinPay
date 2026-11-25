<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TelegramTopicNotifier
{
    public const TOPICS = [
        'payments',
        'orders',
        'repairs',
        'users',
        'sms',
        'inventory',
        'errors',
        'backups',
        'performance',
        'digest',
    ];

    public function enabledFor(string $topic): bool
    {
        if (! in_array($topic, self::TOPICS, true)) {
            return false;
        }

        $token = (string) config('observability.telegram_topics.bot_token', '');
        $chatId = trim((string) config('observability.telegram_topics.chat_id', ''));

        return (bool) config('observability.telegram_topics.enabled', false)
            && $this->validToken($token)
            && $chatId !== '';
    }

    public function threadId(string $topic): ?int
    {
        $id = config("observability.telegram_topics.threads.{$topic}");

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    public function send(
        string $topic,
        string $text,
        ?string $dedupeKey = null,
        ?int $throttleSeconds = null,
        ?string $adminUrl = null,
    ): bool {
        if (! $this->enabledFor($topic)) {
            Log::notice('Telegram topic notification skipped', ['topic' => $topic, 'reason' => 'configuration_disabled_or_incomplete']);

            return false;
        }
        if ($this->threadId($topic) === null) {
            Log::warning('Telegram topic notification skipped', ['topic' => $topic, 'reason' => 'missing_topic_id']);

            return false;
        }

        $clean = trim(self::redact($text));
        if ($clean === '') {
            return false;
        }

        if ($dedupeKey !== null && $dedupeKey !== '') {
            $ttl = max(1, $throttleSeconds ?? (int) config('observability.telegram_topics.throttle_seconds', 300));
            $cacheKey = 'tg_topic_dedupe:'.hash('sha256', $topic.':'.$dedupeKey);
            try {
                if (! Cache::add($cacheKey, true, now()->addSeconds($ttl))) {
                    return false;
                }
            } catch (Throwable) {
                // If cache fails, fall through without breaking delivery.
            }
        }

        $base = rtrim((string) config('observability.telegram_topics.api_base', 'https://api.telegram.org'), '/');
        $token = (string) config('observability.telegram_topics.bot_token');
        $chatId = trim((string) config('observability.telegram_topics.chat_id'));
        $threadId = $this->threadId($topic);

        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($clean, 0, 3900),
            'disable_web_page_preview' => true,
        ];
        if ($threadId !== null) {
            $payload['message_thread_id'] = $threadId;
        }
        $adminUrl ??= preg_match('~https?://[^\s]+/admin/[^\s]*~u', $clean, $match) ? $match[0] : null;
        $parts = $adminUrl ? parse_url($adminUrl) : null;
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_array($parts) && ($parts['host'] ?? null) === $appHost
            && ($parts['scheme'] ?? '') === 'https' && str_starts_with($parts['path'] ?? '', '/admin/')
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])) {
            $payload['text'] = mb_substr(trim(str_replace($adminUrl, '', $clean)), 0, 3900);
            $payload['reply_markup'] = ['inline_keyboard' => [[['text' => 'مشاهده در پنل مدیریت', 'url' => $adminUrl]]]];
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

            Log::warning('Telegram topic notification failed', [
                'topic' => $topic,
                'http_status' => $response->status(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Telegram topic notification transport error', [
                'topic' => $topic,
                'exception' => $e::class,
            ]);
        }

        return false;
    }

    public static function redact(string $text): string
    {
        $patterns = [
            '/\bBearer\s+[A-Za-z0-9._\-]{8,}\b/i' => 'Bearer [REDACTED]',
            '/\b(?:bot)?[0-9]{6,12}:[A-Za-z0-9_\-]{12,}\b/' => '[REDACTED_TOKEN]',
            '/(?:api[_-]?key|token|password|secret|authorization)\s*[:=]\s*[^\s,;]+/i' => '[REDACTED_SECRET]',
            '/(?:کد\s*تأیید|کد\s*ورود|otp|verification\s*code)\s*[:=]?\s*[0-9۰-۹]{4,8}/iu' => '[REDACTED_OTP]',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    private function validToken(string $token): bool
    {
        return $token !== '' && preg_match('/[\x00-\x20\x7f]/', $token) !== 1;
    }
}
