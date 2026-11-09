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

    /**
     * The one mapping from validated filter input (the /listings parameters) to a filter; `max_price` is euros here and
     * cents in the filter. Both /listings and the natural-language search use it, so the two cannot drift.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(array $data, int $perPage = 20, int $page = 1): self
    {
        return new self(
            make: $data['make'] ?? null,
            model: $data['model'] ?? null,
            fuel: isset($data['fuel']) ? Fuel::from($data['fuel']) : null,
            city: $data['city'] ?? null,
            minYear: isset($data['min_year']) ? (int) $data['min_year'] : null,
            maxPriceCents: isset($data['max_price']) ? (int) $data['max_price'] * 100 : null,
            perPage: $perPage,
            page: $page,
        );
    }

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
