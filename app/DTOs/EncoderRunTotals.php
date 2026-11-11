<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class EncoderRunTotals
{
    public function __construct(
        public int $photos,
        public int $latencyMs,
    ) {}
}
