<?php

namespace App\Http\Controllers;

use App\Services\Observability\ObservabilityMetrics;
use App\Services\Observability\TelegramTopicNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

final class ClientTelemetryController extends Controller
{
    public function store(Request $request, ObservabilityMetrics $metrics, TelegramTopicNotifier $topics): Response
    {
        if (strlen($request->getContent()) > 4096) {
            abort(413, 'Payload too large.');
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'source' => ['nullable', 'string', 'max:300'],
            'line' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'column' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'path' => ['nullable', 'string', 'max:300'],
            'kind' => ['nullable', 'in:error,unhandledrejection'],
        ]);

        $sanitize = static function (?string $value): ?string {
            if ($value === null || trim($value) === '') {
                return null;
            }
            $clean = preg_replace('/\?.*$/', '', trim($value)) ?? trim($value);
            $clean = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[REDACTED_EMAIL]', $clean) ?? $clean;
            $clean = preg_replace('/(?:\+98|0)9[0-9]{9}/', '[REDACTED_PHONE]', $clean) ?? $clean;

            return mb_substr($clean, 0, 300);
        };

        $message = $sanitize($data['message']) ?? 'Unknown client error';
        $source = $sanitize($data['source'] ?? null);
        $path = $sanitize($data['path'] ?? null) ?? '/';
        $kind = $data['kind'] ?? 'error';
        $line = $data['line'] ?? null;
        $column = $data['column'] ?? null;
        $userAgent = mb_substr((string) $request->userAgent(), 0, 200);
        $incidentId = (string) ($request->attributes->get('incident_id') ?? '');

        Log::channel('frontend')->warning('Client JS error', [
            'incident_id' => $incidentId,
            'kind' => $kind,
            'message' => $message,
            'source' => $source,
            'line' => $line,
            'column' => $column,
            'path' => $path,
            'user_agent' => $userAgent,
        ]);

        $metrics->increment('errors.client_js');

        $dedupeKey = 'client-js:'.hash('sha256', "{$kind}|{$message}|{$source}|{$line}");
        $topics->send(
            'errors',
            "🌐 خطای جاوااسکریپت سمت کاربر ({$kind})\nمسیر: {$path}\nپیام: {$message}".($source ? "\nمنبع: {$source}:{$line}" : '').($incidentId !== '' ? "\nکد رخداد: {$incidentId}" : ''),
            dedupeKey: $dedupeKey,
        );

        return response()->noContent();
    }
}
