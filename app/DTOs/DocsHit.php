<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One chunk found by a single list: $value is the cosine similarity for the vector list and the ts_rank_cd value for the text list.
 */
final readonly class DocsHit
{
    public function __construct(
        public int $id,
        public string $source,
        public string $path,
        public ?string $heading,
        public string $body,
        public float $value,
    ) {}
}
