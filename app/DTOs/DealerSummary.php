<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DealerSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public string $city,
    ) {}

    /**
     * @return array{id: int, name: string, city: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
        ];
    }
}
