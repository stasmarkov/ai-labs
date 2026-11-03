<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class RagRunTiming
{
    public function __construct(
        public int $latencyMs,
        public ?int $ttftMs,
    ) {}
}
