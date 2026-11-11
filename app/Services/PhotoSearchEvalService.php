<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\PhotoEncoderClient;
use App\Contracts\PhotoSearchEvalRepositoryInterface;
use App\DTOs\PhotoSearchEvalQuery;
use App\DTOs\SimilarPhotoMatch;
use Database\Seeders\Catalog;

/**
 * Runs the labelled queries through the photo text search, uncached and in-process, and grades the answers.
 *
 * Each prompt variant is searched once at the lowest threshold of the curve; every higher threshold is that same
 * answer cut at a higher score, which is exactly what a search at that threshold returns (best photo per listing
 * first, the cutoff then the limit).
 */
final readonly class PhotoSearchEvalService
{
    public const EXTRACTION_MODEL = 'gemma4:12b';

    public const EXTRACTION_PROMPT = 'photo-vision@v1';

    public const BARE_PROMPT = 'photo-search.v0';

    public function __construct(
        private PhotoSearchEvalRepositoryInterface $repository,
        private PhotoTextSearchService $search,
        private PhotoSearchEvalScorer $scorer,
        private SimilarEvalScorer $latencyScorer,
        private PhotoEncoderClient $encoder,
    ) {}

    /**
     * The ids of the photos embedded with the current label.
     *
     * @return list<int>
     */
    public function embeddedPhotoIds(): array
    {
        return $this->repository->embeddedPhotoIds($this->encoder->label());
    }

    /**
     * @param  list<PhotoSearchEvalQuery>  $queries
     * @param  list<int>  $embedded
     * @return array<string, mixed>
     */
    public function evaluate(array $queries, array $embedded, int $k): array
    {
        $colours = array_intersect_key($this->repository->dominantColours(self::EXTRACTION_MODEL, self::EXTRACTION_PROMPT), array_flip($embedded));
        $template = (string) config('ai.photo_search.prompt');

        $withTemplate = $this->variant($template, $queries, $colours, $k);
        $chosen = $withTemplate['chosen'];
        $bare = $this->variant(self::BARE_PROMPT, $queries, $colours, $k);

        return [
            'pool' => ['embedded_photos' => count($embedded), 'colour_photos' => count($colours)],
            'chosen_min_score' => $chosen,
            'variants' => [
                'template' => $this->report($withTemplate, $chosen),
                'bare' => $this->report($bare, $chosen),
            ],
            'latency' => ['uncached' => $withTemplate['latency'], 'cached' => $this->cachedLatency($queries, $chosen)],
        ];
    }

    /**
     * @param  list<PhotoSearchEvalQuery>  $queries
     * @param  array<int, list<string>>  $colours
     * @return array{prompt: string, answers: array<string, list<SimilarPhotoMatch>>, queries: list<PhotoSearchEvalQuery>, colours: array<int, list<string>>, k: int, curve: list<array<string, mixed>>, chosen: ?float, latency: array<string, mixed>}
     */
    private function variant(string $prompt, array $queries, array $colours, int $k): array
    {
        $previous = config('ai.photo_search');
        config(['ai.photo_search.prompt' => $prompt, 'ai.photo_search.min_score' => PhotoSearchEvalScorer::THRESHOLDS[0]]);

        try {
            $answers = [];
            $micros = [];

            foreach ($queries as $query) {
                $startedAt = hrtime(true);
                $answers[$query->id] = $this->search->search($query->query, 'eval', false)->results;
                $micros[] = intdiv(hrtime(true) - $startedAt, 1000);
            }
        } finally {
            config(['ai.photo_search' => $previous]);
        }

        $variant = ['prompt' => $prompt, 'answers' => $answers, 'queries' => $queries, 'colours' => $colours, 'k' => $k];
        $curve = array_map(
            fn (float $threshold): array => ['threshold' => $threshold] + $this->scorer->score($this->flags($variant, $threshold)),
            PhotoSearchEvalScorer::THRESHOLDS,
        );

        return $variant + ['curve' => $curve, 'chosen' => $this->scorer->choose($curve), 'latency' => $this->latencyScorer->latency($micros)];
    }

    /**
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>
     */
    private function report(array $variant, ?float $threshold): array
    {
        $atThreshold = $threshold === null ? null : $this->scorer->score($this->flags($variant, $threshold));
        $perQuery = [];

        if ($threshold !== null) {
            foreach ($this->graded($variant, $threshold) as $id => $flags) {
                $perQuery[] = ['id' => $id, 'returned' => count($flags), 'relevant' => count(array_filter($flags))];
            }
        }

        return [
            'prompt' => $variant['prompt'],
            'chosen_min_score' => $variant['chosen'],
            'compared_at_min_score' => $threshold,
            'metrics' => $atThreshold,
            'curve' => $variant['curve'],
            'per_query' => $perQuery,
        ];
    }

    /**
     * @param  array<string, mixed>  $variant
     * @return list<list<bool>>
     */
    private function flags(array $variant, float $threshold): array
    {
        return array_values($this->graded($variant, $threshold));
    }

    /**
     * The relevance flags of each query's top k at a threshold. A colour query is graded over the photos that have a
     * completed extraction only.
     *
     * @param  array<string, mixed>  $variant
     * @return array<string, list<bool>>
     */
    private function graded(array $variant, float $threshold): array
    {
        $graded = [];

        foreach ($variant['queries'] as $query) {
            $matches = array_values(array_filter(
                $variant['answers'][$query->id],
                fn (SimilarPhotoMatch $match): bool => $match->similarity >= $threshold && (! $query->usesColour() || isset($variant['colours'][$match->photoId])),
            ));

            $graded[$query->id] = array_map(
                fn (SimilarPhotoMatch $match): bool => $this->isRelevant($query, $match, $variant['colours'][$match->photoId] ?? []),
                array_slice($matches, 0, $variant['k']),
            );
        }

        return $graded;
    }

    /**
     * @param  list<string>  $colours
     */
    public function isRelevant(PhotoSearchEvalQuery $query, SimilarPhotoMatch $match, array $colours): bool
    {
        foreach ($query->rule as $field => $wanted) {
            $matches = match ($field) {
                'make' => $match->make === $wanted,
                'model' => $match->model === $wanted,
                'body_type' => (Catalog::BODY_TYPE_BY_MODEL[$match->model] ?? null) === $wanted,
                default => in_array($this->colour($wanted), array_map($this->colour(...), $colours), true),
            };

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    private function colour(string $colour): string
    {
        return $colour === 'silver' ? 'grey' : $colour;
    }

    /**
     * Every query once to fill the cache at the chosen threshold, then once more, timed, from the cache.
     *
     * @param  list<PhotoSearchEvalQuery>  $queries
     * @return array<string, mixed>
     */
    private function cachedLatency(array $queries, ?float $threshold): array
    {
        $previous = config('ai.photo_search');
        config(['ai.photo_search.min_score' => $threshold ?? $this->search->minScore()]);

        try {
            foreach ($queries as $query) {
                $this->search->search($query->query, 'eval');
            }

            $micros = [];

            foreach ($queries as $query) {
                $startedAt = hrtime(true);
                $this->search->search($query->query, 'eval');
                $micros[] = intdiv(hrtime(true) - $startedAt, 1000);
            }
        } finally {
            config(['ai.photo_search' => $previous]);
        }

        return $this->latencyScorer->latency($micros);
    }
}
