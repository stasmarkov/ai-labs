<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Contracts\EmbeddingIndexRepositoryInterface;
use App\Contracts\SemanticSearchRepositoryInterface;
use App\DTOs\SemanticMatch;
use App\Exceptions\IndexNotBuiltException;
use App\Exceptions\InvalidEmbeddingException;
use App\Exceptions\SemanticSearchUnavailableException;
use App\Support\ServerTiming;
use Laravel\Ai\Embeddings;
use Throwable;

final readonly class SemanticSearchService
{
    public const LIMIT = 10;

    public const QUERY_AGENT = 'embeddings:query';

    public function __construct(
        private SemanticSearchRepositoryInterface $matches,
        private ListingEmbeddingService $embeddings,
        private EmbeddingIndexRepositoryInterface $index,
        private ServerTiming $timing,
    ) {}

    /**
     * @return list<SemanticMatch>
     *
     * @throws SemanticSearchUnavailableException when the query cannot be embedded.
     * @throws IndexNotBuiltException when the listings were embedded with another model.
     */
    public function search(string $query): array
    {
        if ($this->index->listingsEmbeddedOnlyByOtherModels($this->embeddings->modelLabel(ListingEmbeddingService::FEATURE))) {
            throw IndexNotBuiltException::forModel($this->embeddings->modelLabel(ListingEmbeddingService::FEATURE), 'php artisan listings:embed --fresh');
        }

        $vector = $this->embedQuery($query, ListingEmbeddingService::FEATURE);

        return $this->timing->measure('db', fn (): array => $this->matches->nearest($vector, self::LIMIT, (int) config('ai.embeddings.ef_search'), $this->embeddings->modelLabel(ListingEmbeddingService::FEATURE)));
    }

    /**
     * @return list<float>
     *
     * @throws SemanticSearchUnavailableException when the query cannot be embedded.
     */
    public function embedQuery(string $query, string $feature): array
    {
        $dimensions = $this->embeddings->dimensions($feature);
        app(AiRunRecorder::class)->nextEmbeddingsAgent(self::QUERY_AGENT);

        try {
            $vector = array_values($this->timing->measure(
                'embed',
                fn (): array => Embeddings::for([$query])
                    ->dimensions($dimensions)
                    ->timeout((int) config('ai.embeddings.timeout'))
                    ->generate($this->embeddings->provider($feature), $this->embeddings->model($feature))
                    ->embeddings[0] ?? [],
            ));
        } catch (Throwable $e) {
            throw SemanticSearchUnavailableException::because($e);
        } finally {
            app(AiRunRecorder::class)->nextEmbeddingsAgent(null);
        }

        if (count($vector) !== $dimensions) {
            throw SemanticSearchUnavailableException::because(
                InvalidEmbeddingException::wrongLength($dimensions, count($vector)),
            );
        }

        return $vector;
    }
}
