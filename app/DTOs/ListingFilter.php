<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\Fuel;

final readonly class ListingFilter
{
    public function __construct(
        public ?string $make = null,
        public ?string $model = null,
        public ?Fuel $fuel = null,
        public ?string $city = null,
        public ?int $minYear = null,
        public ?int $maxPriceCents = null,
        public int $perPage = 20,
        public int $page = 1,
    ) {}

    public function withPerPage(int $perPage): self
    {
        return new self(
            $this->make,
            $this->model,
            $this->fuel,
            $this->city,
            $this->minYear,
            $this->maxPriceCents,
            $perPage,
            $this->page,
        );
    }
}
