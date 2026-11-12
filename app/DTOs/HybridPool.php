<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class HybridPool
{
    public function __construct(
        public bool $queryHasCaption,
        public int $candidates,
        public int $candidatesWithCaption,
        public float $appliedMinScore,
    ) {}

    /**
     * @return array{query_has_caption: bool, candidates: int, candidates_with_caption: int, applied_min_score: float}
     */
    public function toArray(): array
    {
        return [
            'query_has_caption' => $this->queryHasCaption,
            'candidates' => $this->candidates,
            'candidates_with_caption' => $this->candidatesWithCaption,
            'applied_min_score' => $this->appliedMinScore,
        ];
    }

    /**
     * @param  array{query_has_caption: bool, candidates: int, candidates_with_caption: int, applied_min_score: float}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['query_has_caption'], $data['candidates'], $data['candidates_with_caption'], (float) $data['applied_min_score']);
    }
}
