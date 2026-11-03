<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\DocsAnswerOutcome;

/**
 * A finished ask, offered to the cache with the versions read when it was planned; DocsAnswerCache::store() decides whether it qualifies.
 */
final readonly class DocsCacheCandidate
{
    /**
     * @param  list<float>  $vector  the embedding of the raw question
     * @param  list<array<string, mixed>>  $sources
     * @param  list<int>  $citedChunkIds
     * @param  list<int>  $retrievedChunkIds
     */
    public function __construct(
        public string $question,
        public array $vector,
        public DocsAnswerOutcome $outcome,
        public string $answer,
        public array $sources,
        public array $citedChunkIds,
        public array $retrievedChunkIds,
        public string $provider,
        public DocsCacheVersions $versions,
        public string $sourceInvocationId,
        public int $sourceLatencyMs,
        public bool $aborted = false,
    ) {}
}
