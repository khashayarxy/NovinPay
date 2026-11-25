<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AttachIncidentId;
use App\Http\Middleware\NormalizeNumericDigits;
use App\Http\Middleware\PreviewAwareSession;
use App\Http\Middleware\PublicSiteMaintenance;
use App\Services\Observability\ObservabilityMetrics;
use App\Services\Observability\TelegramTopicNotifier;
use App\Support\SafeDatabaseErrors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(PublicSiteMaintenance::class);
        $middleware->prepend(AttachIncidentId::class);
        $middleware->web(prepend: [NormalizeNumericDigits::class]);
        $middleware->web(replace: [StartSession::class => PreviewAwareSession::class]);
        $middleware->append(AddSecurityHeaders::class);

        $middleware->redirectGuestsTo('/admin/login');
        $middleware->redirectUsersTo('/admin');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['code', 'phone', 'address', 'postal_code', 'recipient', 'amount', 'reference', 'bank_reference', 'note']);
        $exceptions->respond(function (Response $response): Response {
            $incidentId = request()?->attributes->get('incident_id');
            if (is_string($incidentId) && $incidentId !== '' && ! $response->headers->has('X-Request-Id')) {
                $response->headers->set('X-Request-Id', $incidentId);
            }
            if ($response->getStatusCode() === 503) {
                $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
                if (! $response->headers->has('Retry-After')) {
                    $response->headers->set('Retry-After', '60');
                }
            }

            return $response;
        });
        $exceptions->report(function (Throwable $exception) {
            $query = SafeDatabaseErrors::query($exception);
            if ($query) {
                // Never report the SQL, bindings, original exception, request or trace.
                $state = $query->errorInfo[0] ?? null;
                $sqlState = is_string($state) && preg_match('/\A[A-Z0-9]{5}\z/', $state) ? $state : 'unknown';
                Log::error('Database operation failed; SQL and bindings withheld.', ['sqlstate' => $sqlState]);
                try {
                    app(ObservabilityMetrics::class)->increment('errors.http_500');
                    $incidentId = request()?->attributes->get('incident_id') ?? 'cli';
                    $path = request() ? '/'.ltrim(request()->path(), '/') : 'cli';
                    app(TelegramTopicNotifier::class)->send(
                        'errors',
                        "🚨 خطای پایگاه داده (500)\nکد پیگیری: {$incidentId}\nمسیر: {$path}\nSQLSTATE: {$sqlState}",
                        "db_error:{$sqlState}:{$path}",
                    );
                } catch (Throwable) {
                }

                return false;
            }

            if ($exception instanceof ValidationException
                || $exception instanceof AuthenticationException
                || $exception instanceof AuthorizationException
                || $exception instanceof ModelNotFoundException
                || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500)
            ) {
                return null;
            }

            try {
                app(ObservabilityMetrics::class)->increment('errors.http_500');
                $incidentId = request()?->attributes->get('incident_id') ?? 'cli';
                $path = request() ? '/'.ltrim(request()->path(), '/') : 'cli';
                $class = class_basename($exception);
                $summary = mb_substr(preg_replace('/\s+/u', ' ', trim($exception->getMessage())) ?? '', 0, 180);
                app(TelegramTopicNotifier::class)->send(
                    'errors',
                    "🚨 خطای سرور (500)\nکد پیگیری: {$incidentId}\nنوع: {$class}\nمسیر: {$path}\nپیام: {$summary}",
                    "http500:{$class}:{$path}",
                );
            } catch (Throwable) {
            }

            return null;
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! SafeDatabaseErrors::query($exception)) {
                if ($exception instanceof HttpException && $request->is('admin/repairs*', 'admin/customers*', 'admin/consultations*', 'admin/inventory*', 'admin/orders*', 'admin/finance*', 'admin/articles*', 'admin/article-categories*') && in_array($exception->getStatusCode(), [409, 422], true)) {
                    $message = $exception->getMessage() ?: 'اطلاعات عملیات را بررسی و پرونده را دوباره باز کنید.';

                    $view = $request->is('admin/repairs*') ? 'admin.repair-cases.error' : 'admin.crm-error';

                    return $request->expectsJson() ? response()->json(['message' => $message], $exception->getStatusCode()) : response()->view($view, ['status' => $exception->getStatusCode(), 'message' => $message], $exception->getStatusCode());
                }

                return null;
            }

            return $request->expectsJson() ? response()->json(['message' => 'پردازش درخواست انجام نشد. دوباره تلاش کنید.'], 500) : response()->view('errors.500', [], 500);
        });
    })->create();
