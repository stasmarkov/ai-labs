<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class NlSearchQuery
{
    public function __construct(
        public string $text,
        public ?string $model = null,
        public int $perPage = 20,
        public int $page = 1,
    ) {}
}
