<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ProductMediaPublisher
{
    public const PUBLIC_SUBDIR = 'media-cache/products';

    private const RESPONSIVE_WIDTHS = [160, 400];

    public static function shouldPublish(Product $product): bool
    {
        return $product->is_published
            && ! $product->is_sample
            && ! $product->isPrivatePaymentTest();
    }

    public static function isRealPublicDisk(): bool
    {
        $configured = rtrim(str_replace('\\', '/', Storage::disk('public')->path('')), '/');
        $expected = rtrim(str_replace('\\', '/', storage_path('app/public')), '/');

        return $configured !== '' && $configured === $expected;
    }

    public static function syncProduct(Product $product, bool $force = false): void
    {
        if (! $force && ! self::isRealPublicDisk()) {
            return;
        }

        if (! self::shouldPublish($product)) {
            self::unpublishProduct($product);

            return;
        }

        self::ensureDirectory();
        foreach (self::productPaths($product) as $relativePath) {
            self::publishImage($relativePath);
        }
    }

    public static function unpublishProduct(Product $product): void
    {
        foreach (self::productPaths($product) as $relativePath) {
            self::removePublishedImage($relativePath);
        }
    }

    public static function removePublishedImage(?string $relativePath): void
    {
        $filename = self::safeFilename($relativePath);
        if ($filename === null) {
            return;
        }

        $dir = public_path(self::PUBLIC_SUBDIR);
        if (! is_dir($dir)) {
            return;
        }

        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $candidates = [$filename];
        foreach (self::RESPONSIVE_WIDTHS as $w) {
            $candidates[] = "{$stem}-{$w}w.webp";
        }

        foreach ($candidates as $file) {
            $target = $dir.DIRECTORY_SEPARATOR.$file;
            if (is_file($target) && ! is_link($target)) {
                @unlink($target);
            }
        }
    }

    /**
     * @return array{src: string, srcset: ?string, full: string}|null
     */
    public static function resolveStatic(?string $relativePath, ?int $originalWidth = null): ?array
    {
        $filename = self::safeFilename($relativePath);
        if ($filename === null) {
            return null;
        }

        $dir = public_path(self::PUBLIC_SUBDIR);
        $fullFile = $dir.DIRECTORY_SEPARATOR.$filename;
        if (! is_file($fullFile) || is_link($fullFile)) {
            return null;
        }

        $mtime = (int) @filemtime($fullFile);
        $versionSuffix = $mtime > 0 ? '?v='.$mtime : '';
        $fullUrl = asset(self::PUBLIC_SUBDIR.'/'.$filename).$versionSuffix;

        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $entries = [];
        $defaultSrc = null;

        foreach (self::RESPONSIVE_WIDTHS as $w) {
            $variantName = "{$stem}-{$w}w.webp";
            $variantPath = $dir.DIRECTORY_SEPARATOR.$variantName;
            if (is_file($variantPath) && ! is_link($variantPath)) {
                $vUrl = asset(self::PUBLIC_SUBDIR.'/'.$variantName).$versionSuffix;
                $entries[] = "{$vUrl} {$w}w";
                if ($w === 400) {
                    $defaultSrc = $vUrl;
                }
            }
        }

        if ($entries !== []) {
            $fullW = ($originalWidth && $originalWidth > 400) ? $originalWidth : 1200;
            $entries[] = "{$fullUrl} {$fullW}w";
        }

        return [
            'src' => $defaultSrc ?? $fullUrl,
            'srcset' => $entries !== [] ? implode(', ', $entries) : null,
            'full' => $fullUrl,
        ];
    }

    public static function syncAllPublished(bool $force = true): int
    {
        self::ensureDirectory();
        $validFiles = [];
        $count = 0;

        foreach (Product::query()->cursor() as $product) {
            if (! self::shouldPublish($product)) {
                self::unpublishProduct($product);

                continue;
            }

            foreach (self::productPaths($product) as $relativePath) {
                $published = self::publishImage($relativePath);
                foreach ($published as $name) {
                    $validFiles[$name] = true;
                }
                if ($published !== []) {
                    $count++;
                }
            }
        }

        // Prune stale/orphaned files (except .htaccess)
        $dir = public_path(self::PUBLIC_SUBDIR);
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === '.htaccess') {
                    continue;
                }
                $full = $dir.DIRECTORY_SEPARATOR.$entry;
                if (is_file($full) && ! isset($validFiles[$entry])) {
                    @unlink($full);
                }
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private static function productPaths(Product $product): array
    {
        $paths = [];
        if (is_string($product->image_path) && $product->image_path !== '') {
            $paths[] = $product->image_path;
        }
        foreach ($product->galleryImages() as $item) {
            if (is_string($item['path'] ?? null) && $item['path'] !== '') {
                $paths[] = $item['path'];
            }
        }

        return array_values(array_unique($paths));
    }

    private static function safeFilename(?string $relativePath): ?string
    {
        if (! is_string($relativePath) || $relativePath === '') {
            return null;
        }

        $base = basename($relativePath);
        if (! preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\.(?:jpg|jpeg|png|webp)\z/i', $base)) {
            return null;
        }

        return $base;
    }

    private static function ensureDirectory(): void
    {
        $dir = public_path(self::PUBLIC_SUBDIR);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $htaccess = $dir.DIRECTORY_SEPARATOR.'.htaccess';
        if (! is_file($htaccess)) {
            $rules = <<<'HTACCESS'
Options -Indexes
<FilesMatch "\.(?:php|phtml|phar|pl|py|cgi|sh)$">
    Require all denied
</FilesMatch>
<IfModule mod_headers.c>
    Header set Cache-Control "public, max-age=31536000, immutable"
    Header set X-Content-Type-Options "nosniff"
</IfModule>
HTACCESS;
            @file_put_contents($htaccess, $rules."\n");
            @chmod($htaccess, 0644);
        }
    }

    /**
     * @return list<string>
     */
    private static function publishImage(string $relativePath): array
    {
        $filename = self::safeFilename($relativePath);
        if ($filename === null) {
            return [];
        }

        $source = Storage::disk('public')->path('products/'.$filename);
        if (! is_file($source) || is_link($source)) {
            return [];
        }

        $dir = public_path(self::PUBLIC_SUBDIR);
        $destOriginal = $dir.DIRECTORY_SEPARATOR.$filename;
        if (! is_file($destOriginal) || @filesize($destOriginal) !== @filesize($source)) {
            @copy($source, $destOriginal);
            @chmod($destOriginal, 0644);
        }

        $published = [$filename];
        if (! function_exists('imagewebp')) {
            return $published;
        }

        try {
            $info = @getimagesize($source);
            if (! is_array($info) || ($info[0] ?? 0) <= 0 || ($info[1] ?? 0) <= 0) {
                return $published;
            }
            [$srcW, $srcH, $type] = $info;
            $stem = pathinfo($filename, PATHINFO_FILENAME);

            $srcImg = null;
            foreach (self::RESPONSIVE_WIDTHS as $targetW) {
                if ($srcW <= $targetW) {
                    continue;
                }
                $variantName = "{$stem}-{$targetW}w.webp";
                $variantPath = $dir.DIRECTORY_SEPARATOR.$variantName;
                if (is_file($variantPath) && @filesize($variantPath) > 0) {
                    $published[] = $variantName;

                    continue;
                }

                if ($srcImg === null) {
                    $srcImg = match ($type) {
                        IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
                        IMAGETYPE_PNG => @imagecreatefrompng($source),
                        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
                        default => false,
                    };
                    if (! $srcImg) {
                        break;
                    }
                }

                $targetH = max(1, (int) round(($srcH * $targetW) / $srcW));
                $canvas = imagecreatetruecolor($targetW, $targetH);
                if (! $canvas) {
                    continue;
                }
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
                imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $transparent);
                imagecopyresampled($canvas, $srcImg, 0, 0, 0, 0, $targetW, $targetH, $srcW, $srcH);
                if (@imagewebp($canvas, $variantPath, 82)) {
                    @chmod($variantPath, 0644);
                    $published[] = $variantName;
                }
                imagedestroy($canvas);
            }

            if ($srcImg) {
                imagedestroy($srcImg);
            }
        } catch (Throwable) {
            // Degrade gracefully to original image if GD fails on an unusual input.
        }

        return $published;
    }
}
