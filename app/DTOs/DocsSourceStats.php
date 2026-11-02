<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DocsSourceStats
{
    public function __construct(
        public string $source,
        public int $documents,
        public int $chunks,
        public int $minTokens,
        public float $medianTokens,
        public int $maxTokens,
        public int $oversize,
    ) {}

    /**
     * @return array{documents: int, chunks: int, tokens: array{min: int, median: float, max: int}, oversize: int}
     */
    public function toArray(): array
    {
        return [
            'documents' => $this->documents,
            'chunks' => $this->chunks,
            'tokens' => ['min' => $this->minTokens, 'median' => $this->medianTokens, 'max' => $this->maxTokens],
            'oversize' => $this->oversize,
        ];
    }
}
