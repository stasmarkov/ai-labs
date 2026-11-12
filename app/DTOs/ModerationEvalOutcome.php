<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ModerationEvalOutcome
{
    public function __construct(
        public ModerationSetItem $item,
        public ModerationResult $result,
        public int $latencyMs,
    ) {}
}
