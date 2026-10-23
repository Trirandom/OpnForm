<?php

namespace App\Service\Storage;

use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;

/**
 * Resizes and re-encodes submitted images to WebP to keep storage usage low.
 */
class ImageOptimizer
{
    /**
     * Extensions we can safely decode with GD. GIFs are excluded to keep animations,
     * SVG/HEIC are not supported by GD.
     */
    public const OPTIMIZABLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'bmp'];

    public static function enabled(): bool
    {
        return (bool) config('opnform.image_optimization.enabled', true);
    }

    public static function isOptimizable(?string $fileName): bool
    {
        if (!self::enabled() || empty($fileName)) {
            return false;
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return in_array($extension, self::OPTIMIZABLE_EXTENSIONS, true);
    }

    /**
     * Returns the WebP encoded image, or null when the image can't (or shouldn't) be optimized.
     */
    public static function optimize(string $binary, ?string $fileName = null, ?int $maxWidth = null): ?string
    {
        try {
            $image = ImageManager::gd(autoOrientation: true)->read($binary);
            $image->scaleDown(width: $maxWidth ?? (int) config('opnform.image_optimization.max_width', 720));
            $encoded = (string) $image->toWebp(quality: (int) config('opnform.image_optimization.quality', 80));
        } catch (\Throwable $e) {
            Log::warning('Image optimization failed, keeping original file', [
                'file' => $fileName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // Already a WebP and we couldn't make it smaller: keep the original
        $isWebp = strtolower(pathinfo($fileName ?? '', PATHINFO_EXTENSION)) === 'webp';
        if ($isWebp && strlen($encoded) >= strlen($binary)) {
            return null;
        }

        return $encoded;
    }

    public static function webpName(string $fileName): string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $base = $extension === '' ? $fileName : substr($fileName, 0, -(strlen($extension) + 1));

        return $base . '.webp';
    }
}
