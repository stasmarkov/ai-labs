<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * How many rows of a vector table exist, carry a vector, and carry one made with a given model.
 */
final readonly class EmbeddingIndexCount
{
    public function __construct(
        public int $total,
        public int $embedded,
        public int $current,
    ) {}

    /**
     * Rows whose vector was made with another model, so search over them is stale until a re-index.
     */
    public function foreign(): int
    {
        return max(0, $this->embedded - $this->current);
    }
}
