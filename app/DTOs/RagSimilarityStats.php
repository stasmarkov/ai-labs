<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class RagSimilarityStats
{
    public function __construct(
        public int $count,
        public float $min,
        public float $median,
        public float $max,
    ) {}

    /**
     * @return array{count: int, min: float, median: float, max: float}
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'min' => round($this->min, 6),
            'median' => round($this->median, 6),
            'max' => round($this->max, 6),
        ];
    }
}
