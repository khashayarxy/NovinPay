<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class GuestPageCache
{
    private const VERSION_KEY = 'guest_page_cache:version';

    private const TTL_SECONDS = 300;

    public static function version(): string
    {
        try {
            $version = Cache::get(self::VERSION_KEY);
            if (is_string($version) && $version !== '') {
                return $version;
            }
            $fresh = (string) Str::uuid();
            Cache::forever(self::VERSION_KEY, $fresh);

            return $fresh;
        } catch (Throwable) {
            return 'unversioned';
        }
    }

    public static function bump(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, (string) Str::uuid());
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(static function (): void {
                    try {
                        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
                    } catch (Throwable) {
                    }
                });
            }
        } catch (Throwable) {
            // Never fail a product/inventory transaction if cache storage is unavailable.
        }
    }

    public static function get(string $key): ?string
    {
        try {
            $cached = Cache::get($key);

            return is_string($cached) && $cached !== '' ? $cached : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function put(string $key, string $html): void
    {
        try {
            Cache::put($key, $html, self::TTL_SECONDS);
        } catch (Throwable) {
        }
    }

    public static function assetSignature(): string
    {
        $files = [
            public_path('build/manifest.json'),
            public_path('build/support-chat/manifest.json'),
            public_path('css/storefront-refinement.css'),
            public_path('css/store-navigation.css'),
            public_path('js/storefront-premium.js'),
        ];

        $parts = [];
        foreach ($files as $file) {
            $parts[] = is_file($file) ? (string) @filemtime($file) : '0';
        }

        return substr(hash('sha256', implode(':', $parts)), 0, 16);
    }
}
