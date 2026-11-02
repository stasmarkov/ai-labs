<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\OllamaServerInfo;
use App\Contracts\EmbeddingStatsRepositoryInterface;
use App\Contracts\ListingDocumentRepositoryInterface;
use App\DTOs\EmbeddingRun;
use App\DTOs\EmbeddingRunSummary;
use App\DTOs\ListingEmbedding;
use App\Exceptions\InvalidEmbeddingException;
use App\Jobs\EmbedListingBatchJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Embeddings;

final readonly class ListingEmbeddingService
{
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

    public function provider(): string
    {
        return (string) config('ai.default_for_embeddings');
    }

    public function model(): string
    {
        return (string) config('ai.providers.'.$this->provider().'.models.embeddings.default');
    }

    public function dimensions(): int
    {
        return (int) config('ai.providers.'.$this->provider().'.models.embeddings.dimensions');
    }

    /**
     * The value stored in embedding_model, so a change of provider or model re-embeds.
     */
    public function modelLabel(): string
    {
        return $this->provider().'/'.$this->model();
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

        $ids = $this->documents->staleListingIds($this->modelLabel(), $limit);
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
        $documents = $this->documents->staleDocuments($listingIds, $this->modelLabel());

        if ($documents === []) {
            return 0;
        }

        $dimensions = $this->dimensions();
        $vectors = Embeddings::for(array_map(fn ($document): string => $document->description, $documents))
            ->dimensions($dimensions)
            ->timeout((int) config('ai.embeddings.timeout'))
            ->generate($this->provider(), $this->model())
            ->embeddings;

        if (count($vectors) !== count($documents)) {
            throw InvalidEmbeddingException::wrongCount(count($documents), count($vectors));
        }

        $embeddings = [];

        foreach ($documents as $index => $document) {
            $vector = array_values($vectors[$index]);

            if (count($vector) !== $dimensions) {
                throw InvalidEmbeddingException::wrongLength($dimensions, count($vector));
            }

            $embeddings[] = new ListingEmbedding($document->listingId, hash('sha256', $document->description), $vector);
        }

        return $this->documents->storeEmbeddings($embeddings, $this->modelLabel(), CarbonImmutable::now());
    }

    public function summarize(EmbeddingRun $run): EmbeddingRunSummary
    {
        $from = $run->startedAt;
        $finishedAt = $this->documents->lastEmbeddedAt($from) ?? CarbonImmutable::now();
        // Count from the first model call, not from dispatch: worker start-up and the --fresh UPDATE are not embedding time.
        $firstCall = $this->stats->firstCallStartedAt($from);
        $startedAt = $firstCall !== null && $firstCall->isAfter($from) ? $firstCall : $from;

        return new EmbeddingRunSummary(
            model: $this->model(),
            provider: $this->provider(),
            ollamaVersion: $this->ollama->version($this->provider()),
            ollamaNumParallel: $this->ollama->numParallel($this->provider()),
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
