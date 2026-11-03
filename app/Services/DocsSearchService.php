<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DocsSearchRepositoryInterface;
use App\DTOs\DocsHit;
use App\DTOs\DocsMatch;
use App\Enums\DocsSearchMode;
use App\Exceptions\DocsSearchUnavailableException;
use App\Exceptions\SemanticSearchUnavailableException;
use App\Support\ServerTiming;

final readonly class DocsSearchService
{
    public const RRF_CONSTANT = 60;

    public const MAX_QUERY_LENGTH = 200;

    public function __construct(
        private DocsSearchRepositoryInterface $chunks,
        private SemanticSearchService $queries,
        private ListingEmbeddingService $embeddings,
        private ServerTiming $timing,
    ) {}

    public function defaultK(): int
    {
        return max(1, (int) config('ai.docs.k_default'));
    }

    public function maxK(): int
    {
        return max(1, (int) config('ai.docs.k_max'));
    }

    /**
     * @param  list<float>|null  $queryVector  the query already embedded, so a caller running several modes embeds it once
     * @return list<DocsMatch>
     *
     * @throws DocsSearchUnavailableException when the query cannot be embedded (vector and hybrid modes).
     */
    public function search(string $query, DocsSearchMode $mode, int $k, ?array $queryVector = null): array
    {
        // The fused list can only fill k places if each input list has at least k rows.
        $depth = max($k, (int) config('ai.docs.rrf_depth'));

        return match ($mode) {
            DocsSearchMode::Vector => $this->single($this->vectorList($query, $k, $queryVector), true),
            DocsSearchMode::Text => $this->single($this->timing->measure('db', fn (): array => $this->chunks->textMatches($query, $k)), false),
            DocsSearchMode::Hybrid => $this->fuse($this->vectorList($query, $depth, $queryVector), $this->timing->measure('db', fn (): array => $this->chunks->textMatches($query, $depth)), $k),
        };
    }

    /**
     * @return list<float>
     *
     * @throws DocsSearchUnavailableException when the query cannot be embedded.
     */
    public function embedQuery(string $query): array
    {
        try {
            return $this->queries->embedQuery($query);
        } catch (SemanticSearchUnavailableException $e) {
            throw DocsSearchUnavailableException::because($e->getPrevious() ?? $e);
        }
    }

    /**
     * @param  list<float>|null  $queryVector
     * @return list<DocsHit>
     */
    private function vectorList(string $query, int $limit, ?array $queryVector): array
    {
        $vector = $queryVector ?? $this->embedQuery($query);

        return $this->timing->measure('db', fn (): array => $this->chunks->nearest($vector, $this->embeddings->modelLabel(), $limit, (int) config('ai.embeddings.ef_search')));
    }

    /**
     * @param  list<DocsHit>  $hits
     * @return list<DocsMatch>
     */
    private function single(array $hits, bool $isVector): array
    {
        $matches = [];

        foreach ($hits as $index => $hit) {
            $rank = $index + 1;
            $matches[] = new DocsMatch(
                id: $hit->id,
                source: $hit->source,
                path: $hit->path,
                heading: $hit->heading,
                body: $hit->body,
                score: $hit->value,
                vectorRank: $isVector ? $rank : null,
                vectorSimilarity: $isVector ? $hit->value : null,
                textRank: $isVector ? null : $rank,
                textScore: $isVector ? null : $hit->value,
            );
        }

        return $matches;
    }

    /**
     * Reciprocal rank fusion: a chunk scores the sum of 1 / (60 + rank) over the lists it is in, so the two score scales are never compared.
     *
     * @param  list<DocsHit>  $vectorHits
     * @param  list<DocsHit>  $textHits
     * @return list<DocsMatch>
     */
    private function fuse(array $vectorHits, array $textHits, int $k): array
    {
        $candidates = [];

        foreach ($vectorHits as $index => $hit) {
            $candidates[$hit->id] = ['hit' => $hit, 'vector' => [$index + 1, $hit->value], 'text' => null];
        }

        foreach ($textHits as $index => $hit) {
            $candidates[$hit->id] = [
                'hit' => $candidates[$hit->id]['hit'] ?? $hit,
                'vector' => $candidates[$hit->id]['vector'] ?? null,
                'text' => [$index + 1, $hit->value],
            ];
        }

        $matches = array_map(fn (array $candidate): DocsMatch => new DocsMatch(
            id: $candidate['hit']->id,
            source: $candidate['hit']->source,
            path: $candidate['hit']->path,
            heading: $candidate['hit']->heading,
            body: $candidate['hit']->body,
            score: ($candidate['vector'] === null ? 0.0 : 1 / (self::RRF_CONSTANT + $candidate['vector'][0]))
                + ($candidate['text'] === null ? 0.0 : 1 / (self::RRF_CONSTANT + $candidate['text'][0])),
            vectorRank: $candidate['vector'][0] ?? null,
            vectorSimilarity: $candidate['vector'][1] ?? null,
            textRank: $candidate['text'][0] ?? null,
            textScore: $candidate['text'][1] ?? null,
        ), array_values($candidates));

        usort($matches, fn (DocsMatch $a, DocsMatch $b): int => [$b->score, $a->id] <=> [$a->score, $b->id]);

        return array_slice($matches, 0, $k);
    }
}
