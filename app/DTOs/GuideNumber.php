<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class GuideNumber
{
    public function __construct(
        public string $label,
        public ?string $file = null,
        public ?string $path = null,
        public bool $pending = false,
        public ?string $unit = null,
    ) {}
}
