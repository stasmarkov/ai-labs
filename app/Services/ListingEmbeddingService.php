<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviders;
use App\Ai\OllamaServerInfo;
use App\Contracts\EmbeddingStatsRepositoryInterface;
use App\Contracts\ListingDocumentRepositoryInterface;
use App\DTOs\EmbeddingRun;
use App\DTOs\EmbeddingRunSummary;
use App\DTOs\ListingEmbedding;
use App\Exceptions\EmbeddingDimensionsException;
use App\Exceptions\InvalidEmbeddingException;
use App\Exceptions\ProviderNotConfiguredException;
use App\Jobs\EmbedListingBatchJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Embeddings;

final readonly class ListingEmbeddingService
{
    public const FEATURE = 'embeddings-listings';

    public const DOCS_FEATURE = 'embeddings-docs';

    public const VECTOR_DIMENSIONS = 1024;

    private const LAST_RUN_KEY = 'listings:embed:last-run';

    public function __construct(
        private ListingDocumentRepositoryInterface $documents,
        private EmbeddingStatsRepositoryInterface $stats,
        private OllamaServerInfo $ollama,
    ) {}

    public function defaultBatch(): int
    {
        return (int) (config('ai.embeddings.batch') ?: 256);
    }

    public function queue(): string
    {
        return (string) (config('ai.embeddings.queue') ?: 'embeddings');
    }

    public function provider(string $feature): string
    {
        return app(AiProviders::class)->forFeature($feature)->provider;
    }

    public function model(string $feature): string
    {
        return app(AiProviders::class)->forFeature($feature)->model;
    }

    /**
     * @throws EmbeddingDimensionsException when the provider's configured size is not the size of the vector columns.
     * @throws ProviderNotConfiguredException when the feature's cloud provider has no key.
     */
    public function assertDimensions(string $feature): void
    {
        $dimensions = $this->dimensions($feature);

        if ($dimensions !== self::VECTOR_DIMENSIONS) {
            throw EmbeddingDimensionsException::forProvider($this->provider($feature), $dimensions, self::VECTOR_DIMENSIONS);
        }
    }

    public function dimensions(string $feature): int
    {
        return (int) config('ai.providers.'.$this->provider($feature).'.models.embeddings.dimensions');
    }

    /**
     * The value stored in embedding_model, so a change of provider or model re-embeds.
     */
    public function modelLabel(string $feature): string
    {
        return $this->provider($feature).'/'.$this->model($feature);
    }

    public function queuedJobs(): int
    {
        return $this->stats->queuedJobs($this->queue());
    }

    public function listingsWithoutDocument(): int
    {
        return $this->documents->countListingsWithoutDocument();
    }

    /**
     * Queue one job per batch of listings whose vector is missing or out of date.
     */
    public function dispatch(int $batch, ?int $limit, bool $fresh): EmbeddingRun
    {
        $startedAt = CarbonImmutable::now();

        if ($fresh) {
            $this->documents->clearEmbeddings($limit);
        }

        $ids = $this->documents->staleListingIds($this->modelLabel(self::FEATURE), $limit);
        $batches = array_chunk($ids, $batch);

        $run = new EmbeddingRun($startedAt, $batch, $limit, count($ids), count($batches));
        Cache::forever(self::LAST_RUN_KEY, $run->toArray());

        foreach ($batches as $listingIds) {
            EmbedListingBatchJob::dispatch($listingIds)->onQueue($this->queue());
        }

        return $run;
    }

    public function lastRun(): ?EmbeddingRun
    {
        $data = Cache::get(self::LAST_RUN_KEY);

        return is_array($data) ? EmbeddingRun::fromArray($data) : null;
    }

    /**
     * Embed the still-stale descriptions of one batch in a single model call.
     *
     * @param  list<int>  $listingIds
     * @return int number of vectors stored
     *
     * @throws InvalidEmbeddingException when the model answers with the wrong number or size of vectors; nothing is stored then.
     */
    public function embedBatch(array $listingIds): int
    {
        $documents = $this->documents->staleDocuments($listingIds, $this->modelLabel(self::FEATURE));

        if ($documents === []) {
            return 0;
        }

        $vectors = $this->embedTexts(self::FEATURE, array_map(fn ($document): string => $document->description, $documents));

        $embeddings = [];

        foreach ($documents as $index => $document) {
            $embeddings[] = new ListingEmbedding($document->listingId, hash('sha256', $document->description), $vectors[$index]);
        }

        return $this->documents->storeEmbeddings($embeddings, $this->modelLabel(self::FEATURE), CarbonImmutable::now());
    }

    /**
     * One model call for all texts, checked for the number and length of the vectors.
     *
     * The feature is required, never defaulted: listings and docs may run on different embedding providers, and a
     * vector must be made by the model whose label it is stored under.
     *
     * @param  list<string>  $texts
     * @return list<list<float>> one vector per text, in order
     *
     * @throws InvalidEmbeddingException when the model answers with the wrong number or size of vectors.
     */
    public function embedTexts(string $feature, array $texts): array
    {
        $dimensions = $this->dimensions($feature);
        $vectors = Embeddings::for($texts)
            ->dimensions($dimensions)
            ->timeout((int) config('ai.embeddings.timeout'))
            ->generate($this->provider($feature), $this->model($feature))
            ->embeddings;

        if (count($vectors) !== count($texts)) {
            throw InvalidEmbeddingException::wrongCount(count($texts), count($vectors));
        }

        $checked = [];

        foreach ($vectors as $vector) {
            $vector = array_values($vector);

            if (count($vector) !== $dimensions) {
                throw InvalidEmbeddingException::wrongLength($dimensions, count($vector));
            }

            $checked[] = $vector;
        }

        return $checked;
    }

    public function summarize(EmbeddingRun $run): EmbeddingRunSummary
    {
        $from = $run->startedAt;
        $finishedAt = $this->documents->lastEmbeddedAt($from) ?? CarbonImmutable::now();
        // Count from the first model call, not from dispatch: worker start-up and the --fresh UPDATE are not embedding time.
        $firstCall = $this->stats->firstCallStartedAt($from);
        $startedAt = $firstCall !== null && $firstCall->isAfter($from) ? $firstCall : $from;

        return new EmbeddingRunSummary(
            model: $this->model(self::FEATURE),
            provider: $this->provider(self::FEATURE),
            ollamaVersion: $this->ollama->version($this->provider(self::FEATURE)),
            ollamaNumParallel: $this->ollama->numParallel($this->provider(self::FEATURE)),
            batch: $run->batch,
            limit: $run->limit,
            listingsEmbedded: $this->documents->countEmbeddedSince($from),
            calls: $this->stats->callsBetween($from->startOfSecond(), CarbonImmutable::now()->endOfSecond(), $this->queue()),
            startedAt: $startedAt,
            finishedAt: $finishedAt->isBefore($startedAt) ? $startedAt : $finishedAt,
            sizes: $this->stats->documentSizes(),
        );
    }
}
