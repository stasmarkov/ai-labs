<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ListingDocumentText
{
    public function __construct(
        public int $listingId,
        public string $bodyType,
        public string $description,
    ) {}
}
