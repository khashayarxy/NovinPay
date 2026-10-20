<?php

namespace App\Providers;

use App\Models\User;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only the persisted account provisioned through trusted configuration
        // may administer this single-admin application. Never trust request
        // fields or an in-memory email override; missing configuration denies.
        Gate::define('access-admin', function (User $user): bool {
            $email = config('admin.email');

            return is_string($email)
                && trim($email) !== ''
                && $user->exists
                && User::query()
                    ->whereKey($user->getAuthIdentifier())
                    ->where('email', trim($email))
                    ->exists();
        });

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
