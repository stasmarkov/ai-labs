<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ListingSummary
{
    public function __construct(
        public int $id,
        public string $make,
        public string $model,
        public int $year,
        public string $fuel,
        public int $mileageKm,
        public int $priceCents,
        public string $currency,
        public string $city,
        public string $listedAt,
        public int $dealerId,
    ) {}

    /**
     * @return array{id: int, make: string, model: string, year: int, fuel: string, mileage_km: int, price_cents: int, currency: string, city: string, listed_at: string, dealer_id: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'fuel' => $this->fuel,
            'mileage_km' => $this->mileageKm,
            'price_cents' => $this->priceCents,
            'currency' => $this->currency,
            'city' => $this->city,
            'listed_at' => $this->listedAt,
            'dealer_id' => $this->dealerId,
        ];
    }
}
