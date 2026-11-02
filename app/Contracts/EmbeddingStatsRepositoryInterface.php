<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\EmbeddingCallCounts;
use App\DTOs\ListingDocumentSizes;
use Carbon\CarbonImmutable;

interface EmbeddingStatsRepositoryInterface
{
    /**
     * Jobs waiting or running on the given queue.
     */
    public function queuedJobs(string $queue): int;

    /**
     * Embedding model calls and exhausted batches between two moments.
     */
    /**
     * When the first embedding call logged since the given time started (its log time minus its latency).
     */
    public function firstCallStartedAt(CarbonImmutable $since): ?CarbonImmutable;

    public function callsBetween(CarbonImmutable $from, CarbonImmutable $to, string $queue): EmbeddingCallCounts;

    public function documentSizes(): ListingDocumentSizes;
}
