<?php

if (! function_exists('versioned_asset')) {
    /**
     * Generate a public asset URL with automatic `?v=<filemtime>` cache busting.
     * Falls back cleanly to `asset($path, $secure)` if the local file is missing.
     */
    function versioned_asset(string $path, ?bool $secure = null): string
    {
        $normalized = ltrim($path, '/');
        $cleanPath = strtok($normalized, '?#') ?: $normalized;
        $url = asset($cleanPath, $secure);
        $fullPath = public_path($cleanPath);

        if (is_file($fullPath)) {
            $mtime = @filemtime($fullPath);

            if (is_int($mtime) && $mtime > 0) {
                return $url.'?v='.$mtime;
            }
        }

        return $url;
    }
}
