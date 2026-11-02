<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\DocsEmbeddingWindow;
use App\DTOs\DocsIndexSizes;
use App\DTOs\DocsSourceStats;
use App\DTOs\SampledChunk;

interface DocsBenchRepositoryInterface
{
    /**
     * Chunks that have a vector for this model.
     */
    public function embeddedCount(string $model): int;

    /**
     * Documents, chunks and the estimated token distribution per source.
     *
     * @return list<DocsSourceStats>
     */
    public function sourceStats(): array;

    public function sizes(): DocsIndexSizes;

    /**
     * The indexing batch calls logged under this agent (more than one chunk each, so single-chunk token checks are left out), limited to the latest indexing run:
     * calls logged since the newest `indexed_at`, so earlier runs do not stretch the window.
     */
    public function embeddingWindow(string $agent): ?DocsEmbeddingWindow;

    /**
     * The same chunks for the same seed: $perSource chunks of each source, picked by a hash of the chunk id and the seed.
     *
     * @return list<SampledChunk>
     */
    public function sampleChunks(int $perSource, string $seed): array;

    public function pgvectorVersion(): string;
}
