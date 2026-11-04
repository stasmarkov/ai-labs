<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class ExportRecord
{
    public function __construct(
        public string $id,
        public string $path,
        public int $bytes,
        public string $sha256,
        public int $rows,
        public CarbonImmutable $generatedAt,
    ) {}
}
