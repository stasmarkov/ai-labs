<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ListingDocumentSizes
{
    public function __construct(
        public int $tableBytes,
        public int $indexBytes,
        public int $totalBytes,
    ) {}
}
