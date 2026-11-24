<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Browser controls; preserve stricter endpoint-specific attachment/chat CSPs. */
class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->getHost() === 'novinpaypos.test') {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', $response->headers->get('X-Frame-Options') === 'DENY' ? 'DENY' : 'SAMEORIGIN');
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            // Long-lived HSTS in production; preserve short rollout default in non-production/tests.
            $hsts = app()->environment('production')
                ? 'max-age=31536000; includeSubDomains'
                : 'max-age=300';
            $response->headers->set('Strict-Transport-Security', $hsts);
        }
        if (! $response->headers->has('Permissions-Policy')) {
            $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(self), payment=(), usb=(), serial=(), accelerometer=(), gyroscope=(), magnetometer=()');
        }
        if (str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            if ($request->attributes->get('customer_header_rendered')) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
            } elseif (! $response->headers->has('X-LiteSpeed-Cache-Control')) {
                $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
            }
            if (! $response->headers->has('Content-Security-Policy')) {
                $response->headers->set('Content-Security-Policy', "object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
            }
            if (! $response->headers->has('Content-Security-Policy-Report-Only')) {
                // Console-only rollout: no public report endpoint or payload logs.
                // Existing operator/widget enforcement remains authoritative.
                $response->headers->set('Content-Security-Policy-Report-Only', "default-src 'self'; script-src 'self' https://static.neshan.org; style-src 'self' 'unsafe-inline' https://static.neshan.org; img-src 'self' data: blob: https://trustseal.enamad.ir https://*.neshan.org; font-src 'self' data: https://static.neshan.org; connect-src 'self' https://*.neshan.org https://www.cloudflare.com https://1.1.1.1; frame-src 'self' https://neshan.org; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'");
            }
        }

        return $response;
    }
}
