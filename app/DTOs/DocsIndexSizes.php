<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DocsIndexSizes
{
    public function __construct(
        public int $tableBytes,
        public int $hnswBytes,
        public int $ginBytes,
        public int $totalBytes,
    ) {}

    /**
     * @return array{table_bytes: int, hnsw_bytes: int, gin_bytes: int, total_bytes: int}
     */
    public function toArray(): array
    {
        return [
            'table_bytes' => $this->tableBytes,
            'hnsw_bytes' => $this->hnswBytes,
            'gin_bytes' => $this->ginBytes,
            'total_bytes' => $this->totalBytes,
        ];
    }
}
