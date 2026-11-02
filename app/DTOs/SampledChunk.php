<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class SampledChunk
{
    public function __construct(
        public int $id,
        public string $source,
        public string $path,
        public string $content,
        public int $tokenEstimate,
    ) {}
}
