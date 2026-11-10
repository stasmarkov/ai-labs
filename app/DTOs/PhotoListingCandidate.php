<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoListingCandidate
{
    public function __construct(
        public int $id,
        public int $year,
    ) {}
}
