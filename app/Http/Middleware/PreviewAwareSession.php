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

    private const READ_ONLY_CHAT_ENDPOINTS = [
        'support-widget.php',
        'support-content.php',
        'customer-notifications.php',
        'get_activity.php',
        'support-typing.php',
        'support-theme.php',
        'support-avatar.php',
        'operator-avatar.php',
        'support-profile-image.php',
        'support-attachment.php',
    ];

    public function handle($request, Closure $next)
    {
        $isReadMethod = $request->isMethod('GET') || $request->isMethod('HEAD');
        $this->readOnlyPreview = ($isReadMethod
            && $request->routeIs('admin.maintenance.preview', 'admin.maintenance.preview.page', 'support.chat.operator.asset', 'customer.header'))
            || ($isReadMethod && $this->isReadOnlyChatRequest($request));
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

    private function isReadOnlyChatRequest($request): bool
    {
        if (! $request->routeIs('support.chat')) {
            return false;
        }

        $path = (string) $request->route('path', '');
        if (in_array($path, self::READ_ONLY_CHAT_ENDPOINTS, true)) {
            return true;
        }

        if ($path !== 'api.php') {
            return false;
        }

        // Match the native API's isset dispatch, including Laravel-normalized nulls.
        // CSRF/bootstrap requests retain normal session writes even if paired with read flags.
        $query = $request->query->all();
        if (isset($query['get_csrf']) || isset($query['get_recovery_context']) || ($query['bootstrap'] ?? '') === '1') {
            return false;
        }

        return isset($query['widget_config'])
            || isset($query['recovery_availability'])
            || isset($query['get_conversation'])
            || isset($query['receipts'])
            || isset($query['get_users'])
            || isset($query['get_session']);
    }

    protected function collectGarbage(Session $session)
    {
        if (! $this->readOnlyPreview) {
            parent::collectGarbage($session);
        }
    }

    protected function storeCurrentUrl($request, $session)
    {
        // The widget and its background requests share the customer's Laravel session.
        if (! $request->routeIs('support.chat', 'support.panel.worker', 'customer.header')) {
            parent::storeCurrentUrl($request, $session);
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
