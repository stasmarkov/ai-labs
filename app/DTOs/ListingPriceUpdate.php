<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ListingPriceUpdate
{
    public function __construct(
        public int $id,
        public int $priceCents,
        public string $currency,
        public int $previousPriceCents,
    ) {}
}
