<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ChunkEmbedding
{
    /**
     * @param  list<float>  $vector
     */
    public function __construct(
        public int $chunkId,
        public string $contentHash,
        public array $vector,
    ) {}
}
