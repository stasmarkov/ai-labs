<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ListingEmbedding
{
    /**
     * @param  list<float>  $vector
     */
    public function __construct(
        public int $listingId,
        public string $textHash,
        public array $vector,
    ) {}
}
