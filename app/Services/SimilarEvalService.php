<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviders;
use App\Ai\PhotoEncoderClient;
use App\Contracts\CaptionSimilarityRepositoryInterface;
use App\Contracts\SimilarEvalRepositoryInterface;
use App\DTOs\SimilarEvalPhoto;
use App\DTOs\SimilarEvalQuery;
use App\DTOs\SimilarPhotoMatch;

/**
 * Runs every embedded photo as a query against the similar-photos service and measures the answers.
 * Latency is the in-process service call (database, filtering and cache), without HTTP.
 */
final readonly class SimilarEvalService
{
    /**
     * Minimum scores of the threshold curve, from which min_score is calibrated.
     */
    public const THRESHOLDS = [0.60, 0.65, 0.70, 0.75, 0.80, 0.85, 0.90, 0.95];

    /**
     * The image weights of the hybrid sweep.
     */
    public const WEIGHTS = [0.0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0];

    /**
     * The in-process budget of an uncached hybrid request, in milliseconds. Over it is recorded, not failed.
     */
    public const LATENCY_BUDGET_MS = 80.0;

    public function __construct(
        private SimilarEvalRepositoryInterface $repository,
        private SimilarPhotosService $similar,
        private SimilarEvalScorer $scorer,
        private PhotoEncoderClient $encoder,
        private AiProviders $providers,
        private CaptionSimilarityRepositoryInterface $captions,
        private HybridEvalScorer $hybrid,
        private PhotoTextSearchService $textSearch,
    ) {}

    /**
     * The photos embedded with the current model label.
     *
     * @return list<SimilarEvalPhoto>
     */
    public function photos(): array
    {
        return $this->repository->embeddedPhotos($this->encoder->label());
    }

    /**
     * @param  list<SimilarEvalPhoto>  $photos
     * @return array<string, mixed>
     */
    public function evaluate(array $photos, int $k): array
    {
        $minScore = (float) config('ai.similar_photos.min_score');
        $choice = $this->providers->configured(PhotoEmbeddingService::FEATURE);
        $totals = $this->repository->encoderRunTotals(PhotoEmbeddingService::AGENT, PhotoEmbeddingService::INVOCATION_PREFIX, $choice->provider, $choice->model);

        return [
            'metrics' => $this->scorer->score($this->queries($photos, $minScore, $k), $k),
            'curve' => array_map(
                fn (float $threshold): array => ['threshold' => $threshold] + array_diff_key(
                    $this->scorer->score($this->queries($photos, $threshold, $k), $k),
                    ['queries' => 0, 'returned' => 0],
                ),
                self::THRESHOLDS,
            ),
            'latency' => $this->latency($photos),
            'throughput' => [
                'photos' => $totals->photos,
                'encoder_ms' => $totals->latencyMs,
                'photos_per_second' => $this->scorer->throughput($totals->photos, $totals->latencyMs),
            ],
        ];
    }

    /**
     * The hybrid eval: for every weight, the caption-blended ranking over the image candidates with its minimum score
     * calibrated; the image-only baseline; the same sweep with listing titles in place of captions, which leaks the
     * ground truth and is never chosen; and the uncached latency at the weight a user would get.
     *
     * @param  list<SimilarEvalPhoto>  $photos
     * @return array<string, mixed>
     */
    public function evaluateHybrid(array $photos, int $k): array
    {
        $label = $this->encoder->label();
        $minScore = $this->similar->minScore();
        $titleVectors = $this->titleVectors($label);
        $sources = ['caption', 'title'];
        $empty = ['answered' => 0, 'returned' => 0, 'make' => 0, 'make_model' => 0, 'text_pairs' => 0];
        $baseline = $empty;
        $totals = [];

        foreach ($sources as $source) {
            $totals[$source] = array_map(fn (): array => array_fill(0, count(self::THRESHOLDS), $empty), self::WEIGHTS);
        }

        $memo = [];

        foreach ($photos as $photo) {
            $baseline = $this->add($baseline, $this->hybrid->tally($photo->make, $photo->model, $this->similar->rank($photo->photoId, $minScore, $k), $k));

            $candidates = $this->similar->hybridCandidates($photo->photoId, $k);
            $similarities = [
                'caption' => $this->captions->captionSimilarities($photo->photoId, array_map(fn (SimilarPhotoMatch $c): int => $c->photoId, $candidates), $label),
                'title' => $this->titleSimilarities($photo, $candidates, $titleVectors, $memo),
            ];

            foreach ($sources as $source) {
                foreach (self::WEIGHTS as $wi => $w) {
                    $ranked = $this->similar->blend($candidates, $similarities[$source], $w, -INF, max(1, count($candidates)));

                    foreach (self::THRESHOLDS as $ti => $threshold) {
                        $kept = array_values(array_filter($ranked, fn (SimilarPhotoMatch $match): bool => $match->similarity >= $threshold));
                        $totals[$source][$wi][$ti] = $this->add($totals[$source][$wi][$ti], $this->hybrid->tally($photo->make, $photo->model, $kept, $k));
                    }
                }
            }
        }

        $queries = count($photos);
        $baselineMetrics = ['w' => 1.0, 'min_score' => $minScore] + $this->withoutObjective($this->hybrid->metrics($baseline, $queries));
        $caption = $this->sweep($totals['caption'], $queries);
        $best = $this->hybrid->best($caption, $baselineMetrics);
        $weight = $best['w'] ?? (float) config('ai.similar_photos.default_weight');

        return [
            'baseline' => $baselineMetrics,
            'sweep' => $caption,
            'best' => $best === null
                ? ['w' => 1.0, 'min_score' => $minScore, 'precision_make_model' => $baselineMetrics['precision_make_model'], 'coverage' => $baselineMetrics['coverage'], 'beats_baseline' => false]
                : ['w' => $best['w'], 'min_score' => $best['min_score'], 'precision_make_model' => $best['precision_make_model'], 'coverage' => $best['coverage'], 'beats_baseline' => true],
            'title' => [
                'source' => 'title',
                'leaks_ground_truth' => true,
                'headline' => false,
                'note' => 'The listing title holds the make and model the eval grades, so these numbers measure the leak, not the photos. Never chosen as the default.',
                'sweep' => $this->sweep($totals['title'], $queries),
            ],
            'latency' => $this->hybridLatency($photos, $weight),
        ];
    }

    /**
     * @param  list<list<array{answered: int, returned: int, make: int, make_model: int, text_pairs: int}>>  $totals  by weight, then threshold
     * @return list<array{w: float, min_score: float, precision_make_model: float, coverage: float, curve: list<array<string, mixed>>}>
     */
    private function sweep(array $totals, int $queries): array
    {
        $rows = [];

        foreach (self::WEIGHTS as $wi => $w) {
            $curve = [];

            foreach (self::THRESHOLDS as $ti => $threshold) {
                $curve[] = ['threshold' => $threshold] + $this->hybrid->metrics($totals[$wi][$ti], $queries);
            }

            $chosen = $curve[$this->hybrid->calibrate(array_column($curve, 'objective'))];
            $rows[] = ['w' => $w, 'min_score' => $chosen['threshold']]
                + $this->withoutObjective(array_diff_key($chosen, ['threshold' => 0]))
                + ['curve' => array_map(fn (array $point): array => array_diff_key($point, ['queries' => 0, 'returned' => 0]), $curve)];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function withoutObjective(array $metrics): array
    {
        return array_diff_key($metrics, ['objective' => 0]);
    }

    /**
     * @param  array{answered: int, returned: int, make: int, make_model: int, text_pairs: int}  $a
     * @param  array{answered: int, returned: int, make: int, make_model: int, text_pairs: int}  $b
     * @return array{answered: int, returned: int, make: int, make_model: int, text_pairs: int}
     */
    private function add(array $a, array $b): array
    {
        return [
            'answered' => $a['answered'] + $b['answered'],
            'returned' => $a['returned'] + $b['returned'],
            'make' => $a['make'] + $b['make'],
            'make_model' => $a['make_model'] + $b['make_model'],
            'text_pairs' => $a['text_pairs'] + $b['text_pairs'],
        ];
    }

    /**
     * The templated title of every embedded listing, encoded in memory; nothing is written to the database.
     *
     * @return array<string, list<float>>
     */
    private function titleVectors(string $label): array
    {
        $titles = $this->repository->embeddedTitles($label);

        if ($titles === []) {
            return [];
        }

        $vectors = $this->encoder->embedTexts(array_map($this->textSearch->prompt(...), $titles));

        return array_combine($titles, $vectors);
    }

    /**
     * @param  list<SimilarPhotoMatch>  $candidates
     * @param  array<string, list<float>>  $vectors
     * @param  array<string, float>  $memo
     * @return array<int, float>
     */
    private function titleSimilarities(SimilarEvalPhoto $photo, array $candidates, array $vectors, array &$memo): array
    {
        $own = $vectors[$photo->title] ?? null;
        $similarities = [];

        foreach ($candidates as $candidate) {
            $other = $vectors[$candidate->listingTitle] ?? null;

            if ($own === null || $other === null) {
                continue;
            }

            $similarities[$candidate->photoId] = $memo[$photo->title."\0".$candidate->listingTitle] ??= $this->hybrid->cosine($own, $other);
        }

        return $similarities;
    }

    /**
     * Uncached after one warm-up pass: a new cache generation, so every call runs its queries.
     *
     * @param  list<SimilarEvalPhoto>  $photos
     * @return array<string, mixed>
     */
    private function hybridLatency(array $photos, float $w): array
    {
        foreach ($photos as $photo) {
            $this->similar->for($photo->photoId, $w);
        }

        // Hybrid keys carry the text generation, so moving it is enough; the image-only cache (and the listing page
        // block that reads it) stays warm.
        $this->similar->invalidateText();

        $samples = array_map(function (SimilarEvalPhoto $photo) use ($w): int {
            $startedAt = hrtime(true);
            $this->similar->for($photo->photoId, $w);

            return intdiv(hrtime(true) - $startedAt, 1000);
        }, $photos);
        $uncached = $this->scorer->latency($samples);

        return [
            'w' => $w,
            'uncached' => $uncached,
            'budget_ms' => self::LATENCY_BUDGET_MS,
            'within_budget' => $uncached['p95_ms'] !== null && $uncached['p95_ms'] <= self::LATENCY_BUDGET_MS,
        ];
    }

    /**
     * @param  list<SimilarEvalPhoto>  $photos
     * @return list<SimilarEvalQuery>
     */
    private function queries(array $photos, float $minScore, int $k): array
    {
        return array_map(
            fn (SimilarEvalPhoto $photo): SimilarEvalQuery => new SimilarEvalQuery($photo->make, $photo->model, $this->similar->rank($photo->photoId, $minScore, $k)),
            $photos,
        );
    }

    /**
     * Uncached: a new cache generation, so every call goes to the database. Cached: the same calls again.
     *
     * @param  list<SimilarEvalPhoto>  $photos
     * @return array{uncached: array<string, mixed>, cached: array<string, mixed>}
     */
    private function latency(array $photos): array
    {
        $this->similar->invalidate();

        $uncached = array_map(fn (SimilarEvalPhoto $photo): int => $this->timed($photo->photoId), $photos);
        $cached = array_map(fn (SimilarEvalPhoto $photo): int => $this->timed($photo->photoId), $photos);

        return ['uncached' => $this->scorer->latency($uncached), 'cached' => $this->scorer->latency($cached)];
    }

    private function timed(int $photoId): int
    {
        $startedAt = hrtime(true);
        $this->similar->for($photoId);

        return intdiv(hrtime(true) - $startedAt, 1000);
    }
}
