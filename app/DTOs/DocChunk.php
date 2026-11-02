<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DocChunk
{
    public function __construct(
        public int $position,
        public string $heading,
        public string $body,
        public string $content,
        public int $tokenEstimate,
        public bool $oversize,
        public string $contentHash,
    ) {}
}
