<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PublicArticleResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (NotFoundHttpException) {
            $response = response()->view('errors.404', [], 404);
        }
        // No response/fragment cache, validators or 304s: each request checks the
        // current publication pointer. This also prevents caching a public 404.
        $response->headers->set('Cache-Control', 'no-store, max-age=0, must-revalidate');
        $response->headers->remove('ETag');
        $response->headers->remove('Last-Modified');
        $response->headers->set('X-Robots-Tag', $response->getStatusCode() === 200
            ? $request->attributes->get('public_article_robots', 'noindex, follow') : 'noindex, nofollow');

        return $response;
    }
}
