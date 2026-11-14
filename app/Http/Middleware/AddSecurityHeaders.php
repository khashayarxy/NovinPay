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

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', $response->headers->get('X-Frame-Options') === 'DENY' ? 'DENY' : 'SAMEORIGIN');
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            // Five-minute host-only first rollout. No preload/subdomain commitment.
            $response->headers->set('Strict-Transport-Security', 'max-age=300');
        }
        if (! $response->headers->has('Permissions-Policy')) {
            $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(self), payment=(), usb=(), serial=(), accelerometer=(), gyroscope=(), magnetometer=()');
        }
        if (str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            if (! $response->headers->has('Content-Security-Policy')) {
                $response->headers->set('Content-Security-Policy', "object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
            }
            if (! $response->headers->has('Content-Security-Policy-Report-Only')) {
                // Console-only rollout: no public report endpoint or payload logs.
                // Existing operator/widget enforcement remains authoritative.
                $response->headers->set('Content-Security-Policy-Report-Only', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://trustseal.enamad.ir; font-src 'self'; connect-src 'self'; frame-src 'self' https://neshan.org; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'");
            }
        }

        return $response;
    }
}
