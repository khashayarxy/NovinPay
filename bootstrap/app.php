<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Support\SafeDatabaseErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AddSecurityHeaders::class);

        $middleware->redirectGuestsTo('/admin/login');
        $middleware->redirectUsersTo('/admin');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception) {
            $query = SafeDatabaseErrors::query($exception);
            if (! $query) {
                return null;
            }
            // Never report the SQL, bindings, original exception, request or trace.
            $state = $query->errorInfo[0] ?? null;
            Log::error('Database operation failed; SQL and bindings withheld.', ['sqlstate' => is_string($state) && preg_match('/\A[A-Z0-9]{5}\z/', $state) ? $state : 'unknown']);

            return false;
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! SafeDatabaseErrors::query($exception)) {
                if ($exception instanceof HttpException && $request->is('admin/repairs*', 'admin/customers*', 'admin/consultations*', 'admin/inventory*', 'admin/orders*', 'admin/finance*') && in_array($exception->getStatusCode(), [409, 422], true)) {
                    $message = $exception->getMessage() ?: 'اطلاعات عملیات را بررسی و پرونده را دوباره باز کنید.';

                    $view = $request->is('admin/repairs*') ? 'admin.repair-cases.error' : 'admin.crm-error';

                    return $request->expectsJson() ? response()->json(['message' => $message], $exception->getStatusCode()) : response()->view($view, ['status' => $exception->getStatusCode(), 'message' => $message], $exception->getStatusCode());
                }

                return null;
            }

            return $request->expectsJson() ? response()->json(['message' => 'پردازش درخواست انجام نشد. دوباره تلاش کنید.'], 500) : response()->view('errors.500', [], 500);
        });
    })->create();
