<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AdminListingRow
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public int $id,
        public string $make,
        public string $model,
        public int $year,
        public int $priceCents,
        public string $city,
        public ?string $title,
        public array $tags,
    ) {}
}
