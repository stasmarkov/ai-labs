<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ResizedImage
{
    public function __construct(
        public string $bytes,
        public int $width,
        public int $height,
    ) {}
}
