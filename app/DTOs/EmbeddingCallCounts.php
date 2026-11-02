<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class EmbeddingCallCounts
{
    public function __construct(
        public int $modelCalls,
        public int $failedBatches,
    ) {}
}
