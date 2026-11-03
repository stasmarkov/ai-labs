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
        public ?int $inputs = null,
        /** @var list<int>|null */
        public ?array $retrievedChunkIds = null,
        /** @var list<int>|null */
        public ?array $citedChunkIds = null,
        public ?string $refusalReason = null,
        public ?string $cacheKind = null,
        public ?float $cacheSimilarity = null,
        public ?int $cacheEntryId = null,
        public ?int $savedLatencyMs = null,
        public ?string $origin = null,
        public ?int $historyTurns = null,
        public ?int $stoppedAfterMs = null,
    ) {}
}
