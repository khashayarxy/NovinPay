<?php

namespace App\Http\Middleware;

use App\Support\MaintenanceStateFault;
use App\Support\SiteMaintenanceState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicSiteMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (SiteMaintenanceState::adminCorridor($request->server->get('REQUEST_URI', '/'))) {
            return $next($request);
        }
        try {
            $state = app(SiteMaintenanceState::class)->read();
        } catch (MaintenanceStateFault) {
            $state = ['enabled' => true];
        }
        if (! $state['enabled']) {
            return $next($request);
        }
        $payload = SiteMaintenanceState::response($state, base_path(), $request->expectsJson());

        return new Response($request->isMethod('HEAD') ? '' : $payload['body'], 503, $payload['headers']);
    }
}
