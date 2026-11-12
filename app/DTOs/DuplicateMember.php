<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DuplicateMember
{
    public function __construct(
        public int $photoId,
        public float $maxSimilarity,
    ) {}
}
