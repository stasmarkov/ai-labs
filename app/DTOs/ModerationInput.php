<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ModerationInput
{
    public function __construct(
        public string $title,
        public float $priceEur,
        public string $make,
        public string $model,
        public int $year,
        public int $mileageKm,
        public string $city,
        public string $description,
        public ?int $listingId = null,
    ) {}
}
