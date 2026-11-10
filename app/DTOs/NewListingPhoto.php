<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class NewListingPhoto
{
    public function __construct(
        public int $listingId,
        public string $source,
        public string $sourceClass,
        public string $sourceFile,
        public int $width,
        public int $height,
        public string $sha256,
    ) {}
}
