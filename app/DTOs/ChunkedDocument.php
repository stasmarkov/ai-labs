<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ChunkedDocument
{
    /**
     * @param  list<DocChunk>  $chunks
     */
    public function __construct(
        public string $title,
        public array $chunks,
    ) {}
}
