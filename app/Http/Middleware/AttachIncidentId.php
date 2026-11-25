<?php

namespace App\Http\Middleware;

use App\Services\Observability\ObservabilityMetrics;
use App\Services\Observability\TelegramTopicNotifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class AttachIncidentId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incidentId = $request->attributes->get('incident_id');
        if (! is_string($incidentId) || $incidentId === '') {
            $incidentId = 'E'.now()->format('ymd').'-'.bin2hex(random_bytes(3));
            $request->attributes->set('incident_id', $incidentId);
        }

        Log::withContext(['incident_id' => $incidentId]);

        $started = hrtime(true);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Id', $incidentId);

        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $threshold = (int) config('observability.performance.slow_request_ms', 2000);
        $anomalousRedirect = $response->isRedirection() && $durationMs >= max(10000, $threshold * 5);
        if ($threshold > 0 && $durationMs >= $threshold && (! $response->isRedirection() || $anomalousRedirect)) {
            $path = '/'.ltrim($request->path(), '/');
            Log::channel('performance')->warning('Slow HTTP request detected', [
                'incident_id' => $incidentId,
                'method' => $request->method(),
                'path' => $path,
                'status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
            ]);
            app(ObservabilityMetrics::class)->increment('performance.slow_requests');
            app(TelegramTopicNotifier::class)->send(
                'performance',
                "⏱ درخواست کند HTTP\nمسیر: {$request->method()} {$path}\nزمان: {$durationMs}ms | وضعیت: {$response->getStatusCode()}\nکد رخداد: {$incidentId}",
                dedupeKey: "slow-http:{$request->method()}:{$path}",
            );
        }

        return $response;
    }
}
