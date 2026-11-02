<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class IndexedDocument
{
    /**
     * @param  list<DocChunk>  $chunks
     */
    public function __construct(
        public string $source,
        public string $path,
        public string $title,
        public string $contentHash,
        public array $chunks,
    ) {}
}
