<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoExtractionStatusCounts
{
    public function __construct(
        public int $queued,
        public int $running,
        public int $completed,
        public int $failed,
    ) {}
}
