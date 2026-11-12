<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\ResizedImage;
use App\Enums\PhotoVariant;
use App\Exceptions\PhotoImportException;

/**
 * Degrades a photo the way a re-upload does, one fixed kind at a time, and writes the result as a JPEG.
 */
final readonly class PhotoVariantRenderer
{
    private const QUALITY = 85;

    private const RECOMPRESSED_QUALITY = 25;

    private const DOWNSCALED_SIDE = 320;

    private const CROP_SHARE = 0.85;

    private const DARKEN = -40;

    public function render(string $bytes, PhotoVariant $variant): ResizedImage
    {
        if (! extension_loaded('gd')) {
            throw PhotoImportException::imageLibraryMissing();
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw PhotoImportException::unreadableImage($variant->value);
        }

        imagepalettetotruecolor($image);
        $width = imagesx($image);
        $height = imagesy($image);
        $quality = self::QUALITY;

        switch ($variant) {
            case PhotoVariant::Downscaled:
                $scale = self::DOWNSCALED_SIDE / max($width, $height);
                $small = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))) ?: $image;
                $image = imagescale($small, $width, $height) ?: $small;

                break;
            case PhotoVariant::Recompressed:
                $quality = self::RECOMPRESSED_QUALITY;

                break;
            case PhotoVariant::Cropped:
                $cropWidth = max(1, (int) round($width * self::CROP_SHARE));
                $cropHeight = max(1, (int) round($height * self::CROP_SHARE));
                $cropped = imagecrop($image, ['x' => intdiv($width - $cropWidth, 2), 'y' => intdiv($height - $cropHeight, 2), 'width' => $cropWidth, 'height' => $cropHeight]);
                $image = $cropped === false ? $image : (imagescale($cropped, $width, $height) ?: $cropped);

                break;
            case PhotoVariant::Darkened:
                imagefilter($image, IMG_FILTER_BRIGHTNESS, self::DARKEN);

                break;
            case PhotoVariant::Mirrored:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                break;
        }

        ob_start();
        imagejpeg($image, null, $quality);
        $jpeg = (string) ob_get_clean();

        return new ResizedImage($jpeg, imagesx($image), imagesy($image));
    }
}
