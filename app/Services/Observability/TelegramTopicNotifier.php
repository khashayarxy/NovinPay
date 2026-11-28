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
        'consultations',
        'support_chat',
        'reviews',
        'documents',
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
        ?string $actionLabel = null,
    ): bool {
        if (! $this->enabledFor($topic)) {
            if (! app()->environment('testing')) {
                Log::notice('Telegram topic notification skipped', ['topic' => $topic, 'reason' => 'configuration_disabled_or_incomplete']);
            }

            return false;
        }

        $threadId = $this->threadId($topic);
        $fallbackToGeneral = (bool) config('observability.telegram_topics.fallback_to_general', true);
        if ($threadId === null && ! $fallbackToGeneral) {
            if (! app()->environment('testing')) {
                Log::warning('Telegram topic notification skipped', ['topic' => $topic, 'reason' => 'missing_topic_id']);
            }

            return false;
        }
        if ($threadId === null) {
            if (! app()->environment('testing')) {
                Log::info('Telegram topic ID unset; falling back to group main thread', ['topic' => $topic]);
            }
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

        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($clean, 0, 3900),
            'disable_web_page_preview' => true,
        ];
        if ($threadId !== null) {
            $payload['message_thread_id'] = $threadId;
        }

        [$resolvedUrl, $resolvedLabel] = $this->resolveTrustedActionButton($clean, $adminUrl, $actionLabel);
        if ($resolvedUrl !== null) {
            $stripped = trim(str_replace($resolvedUrl, '', $clean));
            $payload['text'] = mb_substr($stripped !== '' ? $stripped : $clean, 0, 3900);
            $payload['reply_markup'] = ['inline_keyboard' => [[['text' => $resolvedLabel, 'url' => $resolvedUrl]]]];
        }

        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(2)
                ->timeout(4)
                ->acceptJson()
                ->post($base.'/bot'.$token.'/sendMessage', $payload);

            if ($response->successful() && $response->json('ok') === true) {
                return true;
            }

            // Safe fallback if a configured topic thread ID was deleted or refers to General (#1)
            $desc = strtolower((string) $response->json('description', ''));
            if ($threadId !== null && $fallbackToGeneral && $response->status() === 400 && str_contains($desc, 'thread')) {
                unset($payload['message_thread_id']);
                Log::warning('Telegram topic thread invalid; retrying on main group thread', [
                    'topic' => $topic,
                    'invalid_thread_id' => $threadId,
                ]);
                $retry = Http::withoutRedirecting()
                    ->connectTimeout(2)
                    ->timeout(4)
                    ->acceptJson()
                    ->post($base.'/bot'.$token.'/sendMessage', $payload);
                if ($retry->successful() && $retry->json('ok') === true) {
                    return true;
                }
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

    public function sendDocument(
        string $topic,
        string $filePath,
        string $caption = '',
        ?string $downloadName = null,
        ?string $dedupeKey = null,
        ?int $throttleSeconds = null,
    ): bool {
        if (! $this->enabledFor($topic)) {
            if (! app()->environment('testing')) {
                Log::notice('Telegram topic document skipped', ['topic' => $topic, 'reason' => 'configuration_disabled_or_incomplete']);
            }

            return false;
        }
        if (! is_file($filePath) || is_link($filePath) || ! is_readable($filePath)) {
            Log::warning('Telegram topic document skipped', ['topic' => $topic, 'reason' => 'file_unreadable']);

            return false;
        }

        $threadId = $this->threadId($topic);
        $fallbackToGeneral = (bool) config('observability.telegram_topics.fallback_to_general', true);
        if ($threadId === null && ! $fallbackToGeneral) {
            if (! app()->environment('testing')) {
                Log::warning('Telegram topic document skipped', ['topic' => $topic, 'reason' => 'missing_topic_id']);
            }

            return false;
        }

        if ($dedupeKey !== null && $dedupeKey !== '') {
            $ttl = max(1, $throttleSeconds ?? (int) config('observability.telegram_topics.throttle_seconds', 300));
            $cacheKey = 'tg_topic_doc_dedupe:'.hash('sha256', $topic.':'.$dedupeKey);
            try {
                if (! Cache::add($cacheKey, true, now()->addSeconds($ttl))) {
                    return false;
                }
            } catch (Throwable) {
            }
        }

        $base = rtrim((string) config('observability.telegram_topics.api_base', 'https://api.telegram.org'), '/');
        $token = (string) config('observability.telegram_topics.bot_token');
        $chatId = trim((string) config('observability.telegram_topics.chat_id'));
        $cleanCaption = mb_substr(trim(self::redact($caption)), 0, 950);
        $name = $downloadName ?: basename($filePath);

        $fields = ['chat_id' => $chatId];
        if ($cleanCaption !== '') {
            $fields['caption'] = $cleanCaption;
        }
        if ($threadId !== null) {
            $fields['message_thread_id'] = (string) $threadId;
        }

        try {
            $contents = file_get_contents($filePath);
            if (! is_string($contents)) {
                return false;
            }

            $response = Http::withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(45)
                ->acceptJson()
                ->attach('document', $contents, $name)
                ->post($base.'/bot'.$token.'/sendDocument', $fields);

            if ($response->successful() && $response->json('ok') === true) {
                return true;
            }

            $desc = strtolower((string) $response->json('description', ''));
            if ($threadId !== null && $fallbackToGeneral && $response->status() === 400 && str_contains($desc, 'thread')) {
                unset($fields['message_thread_id']);
                $retry = Http::withoutRedirecting()
                    ->connectTimeout(5)
                    ->timeout(45)
                    ->acceptJson()
                    ->attach('document', $contents, $name)
                    ->post($base.'/bot'.$token.'/sendDocument', $fields);
                if ($retry->successful() && $retry->json('ok') === true) {
                    return true;
                }
            }

            Log::warning('Telegram topic document upload failed', [
                'topic' => $topic,
                'http_status' => $response->status(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Telegram topic document transport error', [
                'topic' => $topic,
                'exception' => $e::class,
            ]);
        }

        return false;
    }

    /**
     * Send a message to a topic with an explicit multi-row/multi-column InlineKeyboardMarkup.
     *
     * @param  list<list<array<string, string>>>  $inlineKeyboard
     */
    public function sendWithKeyboard(
        string $topic,
        string $text,
        array $inlineKeyboard,
        ?string $dedupeKey = null,
        ?int $throttleSeconds = null,
    ): bool {
        if (! $this->enabledFor($topic)) {
            if (! app()->environment('testing')) {
                Log::notice('Telegram topic keyboard message skipped', ['topic' => $topic, 'reason' => 'configuration_disabled_or_incomplete']);
            }

            return false;
        }

        $threadId = $this->threadId($topic);
        $fallbackToGeneral = (bool) config('observability.telegram_topics.fallback_to_general', true);
        if ($threadId === null && ! $fallbackToGeneral) {
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
            }
        }

        $base = rtrim((string) config('observability.telegram_topics.api_base', 'https://api.telegram.org'), '/');
        $token = (string) config('observability.telegram_topics.bot_token');
        $chatId = trim((string) config('observability.telegram_topics.chat_id'));

        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($clean, 0, 3900),
            'disable_web_page_preview' => true,
        ];
        if ($threadId !== null) {
            $payload['message_thread_id'] = $threadId;
        }
        if ($inlineKeyboard !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => $inlineKeyboard];
        }

        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(6)
                ->acceptJson()
                ->post($base.'/bot'.$token.'/sendMessage', $payload);

            if ($response->successful() && $response->json('ok') === true) {
                return true;
            }

            $desc = strtolower((string) $response->json('description', ''));
            if ($threadId !== null && $fallbackToGeneral && $response->status() === 400 && str_contains($desc, 'thread')) {
                unset($payload['message_thread_id']);
                $retry = Http::withoutRedirecting()
                    ->connectTimeout(3)
                    ->timeout(6)
                    ->acceptJson()
                    ->post($base.'/bot'.$token.'/sendMessage', $payload);
                if ($retry->successful() && $retry->json('ok') === true) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Telegram topic keyboard message transport error', [
                'topic' => $topic,
                'exception' => $e::class,
            ]);
        }

        return false;
    }

    /**
     * Upload a document to a topic and return the confirmed Telegram chat_id and message_id.
     *
     * @param  list<list<array<string, string>>>  $inlineKeyboard
     * @return array{ok: bool, chat_id: ?string, message_id: ?int}
     */
    public function sendDocumentWithResult(
        string $topic,
        string $filePath,
        string $caption = '',
        ?string $downloadName = null,
        array $inlineKeyboard = [],
    ): array {
        $failed = ['ok' => false, 'chat_id' => null, 'message_id' => null];
        if (! $this->enabledFor($topic)) {
            if (! app()->environment('testing')) {
                Log::notice('Telegram topic document with result skipped', ['topic' => $topic, 'reason' => 'configuration_disabled_or_incomplete']);
            }

            return $failed;
        }
        if (! is_file($filePath) || is_link($filePath) || ! is_readable($filePath)) {
            Log::warning('Telegram topic document with result skipped', ['topic' => $topic, 'reason' => 'file_unreadable']);

            return $failed;
        }

        $threadId = $this->threadId($topic);
        $fallbackToGeneral = (bool) config('observability.telegram_topics.fallback_to_general', true);
        if ($threadId === null && ! $fallbackToGeneral) {
            return $failed;
        }

        $base = rtrim((string) config('observability.telegram_topics.api_base', 'https://api.telegram.org'), '/');
        $token = (string) config('observability.telegram_topics.bot_token');
        $chatId = trim((string) config('observability.telegram_topics.chat_id'));
        $cleanCaption = mb_substr(trim(self::redact($caption)), 0, 950);
        $name = $downloadName ?: basename($filePath);

        $fields = ['chat_id' => $chatId];
        if ($cleanCaption !== '') {
            $fields['caption'] = $cleanCaption;
        }
        if ($threadId !== null) {
            $fields['message_thread_id'] = (string) $threadId;
        }
        if ($inlineKeyboard !== []) {
            $fields['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $contents = file_get_contents($filePath);
            if (! is_string($contents)) {
                return $failed;
            }

            $response = Http::withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(45)
                ->acceptJson()
                ->attach('document', $contents, $name)
                ->post($base.'/bot'.$token.'/sendDocument', $fields);

            $desc = strtolower((string) $response->json('description', ''));
            if (! ($response->successful() && $response->json('ok') === true)
                && $threadId !== null
                && $fallbackToGeneral
                && $response->status() === 400
                && str_contains($desc, 'thread')) {
                unset($fields['message_thread_id']);
                $response = Http::withoutRedirecting()
                    ->connectTimeout(5)
                    ->timeout(45)
                    ->acceptJson()
                    ->attach('document', $contents, $name)
                    ->post($base.'/bot'.$token.'/sendDocument', $fields);
            }

            if ($response->successful() && $response->json('ok') === true) {
                $msgId = $response->json('result.message_id');
                $resolvedChatId = (string) ($response->json('result.chat.id') ?? $chatId);
                if (is_numeric($msgId) && (int) $msgId > 0) {
                    return [
                        'ok' => true,
                        'chat_id' => $resolvedChatId,
                        'message_id' => (int) $msgId,
                    ];
                }
            }

            Log::warning('Telegram topic document with result failed', [
                'topic' => $topic,
                'http_status' => $response->status(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Telegram topic document with result transport error', [
                'topic' => $topic,
                'exception' => $e::class,
            ]);
        }

        return $failed;
    }

    /**
     * Delete a previously sent Telegram message safely (treating already-deleted messages as purged).
     */
    public function deleteMessage(string $chatId, int $messageId): bool
    {
        $token = (string) config('observability.telegram_topics.bot_token', '');
        $cleanChatId = trim($chatId);
        if (! $this->validToken($token) || $cleanChatId === '' || $messageId <= 0) {
            return false;
        }

        $base = rtrim((string) config('observability.telegram_topics.api_base', 'https://api.telegram.org'), '/');
        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(4)
                ->timeout(10)
                ->acceptJson()
                ->post($base.'/bot'.$token.'/deleteMessage', [
                    'chat_id' => $cleanChatId,
                    'message_id' => $messageId,
                ]);

            if ($response->successful() && $response->json('ok') === true) {
                return true;
            }

            $desc = strtolower((string) $response->json('description', ''));
            if ($response->status() === 400 && (
                str_contains($desc, 'message to delete not found')
                || str_contains($desc, 'message identifier is not specified')
                || str_contains($desc, "message can't be deleted")
            )) {
                Log::info('Telegram message already absent or non-deletable during purge', [
                    'message_id' => $messageId,
                ]);

                return true;
            }

            Log::warning('Telegram deleteMessage returned non-OK', [
                'http_status' => $response->status(),
                'message_id' => $messageId,
            ]);
        } catch (Throwable $e) {
            Log::warning('Telegram deleteMessage transport error', [
                'exception' => $e::class,
                'message_id' => $messageId,
            ]);
        }

        return false;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function resolveTrustedActionButton(string $cleanText, ?string $explicitUrl, ?string $explicitLabel): array
    {
        $candidate = $explicitUrl;
        if ($candidate === null) {
            if (preg_match('~https?://[^\s]+/(?:admin/[^\s]*|support(?:\?[^\s#]*)?(?:#[^\s]*)?)~u', $cleanText, $match)) {
                $candidate = $match[0];
            }
        }
        if ($candidate === null || $candidate === '') {
            return [null, ''];
        }

        $parts = parse_url($candidate);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! is_array($parts)
            || ($parts['host'] ?? null) !== $appHost
            || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return [null, ''];
        }

        $path = (string) ($parts['path'] ?? '');
        if (str_starts_with($path, '/admin/') && ! isset($parts['query']) && ! isset($parts['fragment'])) {
            return [$candidate, $explicitLabel ?: 'مشاهده در پنل مدیریت'];
        }

        if ($path === '/support') {
            $safeQueryOk = true;
            if (isset($parts['query'])) {
                parse_str($parts['query'], $q);
                foreach (array_keys($q) as $qk) {
                    if (! in_array($qk, ['conversation'], true)) {
                        $safeQueryOk = false;
                        break;
                    }
                }
            }
            if ($safeQueryOk) {
                return [$candidate, $explicitLabel ?: 'مشاهده و پاسخ در پنل چت'];
            }
        }

        return [null, ''];
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
