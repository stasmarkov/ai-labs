<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Recall@k and MRR of one retrieval mode at one k over the on-topic questions.
 */
final readonly class RagRecallCell
{
    /**
     * @param  list<string>  $missedIds  on-topic question ids with no hit in the top k
     */
    public function __construct(
        public string $mode,
        public int $k,
        public int $questions,
        public int $hits,
        public float $mrr,
        public array $missedIds,
    ) {}

    public function recall(): float
    {
        return $this->questions === 0 ? 0.0 : $this->hits / $this->questions;
    }

    /**
     * @return array{mode: string, k: int, questions: int, hits: int, recall: float, mrr: float, missed: list<string>}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'k' => $this->k,
            'questions' => $this->questions,
            'hits' => $this->hits,
            'recall' => round($this->recall(), 6),
            'mrr' => round($this->mrr, 6),
            'missed' => $this->missedIds,
        ];
    }
}
