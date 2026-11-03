<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DocsCacheStats
{
    public function __construct(
        public DocsCacheWindowStats $lastDay,
        public DocsCacheWindowStats $allTime,
        public int $currentEntries,
        public int $staleEntries,
    ) {}
}
