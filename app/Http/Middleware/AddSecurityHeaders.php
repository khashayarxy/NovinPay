<?php

namespace App\Http\Middleware;

use App\Support\PaymentTestProduct;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            // Long-lived HSTS with preload in production; preserve short rollout default in non-production/tests.
            $hsts = app()->environment('production')
                ? 'max-age=31536000; includeSubDomains; preload'
                : 'max-age=300';
            $response->headers->set('Strict-Transport-Security', $hsts);
        }
        if (! $response->headers->has('Permissions-Policy')) {
            $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(self), payment=(), usb=(), serial=(), accelerometer=(), gyroscope=(), magnetometer=()');
        }
        if (str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            if (! $response->headers->has('Cross-Origin-Opener-Policy')) {
                $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
            }
            if ($request->attributes->get('customer_header_rendered')) {
                $existingCacheControl = strtolower((string) $response->headers->get('Cache-Control', ''));
                $isGuestBfcacheEligible = ($request->isMethod('GET') || $request->isMethod('HEAD'))
                    && $response->getStatusCode() === 200
                    && ! str_contains($existingCacheControl, 'no-store')
                    && ! (bool) $request->attributes->get('customer_authenticated')
                    && ! Auth::guard('web')->check()
                    && ! Auth::guard('customer')->check()
                    && ($request->routeIs('home', 'products.index', 'products.show', 'products.comparison', 'contact', 'terms', 'privacy')
                        || in_array($request->path(), ['/', 'products', 'product-comparison', 'contact', 'terms', 'privacy'], true))
                    && ! ($request->routeIs('products.show') && PaymentTestProduct::isTestProduct($request->route('product')));

                $response->headers->set(
                    'Cache-Control',
                    $isGuestBfcacheEligible ? 'private, no-cache, must-revalidate' : 'no-store, private',
                );
                $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
            } elseif (! $response->headers->has('X-LiteSpeed-Cache-Control')) {
                $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
            }
            $cspPolicy = "default-src 'self'; script-src 'self' https://static.neshan.org; style-src 'self' 'unsafe-inline' https://static.neshan.org; img-src 'self' data: blob: https://trustseal.enamad.ir https://*.neshan.org; font-src 'self' data: https://static.neshan.org; connect-src 'self' https://*.neshan.org https://www.cloudflare.com https://1.1.1.1; frame-src 'self' https://neshan.org; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self' https://gateway.zibal.ir";
            if (! $response->headers->has('Content-Security-Policy')) {
                $response->headers->set('Content-Security-Policy', $cspPolicy);
            }
            if (! $response->headers->has('Content-Security-Policy-Report-Only')) {
                // Console-only Trusted Types sink audit without public report endpoint or runtime breakage.
                $response->headers->set('Content-Security-Policy-Report-Only', $cspPolicy."; require-trusted-types-for 'script'; trusted-types default");
            }
        }

        return $response;
    }
}
