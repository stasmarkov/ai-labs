<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DuplicateClusterReport
{
    public function __construct(
        public string $label,
        public float $cutoff,
        public int $photos,
        public int $groups,
        public int $photosGrouped,
        public int $splitGroups,
        public int $replacedGroups,
        public int $elapsedMs,
    ) {}
}
