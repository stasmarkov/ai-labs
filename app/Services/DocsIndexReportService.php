<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\OllamaServerInfo;
use App\Contracts\DocsBenchRepositoryInterface;
use App\DTOs\DocsIndexReport;
use App\DTOs\SampledChunk;
use App\DTOs\TokenSample;

final readonly class DocsIndexReportService
{
    /**
     * Fixed so the same chunks are checked on every run.
     */
    public const SAMPLE_SEED = 'docs-token-check';

    public function __construct(
        private DocsBenchRepositoryInterface $bench,
        private DocsEmbeddingService $embedding,
        private ListingEmbeddingService $embeddings,
        private DocsCorpusVersions $versions,
        private OllamaServerInfo $ollama,
    ) {}

    public function embeddedCount(): int
    {
        return $this->bench->embeddedCount($this->embedding->modelLabel());
    }

    /**
     * Index statistics, then the token estimate against the model's own count for $perSource chunks of each source.
     * The statistics are read first so the single-chunk checks never count as indexing.
     */
    public function run(?float $indexingSeconds, int $perSource): DocsIndexReport
    {
        $sources = $this->bench->sourceStats();
        $window = $this->bench->embeddingWindow(DocsEmbeddingService::AGENT);
        $sizes = $this->bench->sizes();

        $samples = array_map(
            fn (SampledChunk $chunk): TokenSample => new TokenSample($chunk->id, $chunk->source, $chunk->path, $chunk->tokenEstimate, $this->embedding->countTokens($chunk->content)),
            $this->bench->sampleChunks($perSource, self::SAMPLE_SEED),
        );

        return new DocsIndexReport(
            model: $this->embeddings->model(),
            provider: $this->embeddings->provider(),
            ollamaVersion: $this->ollama->version($this->embeddings->provider()),
            pgvector: $this->bench->pgvectorVersion(),
            repo: $this->versions->repo(),
            laravel: $this->versions->laravel(),
            sources: $sources,
            indexingSeconds: $indexingSeconds,
            embedding: $window,
            sizes: $sizes,
            samples: $samples,
        );
    }
}
