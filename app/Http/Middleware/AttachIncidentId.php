<?php

namespace App\Http\Middleware;

use App\Services\Observability\PerformanceAlerts;
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

        $request->attributes->set('performance_started', hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $threshold = (int) config('observability.performance.slow_request_ms', 2000);
        $duration = (float) $request->attributes->get('performance_response_ms', 0);
        if ($threshold <= 0 || $duration < $threshold) {
            return;
        }
        if ($response->isRedirection() && $request->isMethodSafe() === false && $duration < max(10000, $threshold * 5)) {
            return;
        }

        app(PerformanceAlerts::class)->record('requests', 'Slow HTTP request detected', [
            'incident_id' => $request->attributes->get('incident_id', 'unknown'),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->route()?->uri() ?? 'unmatched', '/'),
            'status' => $response->getStatusCode(),
            'duration_ms' => round($duration, 1),
            'bootstrap_ms' => $request->attributes->get('performance_bootstrap_ms'),
            'database_ms' => round((float) $request->attributes->get('performance_sql_ms', 0), 1),
            'other_ms' => round(max(0, $duration - $request->attributes->get('performance_bootstrap_ms', 0) - $request->attributes->get('performance_sql_ms', 0)), 1),
            'query_count' => $request->attributes->get('performance_query_count', 0),
        ]);
    }
}
