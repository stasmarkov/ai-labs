<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One chunk's estimated token count against the count the embedding model reported.
 */
final readonly class TokenSample
{
    public function __construct(
        public int $id,
        public string $source,
        public string $path,
        public int $estimate,
        public int $real,
    ) {}

    public function ratio(): float
    {
        return $this->real > 0 ? $this->estimate / $this->real : 0.0;
    }

    /**
     * @return array{id: int, source: string, path: string, estimate: int, real: int}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'source' => $this->source, 'path' => $this->path, 'estimate' => $this->estimate, 'real' => $this->real];
    }
}
