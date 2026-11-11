<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviders;
use App\Ai\PhotoEncoderClient;
use App\Contracts\SimilarEvalRepositoryInterface;
use App\DTOs\SimilarEvalPhoto;
use App\DTOs\SimilarEvalQuery;

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

    public function __construct(
        private SimilarEvalRepositoryInterface $repository,
        private SimilarPhotosService $similar,
        private SimilarEvalScorer $scorer,
        private PhotoEncoderClient $encoder,
        private AiProviders $providers,
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
