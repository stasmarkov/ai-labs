<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ExportListingRow
{
    public function __construct(
        public int $listingId,
        public string $make,
        public string $model,
        public int $year,
        public int $priceCents,
        public string $currency,
        public string $city,
        public string $fuel,
        public int $mileageKm,
        public string $description,
    ) {}
}
