<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ChunkText
{
    public function __construct(
        public int $id,
        public string $content,
        public string $contentHash,
    ) {}
}
