<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class RagRetrievalResult
{
    /**
     * @param  list<RagRecallCell>  $cells  one per mode and k
     * @param  array<string, float>  $topSimilarity  vector top-1 cosine similarity per question id, for on- and off-topic questions
     */
    public function __construct(
        public array $cells,
        public array $topSimilarity,
    ) {}
}
