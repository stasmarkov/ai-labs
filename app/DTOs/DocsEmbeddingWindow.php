<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * The model calls of the indexing run: from the start of the first to the log time of the last.
 */
final readonly class DocsEmbeddingWindow
{
    public function __construct(
        public int $calls,
        public int $tokensIn,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
    ) {}

    public function seconds(): float
    {
        return round(max(0.0, $this->startedAt->diffInMilliseconds($this->finishedAt) / 1000), 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'calls' => $this->calls,
            'tokens_in' => $this->tokensIn,
            'started_at' => $this->startedAt->toIso8601String(),
            'finished_at' => $this->finishedAt->toIso8601String(),
            'seconds' => $this->seconds(),
        ];
    }
}
