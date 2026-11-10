<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Contracts\DocsIndexRepositoryInterface;
use App\DTOs\ChunkEmbedding;
use App\Exceptions\EmbeddingDimensionsException;
use App\Exceptions\InvalidEmbeddingException;
use App\Exceptions\ProviderNotConfiguredException;
use Carbon\CarbonImmutable;
use Laravel\Ai\Embeddings;

/**
 * Embeds doc chunks with the provider, model, dimension, timeout and queue of the listings embeddings.
 */
final readonly class DocsEmbeddingService
{
    public const AGENT = 'embeddings:docs';

    /** Logged apart from AGENT, so the indexing time window holds only indexing batches. */
    public const TOKEN_CHECK_AGENT = 'embeddings:docs-token-check';

    public function __construct(
        private DocsIndexRepositoryInterface $chunks,
        private ListingEmbeddingService $embeddings,
    ) {}

    public function batch(): int
    {
        return (int) (config('ai.docs.embed_batch') ?: 32);
    }

    public function queue(): string
    {
        return $this->embeddings->queue();
    }

    public function modelLabel(): string
    {
        return $this->embeddings->modelLabel(ListingEmbeddingService::DOCS_FEATURE);
    }

    /**
     * @throws EmbeddingDimensionsException
     * @throws ProviderNotConfiguredException
     */
    public function assertDimensions(): void
    {
        $this->embeddings->assertDimensions(ListingEmbeddingService::DOCS_FEATURE);
    }

    public function queuedJobs(): int
    {
        return $this->embeddings->queuedJobs();
    }

    /**
     * The token count the model reports for one text embedded alone; logged as TOKEN_CHECK_AGENT.
     */
    public function countTokens(string $content): int
    {
        $recorder = app(AiRunRecorder::class);
        $recorder->nextEmbeddingsAgent(self::TOKEN_CHECK_AGENT);

        try {
            return Embeddings::for([$content])
                ->dimensions($this->embeddings->dimensions(ListingEmbeddingService::DOCS_FEATURE))
                ->timeout((int) config('ai.embeddings.timeout'))
                ->generate($this->embeddings->provider(ListingEmbeddingService::DOCS_FEATURE), $this->embeddings->model(ListingEmbeddingService::DOCS_FEATURE))
                ->usage
                ->inputTokens;
        } finally {
            $recorder->nextEmbeddingsAgent(null);
        }
    }

    /**
     * Embed the still-stale chunks of one batch in a single model call.
     *
     * @param  list<int>  $chunkIds
     * @return int number of vectors stored
     *
     * @throws InvalidEmbeddingException when the model answers with the wrong number or size of vectors; nothing is stored then.
     */
    public function embedBatch(array $chunkIds): int
    {
        $chunks = $this->chunks->staleChunks($chunkIds, $this->modelLabel());

        if ($chunks === []) {
            return 0;
        }

        $recorder = app(AiRunRecorder::class);
        $recorder->nextEmbeddingsAgent(self::AGENT);

        try {
            $vectors = $this->embeddings->embedTexts(ListingEmbeddingService::DOCS_FEATURE, array_map(fn ($chunk): string => $chunk->content, $chunks));
        } finally {
            $recorder->nextEmbeddingsAgent(null);
        }

        $stored = [];

        foreach ($chunks as $index => $chunk) {
            $stored[] = new ChunkEmbedding($chunk->id, $chunk->contentHash, $vectors[$index]);
        }

        return $this->chunks->storeEmbeddings($stored, $this->modelLabel(), CarbonImmutable::now());
    }
}
