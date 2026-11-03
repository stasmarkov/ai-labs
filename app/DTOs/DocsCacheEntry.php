<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class DocsCacheEntry
{
    /**
     * @param  list<array<string, mixed>>  $sources  the payload of the sources event
     * @param  list<int>  $citedChunkIds
     * @param  list<int>  $retrievedChunkIds
     */
    public function __construct(
        public int $id,
        public string $question,
        public string $answer,
        public array $sources,
        public array $citedChunkIds,
        public array $retrievedChunkIds,
        public string $provider,
        public DocsCacheVersions $versions,
        public string $sourceInvocationId,
        public int $sourceLatencyMs,
        public int $hitCount,
        public CarbonImmutable $createdAt,
    ) {}
}
