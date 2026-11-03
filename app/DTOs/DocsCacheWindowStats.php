<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DocsCacheWindowStats
{
    /**
     * @param  array<string, int>  $hitsByKind
     */
    public function __construct(
        public int $hits,
        public int $completed,
        public ?float $hitRate,
        public array $hitsByKind,
        public int $savedLatencySumMs,
        public ?float $savedLatencyMedianMs,
    ) {}
}
