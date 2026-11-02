<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class EmbeddingRunSummary
{
    public function __construct(
        public string $model,
        public string $provider,
        public ?string $ollamaVersion,
        public ?int $ollamaNumParallel,
        public int $batch,
        public ?int $limit,
        public int $listingsEmbedded,
        public EmbeddingCallCounts $calls,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
        public ListingDocumentSizes $sizes,
    ) {}

    public function seconds(): float
    {
        return round(max(0.0, $this->startedAt->diffInMilliseconds($this->finishedAt) / 1000), 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $seconds = $this->seconds();

        return [
            'model' => $this->model,
            'provider' => $this->provider,
            'ollama_version' => $this->ollamaVersion,
            'ollama_num_parallel' => $this->ollamaNumParallel,
            'batch' => $this->batch,
            'limit' => $this->limit,
            'listings_embedded' => $this->listingsEmbedded,
            'model_calls' => $this->calls->modelCalls,
            'failed_batches' => $this->calls->failedBatches,
            'started_at' => $this->startedAt->toIso8601String(),
            'finished_at' => $this->finishedAt->toIso8601String(),
            'seconds' => $seconds,
            'embeddings_per_second' => $seconds > 0 ? round($this->listingsEmbedded / $seconds, 2) : 0.0,
            'table_bytes' => $this->sizes->tableBytes,
            'index_bytes' => $this->sizes->indexBytes,
            'total_bytes' => $this->sizes->totalBytes,
        ];
    }
}
