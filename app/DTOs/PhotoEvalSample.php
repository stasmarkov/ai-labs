<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoEvalSample
{
    public function __construct(
        public int $photoId,
        public string $path,
        public string $sourceClass,
    ) {}
}
