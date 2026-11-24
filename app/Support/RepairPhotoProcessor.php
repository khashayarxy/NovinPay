<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

class RepairPhotoProcessor
{
    public const MAX_DIMENSION = 1280;

    public const WEBP_QUALITY = 78;

    public const JPEG_QUALITY = 80;

    /**
     * Resize and compress an uploaded device photo using PHP GD while keeping
     * a safe raw-bytes fallback for tiny or non-GD test fixtures.
     *
     * @return array{bytes: string, extension: string}
     */
    public static function compress(UploadedFile $file): array
    {
        $raw = @file_get_contents($file->getRealPath() ?: $file->getPathname());
        if (! is_string($raw) || $raw === '') {
            return ['bytes' => '', 'extension' => 'jpg'];
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagesx') || ! function_exists('imagesy')) {
            return ['bytes' => $raw, 'extension' => self::fallbackExtension($file)];
        }

        $source = @imagecreatefromstring($raw);
        if ($source === false) {
            return ['bytes' => $raw, 'extension' => self::fallbackExtension($file)];
        }

        try {
            $source = self::orientFromExif($source, $file);
            $width = imagesx($source);
            $height = imagesy($source);

            if ($width <= 0 || $height <= 0) {
                return ['bytes' => $raw, 'extension' => self::fallbackExtension($file)];
            }

            $target = $source;
            $largest = max($width, $height);
            if ($largest > self::MAX_DIMENSION) {
                $scale = self::MAX_DIMENSION / $largest;
                $newWidth = max(1, (int) round($width * $scale));
                $newHeight = max(1, (int) round($height * $scale));
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                if ($resized !== false) {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                    imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                    $target = $resized;
                }
            }

            try {
                if (function_exists('imagewebp')) {
                    imagepalettetotruecolor($target);
                    imagealphablending($target, true);
                    imagesavealpha($target, true);
                    ob_start();
                    $ok = @imagewebp($target, null, self::WEBP_QUALITY);
                    $encoded = ob_get_clean();
                    if ($ok && is_string($encoded) && $encoded !== '') {
                        return ['bytes' => $encoded, 'extension' => 'webp'];
                    }
                }

                if (function_exists('imagejpeg')) {
                    $flat = imagecreatetruecolor(imagesx($target), imagesy($target));
                    if ($flat !== false) {
                        try {
                            $white = imagecolorallocate($flat, 255, 255, 255);
                            imagefill($flat, 0, 0, $white);
                            imagecopy($flat, $target, 0, 0, 0, 0, imagesx($target), imagesy($target));
                            ob_start();
                            $ok = @imagejpeg($flat, null, self::JPEG_QUALITY);
                            $encoded = ob_get_clean();
                            if ($ok && is_string($encoded) && $encoded !== '') {
                                return ['bytes' => $encoded, 'extension' => 'jpg'];
                            }
                        } finally {
                            imagedestroy($flat);
                        }
                    }
                }
            } finally {
                if ($target !== $source) {
                    imagedestroy($target);
                }
            }
        } finally {
            imagedestroy($source);
        }

        return ['bytes' => $raw, 'extension' => self::fallbackExtension($file)];
    }

    private static function orientFromExif(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! function_exists('imagerotate')) {
            return $image;
        }

        $path = $file->getRealPath() ?: $file->getPathname();
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = @imagerotate($image, $angle, 0);
        if ($rotated !== false) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    private static function fallbackExtension(UploadedFile $file): string
    {
        $ext = strtolower((string) $file->guessExtension());

        return in_array($ext, ['webp', 'jpg', 'jpeg', 'png'], true) ? ($ext === 'jpeg' ? 'jpg' : $ext) : 'jpg';
    }
}
