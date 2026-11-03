<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A relevance threshold with its true and false positive rates: a question passes at similarity >= threshold.
 */
final readonly class RagThresholdPoint
{
    public function __construct(
        public float $threshold,
        public float $tpr,
        public float $fpr,
    ) {}

    public function youdenJ(): float
    {
        return $this->tpr - $this->fpr;
    }

    /**
     * @return array{threshold: float, tpr: float, fpr: float, j: float}
     */
    public function toArray(): array
    {
        return [
            'threshold' => round($this->threshold, 6),
            'tpr' => round($this->tpr, 6),
            'fpr' => round($this->fpr, 6),
            'j' => round($this->youdenJ(), 6),
        ];
    }
}
