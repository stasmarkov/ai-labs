<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class SimilarEvalPhoto
{
    public function __construct(
        public int $photoId,
        public string $make,
        public string $model,
    ) {}
}
