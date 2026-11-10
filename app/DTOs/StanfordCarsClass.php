<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class StanfordCarsClass
{
    public function __construct(
        public string $name,
        public string $make,
        public string $model,
        public string $body,
        public int $year,
    ) {}
}
