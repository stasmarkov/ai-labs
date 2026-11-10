<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\ResizedImage;
use App\Exceptions\PhotoImportException;

/**
 * Orients a photo by EXIF and writes it as a JPEG no larger than the long-side limit.
 */
final readonly class PhotoImageResizer
{
    public const MAX_SIDE = 1024;

    private const QUALITY = 85;

    public function resize(string $path): ResizedImage
    {
        if (! extension_loaded('gd')) {
            throw PhotoImportException::imageLibraryMissing();
        }

        $bytes = @file_get_contents($path);
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);

        if ($image === false) {
            throw PhotoImportException::unreadableImage($path);
        }

        $image = $this->orient($image, $path);
        $image = $this->fit($image);
        imagepalettetotruecolor($image);

        ob_start();
        imagejpeg($image, null, self::QUALITY);
        $jpeg = (string) ob_get_clean();

        return new ResizedImage($jpeg, imagesx($image), imagesy($image));
    }

    private function orient(\GdImage $image, string $path): \GdImage
    {
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private function fit(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longSide = max($width, $height);

        if ($longSide <= self::MAX_SIDE) {
            return $image;
        }

        $scale = self::MAX_SIDE / $longSide;

        return imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))) ?: $image;
    }
}
