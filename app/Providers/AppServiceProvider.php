<?php

namespace App\Providers;

use App\Enums\AdminPermission;
use App\Http\Middleware\PreviewAwareSession;
use App\Models\User;
use App\Support\AdminAccess;
use App\Support\SiteMaintenanceState;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        Gate::define('access-admin', fn (User $user): bool => AdminAccess::account($user) !== null);
        Gate::define('staff.manage', fn (User $user): bool => AdminAccess::account($user)?->isSuperAdmin() ?? false);
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
    }
}
