<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

// Only the two new preview GETs are read-only. Every other route delegates
// unchanged to Laravel's existing session lifecycle.
class PreviewAwareSession extends StartSession
{
    private bool $readOnlyPreview = false;

    public function handle($request, Closure $next)
    {
        $this->readOnlyPreview = ($request->isMethod('GET') || $request->isMethod('HEAD'))
            && $request->routeIs('admin.maintenance.preview', 'admin.maintenance.preview.page');
        try {
            if ($this->readOnlyPreview && $this->sessionConfigured()) {
                // A read-only session needs no persistent cache lock either.
                return $this->handleStatefulRequest($request, $this->getSession($request), $next);
            }

            return parent::handle($request, $next);
        } finally {
            $this->readOnlyPreview = false;
        }
    }

    protected function collectGarbage(Session $session)
    {
        if (! $this->readOnlyPreview) {
            parent::collectGarbage($session);
        }
    }

    protected function saveSession($request)
    {
        if (! $this->readOnlyPreview) {
            parent::saveSession($request);
        }
    }

    protected function addCookieToResponse(Response $response, Session $session)
    {
        if (! $this->readOnlyPreview) {
            parent::addCookieToResponse($response, $session);
        }
    }
}
