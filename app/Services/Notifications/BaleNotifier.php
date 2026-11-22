<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class BaleNotifier
{
    public function enabled(): bool
    {
        return (bool) config('notifications.bale.enabled', false)
            && $this->validToken((string) config('notifications.bale.bot_token', ''))
            && trim((string) config('notifications.bale.chat_id', '')) !== '';
    }

    public function send(string $text): bool
    {
        if (! $this->enabled() || trim($text) === '') {
            return false;
        }

        $base = rtrim((string) config('notifications.bale.api_base', 'https://tapi.bale.ai'), '/');
        $token = (string) config('notifications.bale.bot_token');
        $chatId = trim((string) config('notifications.bale.chat_id'));

        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(5)
                ->acceptJson()
                ->post($base.'/bot'.$token.'/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);

            if ($response->successful() && $response->json('ok') === true) {
                return true;
            }

            Log::warning('Bale notification failed', [
                'http_status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
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
