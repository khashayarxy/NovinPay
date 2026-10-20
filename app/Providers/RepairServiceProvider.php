<?php

namespace App\Providers;

use App\Support\RepairIdentity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RepairServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $response = fn (Request $request, array $headers) => response()->view('errors.429', [], 429, $headers);
        RateLimiter::for('repair-submit', function (Request $request) use ($response): array {
            $limits = [Limit::perHour(10)->by('submit-ip:'.RepairIdentity::digest('ip', $request->ip() ?? ''))->response($response)];
            $phone = RepairIdentity::phone($request->input('contact_phone'));
            if (preg_match('/\A09[0-9]{9}\z/', $phone)) {
                $limits[] = Limit::perHour(3)->by('submit-phone:'.RepairIdentity::digest('phone', $phone))->response($response);
            }

            return $limits;
        });
        RateLimiter::for('repair-track', fn (Request $request): array => [
            Limit::perMinute(10)->by('track-minute:'.RepairIdentity::digest('ip', $request->ip() ?? ''))->response($response),
            Limit::perHour(60)->by('track-hour:'.RepairIdentity::digest('ip', $request->ip() ?? ''))->response($response),
        ]);
    }
}
