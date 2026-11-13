<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AdminListingDetail
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public int $id,
        public string $make,
        public string $model,
        public int $year,
        public string $fuel,
        public int $priceCents,
        public string $currency,
        public int $mileageKm,
        public string $city,
        public ?string $title,
        public array $tags,
        public ?string $description,
    ) {}
}
