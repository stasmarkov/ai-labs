<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DocsCacheNewEntry
{
    /**
     * @param  list<float>  $vector
     * @param  list<array<string, mixed>>  $sources
     * @param  list<int>  $citedChunkIds
     * @param  list<int>  $retrievedChunkIds
     */
    public function __construct(
        public string $question,
        public string $questionHash,
        public array $vector,
        public string $answer,
        public array $sources,
        public array $citedChunkIds,
        public array $retrievedChunkIds,
        public string $provider,
        public DocsCacheVersions $versions,
        public string $sourceInvocationId,
        public int $sourceLatencyMs,
    ) {}
}
