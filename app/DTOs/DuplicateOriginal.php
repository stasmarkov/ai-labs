<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DuplicateOriginal
{
    public function __construct(
        public int $id,
        public int $listingId,
        public string $path,
        public string $sourceClass,
        public string $sourceFile,
    ) {}
}
