<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TelegramNotifier
{
    public function enabled(): bool
    {
        return (bool) config('notifications.telegram.enabled', false)
            && $this->validToken((string) config('notifications.telegram.bot_token', ''))
            && trim((string) config('notifications.telegram.chat_id', '')) !== '';
    }

    public function send(string $text, ?string $buttonUrl = null, ?string $buttonLabel = null): bool
    {
        if (! $this->enabled() || trim($text) === '') {
            return false;
        }

        $base = rtrim((string) config('notifications.telegram.api_base', 'https://api.telegram.org'), '/');
        $token = (string) config('notifications.telegram.bot_token');
        $chatId = trim((string) config('notifications.telegram.chat_id'));

        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(5)
                ->acceptJson()
                ->post($base.'/bot'.$token.'/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                    ...($buttonUrl !== null && $buttonLabel !== null ? [
                        'reply_markup' => ['inline_keyboard' => [[['text' => $buttonLabel, 'url' => $buttonUrl]]]],
                    ] : []),
                ]);

            if ($response->successful() && $response->json('ok') === true) {
                return true;
            }

            Log::warning('Telegram notification failed', [
                'http_status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram notification transport error', [
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
