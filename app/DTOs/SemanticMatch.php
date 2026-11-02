<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class SemanticMatch
{
    public function __construct(
        public int $id,
        public int $year,
        public string $make,
        public string $model,
        public string $bodyType,
        public int $priceCents,
        public string $currency,
        public string $city,
        public string $description,
        public float $similarity,
    ) {}

    /**
     * @return array{id: int, year: int, make: string, model: string, body_type: string, price_cents: int, currency: string, city: string, description: string, similarity: float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'make' => $this->make,
            'model' => $this->model,
            'body_type' => $this->bodyType,
            'price_cents' => $this->priceCents,
            'currency' => $this->currency,
            'city' => $this->city,
            'description' => $this->description,
            'similarity' => round($this->similarity, 4),
        ];
    }
}
