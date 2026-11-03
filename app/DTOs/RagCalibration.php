<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class RagCalibration
{
    public function __construct(
        public RagSimilarityStats $onTopic,
        public RagSimilarityStats $offTopic,
        public RagThresholdPoint $best,
        public RagThresholdPoint $configured,
    ) {}

    /**
     * @return array{on_topic: array<string, mixed>, off_topic: array<string, mixed>, best: array<string, mixed>, configured: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'on_topic' => $this->onTopic->toArray(),
            'off_topic' => $this->offTopic->toArray(),
            'best' => $this->best->toArray(),
            'configured' => $this->configured->toArray(),
        ];
    }
}
