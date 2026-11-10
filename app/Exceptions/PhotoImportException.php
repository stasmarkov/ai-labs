<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class PhotoImportException extends RuntimeException
{
    public static function datasetMissing(string $directory): self
    {
        return new self("No dataset at {$directory} (expected classes.csv and images/). Run bench/datasets/stanford-cars.sh --accept-terms first.");
    }

    public static function imageLibraryMissing(): self
    {
        return new self('The GD extension is required to resize photos.');
    }

    public static function unreadableImage(string $path): self
    {
        return new self("Cannot read {$path} as an image.");
    }

    public static function writeFailed(string $path): self
    {
        return new self("Could not write {$path} to the public disk.");
    }
}
