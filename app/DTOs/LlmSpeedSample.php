<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LlmSpeedSample
{
    /**
     * A sample that never saw a content chunk is failed: its time to first token is null and the reason is in $error.
     */
    public function __construct(
        public ?float $ttftMs,
        public ?float $loadMs,
        public ?float $promptEvalTokensPerSecond,
        public ?float $generationTokensPerSecond,
        public int $outputTokens,
        public bool $failed = false,
        public ?string $error = null,
    ) {}
}
