<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class GuideRecipe
{
    public function __construct(
        public string $title,
        public string $body,
        public ?string $snippet = null,
    ) {}
}
