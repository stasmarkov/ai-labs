<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DocsMatch
{
    public const SNIPPET_LENGTH = 300;

    public function __construct(
        public int $id,
        public string $source,
        public string $path,
        public ?string $heading,
        public string $body,
        public float $score,
        public ?int $vectorRank,
        public ?float $vectorSimilarity,
        public ?int $textRank,
        public ?float $textScore,
    ) {}

    /**
     * The body without its prefix, cut at 300 characters with an ellipsis.
     */
    public function snippet(int $length = self::SNIPPET_LENGTH): string
    {
        return mb_strlen($this->body) > $length ? mb_substr($this->body, 0, $length).'…' : $this->body;
    }

    /**
     * @return array{id: int, source: string, path: string, heading: string|null, snippet: string, score: float, vector_rank: int|null, vector_similarity: float|null, text_rank: int|null, text_score: float|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'path' => $this->path,
            'heading' => $this->heading,
            'snippet' => $this->snippet(),
            'score' => round($this->score, 6),
            'vector_rank' => $this->vectorRank,
            'vector_similarity' => $this->vectorSimilarity === null ? null : round($this->vectorSimilarity, 6),
            'text_rank' => $this->textRank,
            'text_score' => $this->textScore === null ? null : round($this->textScore, 6),
        ];
    }
}
