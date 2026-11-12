<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PhotoVariant;

final readonly class NewListingPhoto
{
    public const SOURCE_AUGMENTED = 'augmented';

    public function __construct(
        public int $listingId,
        public string $source,
        public string $sourceClass,
        public string $sourceFile,
        public int $width,
        public int $height,
        public string $sha256,
        public ?int $duplicateOf = null,
        public ?PhotoVariant $variant = null,
    ) {}
}
