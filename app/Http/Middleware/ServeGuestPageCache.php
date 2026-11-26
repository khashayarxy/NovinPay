<?php

namespace App\Http\Middleware;

use App\Services\ShoppingCart;
use App\Support\GuestPageCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ServeGuestPageCache
{
    private const ALLOWED_PRODUCT_QUERY_KEYS = ['q', 'category', 'condition', 'available', 'sort', 'page'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isEligible($request)) {
            return $next($request);
        }

        $cacheKey = $this->buildKey($request);
        if ($cacheKey === null) {
            return $next($request);
        }

        $cachedHtml = GuestPageCache::get($cacheKey);
        if ($cachedHtml !== null) {
            $request->attributes->set('customer_header_rendered', true);

            return new Response($request->isMethod('HEAD') ? '' : $cachedHtml, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Guest-Cache' => 'HIT',
            ]);
        }

        $request->attributes->set('guest_public_read_only', true);
        $response = $next($request);

        $cookies = $response->headers->getCookies();
        if (count($cookies) === 1 && $cookies[0]->getName() === 'XSRF-TOKEN') {
            $response->headers->removeCookie(
                'XSRF-TOKEN',
                $cookies[0]->getPath(),
                $cookies[0]->getDomain(),
            );
        }

        if (
            $request->isMethod('GET')
            && $response->getStatusCode() === 200
            && str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')
            && $response->headers->getCookies() === []
            && ! Auth::guard('web')->check()
            && ! Auth::guard('customer')->check()
        ) {
            $content = $response->getContent();
            if (is_string($content) && $content !== '') {
                GuestPageCache::put($cacheKey, $content);
                $response->headers->set('X-Guest-Cache', 'MISS');
            }
        }

        return $response;
    }

    private function isEligible(Request $request): bool
    {
        if (app()->isDownForMaintenance()) {
            return false;
        }
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }
        if ($request->expectsJson() || $request->ajax() || $request->headers->has('Authorization')) {
            return false;
        }
        if (str_contains(strtolower((string) $request->headers->get('Cache-Control', '')), 'no-cache')) {
            return false;
        }

        $path = $request->path();
        if ($path !== '/' && $path !== 'products') {
            return false;
        }

        if ($request->cookies->has((string) config('session.cookie'))) {
            return false;
        }
        if ($request->cookies->has(ShoppingCart::DEVICE_COOKIE)) {
            return false;
        }
        foreach ($request->cookies->keys() as $cookieName) {
            if (str_starts_with((string) $cookieName, 'remember_')) {
                return false;
            }
        }

        if (Auth::guard('web')->check() || Auth::guard('customer')->check()) {
            return false;
        }

        if ($request->hasSession() && $request->session()->all() !== []) {
            return false;
        }

        if (config('session.driver') === 'array' && app()->bound('session.store')) {
            $storeData = app('session.store')->all();
            unset($storeData['_token'], $storeData['_previous']);
            if (isset($storeData['_flash']) && $storeData['_flash'] === ['old' => [], 'new' => []]) {
                unset($storeData['_flash']);
            }
            if ($storeData !== []) {
                return false;
            }
        }

        return true;
    }

    private function buildKey(Request $request): ?string
    {
        $path = $request->path();
        $query = $request->query->all();

        if ($path === '/') {
            if ($query !== []) {
                return null;
            }
            $normalizedQuery = '';
        } else {
            if (strlen((string) $request->getQueryString()) > 160) {
                return null;
            }
            $filtered = [];
            foreach ($query as $key => $value) {
                if (! in_array($key, self::ALLOWED_PRODUCT_QUERY_KEYS, true) || ! is_string($value) || strlen($value) > 100) {
                    return null;
                }
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    $filtered[$key] = $trimmed;
                }
            }
            ksort($filtered);
            $normalizedQuery = http_build_query($filtered);
        }

        $payload = implode('|', [
            $request->getSchemeAndHttpHost(),
            $path,
            $normalizedQuery,
            (string) config('support_chat.enabled'),
            (string) config('homepage.artwork.hero'),
            GuestPageCache::assetSignature(),
            GuestPageCache::version(),
        ]);

        return 'guest_page_cache:v1:'.hash('sha256', $payload);
    }
}
