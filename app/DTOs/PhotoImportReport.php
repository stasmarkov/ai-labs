<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoImportReport
{
    public function __construct(
        public int $classesMatched,
        public int $classesSkipped,
        public int $photosStored,
        public int $alreadyImported,
        public int $skippedOverModelCap,
        public int $skippedNoListing,
        public int $unreadableFiles,
        public int $listingsWithPhoto,
    ) {}
}
