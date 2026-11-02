<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AiRunRecord
{
    public function __construct(
        public string $invocationId,
        public string $agent,
        public string $provider,
        public string $model,
        public ?string $promptVersion,
        public ?int $tokensIn,
        public ?int $tokensOut,
        public ?int $ttftMs,
        public int $latencyMs,
        public string $status,
        public ?string $errorClass = null,
        public ?string $errorMessage = null,
    ) {}
}
