<?php

namespace App\Providers;

use App\Enums\AdminPermission;
use App\Events\OrderPaid;
use App\Events\RepairPaid;
use App\Events\RepairPaymentLinkGenerated;
use App\Http\Middleware\PreviewAwareSession;
use App\Models\User;
use App\Services\Notifications\UnifiedNotifier;
use App\Services\Observability\ObservabilityMetrics;
use App\Services\Observability\TelegramTopicNotifier;
use App\Support\AdminAccess;
use App\Support\Chat\ChatBridge;
use App\Support\CustomerAuthThrottle;
use App\Support\RepairIdentity;
use App\Support\SiteMaintenanceState;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        require_once app_path('Support/helpers.php');

        $this->app->singleton(ChatBridge::class);
        $this->app->singleton(SiteMaintenanceState::class, fn () => new SiteMaintenanceState(storage_path('framework/site-maintenance')));
        $this->app->singleton(PreviewAwareSession::class, fn ($app) => new PreviewAwareSession(
            $app->make(SessionManager::class), fn () => $app->make(CacheFactory::class)
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('customer-otp', fn ($request) => [
            Limit::perMinute(20)->by('otp-ip:'.hash('sha256', $request->ip()))->response(fn ($request, $headers) => CustomerAuthThrottle::respond($request, $headers, 'otp')),
            Limit::perHour(180)->by('otp-ip-hour:'.hash('sha256', $request->ip()))->response(fn ($request, $headers) => CustomerAuthThrottle::respond($request, $headers, 'otp')),
        ]);
        RateLimiter::for('customer-password', fn ($request) => [
            Limit::perMinute(8)->by('customer-password-ip:'.hash('sha256', $request->ip()))->response(fn ($request, $headers) => CustomerAuthThrottle::respond($request, $headers, 'password')),
            Limit::perMinute(5)->by('customer-password-phone:'.RepairIdentity::digest('password-throttle', (string) $request->session()->get('customer_login_phone')))->response(fn ($request, $headers) => CustomerAuthThrottle::respond($request, $headers, 'password')),
        ]);
        RateLimiter::for('customer-verify', fn ($request) => Limit::perMinute(20)->by(hash('sha256', $request->ip()))
            ->response(fn ($request, $headers) => CustomerAuthThrottle::respond($request, $headers, 'verify')));
        RateLimiter::for('product-feedback', function ($request): array {
            $ip = hash('sha256', $request->ip());
            $account = Auth::guard('customer')->id();

            return [
                Limit::perMinute(6)->by($account === null ? 'guest:'.$ip : 'account:'.$account),
                Limit::perMinute(30)->by('ip:'.$ip),
            ];
        });
        RateLimiter::for('client-telemetry', function ($request): Limit {
            $limit = max(1, (int) config('observability.client_telemetry.rate_limit_per_minute', 10));

            return Limit::perMinute($limit)->by('client-telemetry:'.hash('sha256', (string) $request->ip()));
        });
        Gate::define('access-admin', fn (User $user): bool => AdminAccess::account($user) !== null);
        Gate::define('staff.manage', fn (User $user): bool => AdminAccess::account($user)?->hasFullAccess() ?? false);
        foreach (AdminPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => AdminAccess::allows($user, $permission));
        }

        // When APP_URL is https, force generated URLs (and redirects built
        // from them) onto the https scheme, even if reverse-proxy /
        // TLS-termination headers are not perfectly forwarded. This does
        // NOT force HTTP->HTTPS redirects or mark the session cookie Secure:
        // enable the redirect at the hosting/proxy layer and set
        // SESSION_SECURE_COOKIE=true in .env for that.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Event::listen(OrderPaid::class, fn (OrderPaid $event) => app(UnifiedNotifier::class)->orderPaid($event->order, $event->document, $event->transaction));
        Event::listen(RepairPaid::class, fn (RepairPaid $event) => app(UnifiedNotifier::class)->repairPaid($event->repairCase, $event->document, $event->transaction));
        Event::listen(RepairPaymentLinkGenerated::class, fn (RepairPaymentLinkGenerated $event) => app(UnifiedNotifier::class)->repairPaymentLinkGenerated($event->repairCase, $event->document, $event->paymentUrl));

        $slowQueryThreshold = (int) config('observability.performance.slow_query_ms', 500);
        if ($slowQueryThreshold > 0) {
            DB::listen(function (QueryExecuted $query) use ($slowQueryThreshold): void {
                static $recording = false;
                if ($recording || $query->time < $slowQueryThreshold) {
                    return;
                }
                if (preg_match('/^\s*(?:create|alter|drop|truncate)\b/i', $query->sql)) {
                    return;
                }
                if (preg_match('/\bcache_locks\b/i', $query->sql)
                    && $query->time < max(5000, $slowQueryThreshold * 10)) {
                    return;
                }

                $recording = true;
                try {
                    // Never log raw bindings or literal values from SQL.
                    $shape = preg_replace("/'[^']*'/", "'?'", $query->sql) ?? $query->sql;
                    $shape = preg_replace('/\b\d+\b/', '?', $shape) ?? $shape;
                    $shape = mb_substr(preg_replace('/\s+/u', ' ', trim($shape)) ?? '', 0, 220);
                    $durationMs = (int) round($query->time);
                    $path = app()->runningInConsole() ? 'cli' : '/'.ltrim(request()->path(), '/');

                    Log::channel('performance')->warning('Slow DB query detected', [
                        'connection' => $query->connectionName,
                        'duration_ms' => $durationMs,
                        'path' => $path,
                        'sql_shape' => $shape,
                    ]);

                    app(ObservabilityMetrics::class)->increment('performance.slow_queries');
                    app(TelegramTopicNotifier::class)->send(
                        'performance',
                        "🐢 کوئری کند دیتابیس\nمدت: {$durationMs}ms\nاتصال: {$query->connectionName}\nمسیر: {$path}\nالگو: {$shape}",
                        'slow_query:'.md5($shape),
                    );
                } catch (Throwable) {
                } finally {
                    $recording = false;
                }
            });
        }
    }
}
