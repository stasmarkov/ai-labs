<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Where paraphrase and near-miss similarities fall and which thresholds that supports. A pair is served at similarity >= threshold.
 */
final readonly class DocsCacheCalibration
{
    public function __construct(
        public RagSimilarityStats $paraphrase,
        public RagSimilarityStats $nearMiss,
        public RagThresholdPoint $best,
        public ?RagThresholdPoint $zeroFalseHit,
        public RagThresholdPoint $suggested,
        public int $lostParaphrases,
        public RagThresholdPoint $configured,
    ) {}

    /**
     * @return array{paraphrase: array<string, mixed>, near_miss: array<string, mixed>, best: array<string, mixed>, zero_false_hit: array<string, mixed>|null, suggested_default: array<string, mixed>, configured: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'paraphrase' => $this->paraphrase->toArray(),
            'near_miss' => $this->nearMiss->toArray(),
            'best' => $this->best->toArray(),
            'zero_false_hit' => $this->zeroFalseHit?->toArray(),
            'suggested_default' => $this->suggested->toArray() + ['lost_paraphrases' => $this->lostParaphrases],
            'configured' => $this->configured->toArray(),
        ];
    }
}
