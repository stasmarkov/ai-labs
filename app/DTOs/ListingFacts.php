<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ListingFacts
{
    public function __construct(
        public int $id,
        public int $year,
        public string $make,
        public string $model,
        public string $fuel,
        public int $mileageKm,
    ) {}
}
