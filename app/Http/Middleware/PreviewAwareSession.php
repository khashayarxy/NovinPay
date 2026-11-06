<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

// Explicit read-only responses still read authenticated sessions but never
// persist them. All authentication and mutating endpoints keep normal semantics.
class PreviewAwareSession extends StartSession
{
    private bool $readOnlyPreview = false;

    public function handle($request, Closure $next)
    {
        // Match the native API's isset dispatch, including Laravel-normalized nulls.
        $query = $request->query->all();
        $publicChatConfiguration = $request->isMethod('GET') && $request->routeIs('support.chat')
            && $request->route('path') === 'api.php'
            && (isset($query['widget_config']) || isset($query['recovery_availability']));
        $this->readOnlyPreview = (($request->isMethod('GET') || $request->isMethod('HEAD'))
            && $request->routeIs('admin.maintenance.preview', 'admin.maintenance.preview.page', 'support.chat.operator.asset'))
            || $publicChatConfiguration;
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
