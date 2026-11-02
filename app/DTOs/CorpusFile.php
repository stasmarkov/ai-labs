<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CorpusFile
{
    public function __construct(
        public string $source,
        public string $path,
        public string $absolutePath,
    ) {}
}
