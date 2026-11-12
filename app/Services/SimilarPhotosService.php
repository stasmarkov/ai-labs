<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\PhotoEncoderClient;
use App\Contracts\CaptionSimilarityRepositoryInterface;
use App\Contracts\SimilarPhotosRepositoryInterface;
use App\DTOs\HybridParts;
use App\DTOs\HybridPool;
use App\DTOs\SimilarPhotoMatch;
use App\DTOs\SimilarPhotos;
use Illuminate\Support\Facades\Cache;

final readonly class SimilarPhotosService
{
    public const REASON_NOT_EMBEDDED = 'not embedded';

    public const REASON_OTHER_MODEL = 'index built with another model; run photos:embed --fresh';

    public const REASON_NONE_QUALIFY = 'no photo of another listing is similar enough';

    public function __construct(
        private SimilarPhotosRepositoryInterface $photos,
        private PhotoEncoderClient $encoder,
        private CaptionSimilarityRepositoryInterface $captions,
    ) {}

    /**
     * Up to the configured limit of photos of other listings, best first, with near duplicates and weak matches
     * dropped. A photo without a vector, or one embedded by another model, gets an empty result with a reason.
     *
     * With a weight below 1 the image candidates are re-scored as w * image + (1 - w) * caption similarity; a pair
     * without both caption vectors keeps its image similarity and is flagged as image only. A null or 1.0 weight is
     * the image-only result, unchanged.
     */
    public function for(int $photoId, ?float $w = null): SimilarPhotos
    {
        $w = $this->hybridWeight($w);

        $label = $this->encoder->label();

        // The photo's stored label is checked before the cache: after a re-embed under another model and a switch
        // back, a result cached under the old label would otherwise be served for up to an hour.
        $stored = $this->photos->embeddingModel($photoId);

        if ($stored === null) {
            return new SimilarPhotos([], self::REASON_NOT_EMBEDDED, $label, false);
        }

        if ($stored !== $label) {
            return new SimilarPhotos([], self::REASON_OTHER_MODEL, $label, false);
        }

        $key = $this->cacheKey($photoId, $w);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return new SimilarPhotos(
                array_map(SimilarPhotoMatch::fromArray(...), $cached['matches']),
                $cached['reason'],
                $label,
                true,
                isset($cached['pool']) ? HybridPool::fromArray($cached['pool']) : null,
            );
        }

        $pool = null;

        if ($w === null) {
            $matches = $this->rank($photoId, $this->minScore());
        } else {
            [$matches, $pool] = $this->scoreHybrid($photoId, $w, $this->hybridMinScore($w));
        }

        $reason = $matches === [] ? self::REASON_NONE_QUALIFY : null;

        Cache::put($key, [
            'matches' => array_map(fn (SimilarPhotoMatch $match): array => $match->toArray(), $matches),
            'reason' => $reason,
            'pool' => $pool?->toArray(),
        ], max(1, (int) config('ai.similar_photos.ttl_seconds', 3600)));

        return new SimilarPhotos($matches, $reason, $label, false, $pool);
    }

    /**
     * Forget every cached result of the current label by moving to the next generation.
     */
    public function invalidate(): void
    {
        $key = $this->generationKey($this->encoder->label());

        Cache::add($key, 1, null);
        Cache::increment($key);
    }

    /**
     * Forget every cached hybrid result of the current label by moving to the next text generation. Image-only keys do
     * not carry it, so they stay.
     */
    public function invalidateText(): void
    {
        $key = $this->textGenerationKey($this->encoder->label());

        Cache::add($key, 1, null);
        Cache::increment($key);
    }

    public function textGeneration(string $label): int
    {
        return (int) Cache::get($this->textGenerationKey($label), 1);
    }

    public function cacheKey(int $photoId, ?float $w = null): string
    {
        $label = $this->encoder->label();
        $w = $this->hybridWeight($w);

        // The thresholds are part of the key, so a changed AI_SIMILAR_MIN_SCORE never shows matches cut at the old one.
        $thresholds = $this->minScore().'|'.config('ai.similar_photos.near_duplicate_cutoff').'|'.config('ai.similar_photos.limit');

        if ($w === null) {
            return "similar-photos:{$label}:{$this->generation($label)}:".substr(md5($thresholds), 0, 8).":{$photoId}";
        }

        $hybrid = $thresholds.'|'.$this->hybridMinScore($w);

        // "h2": the hybrid entry shape and cutoff rules of this version; entries written before it are never read.
        return "similar-photos:{$label}:{$this->generation($label)}:h2:t{$this->textGeneration($label)}:w".number_format($w, 4, '.', '').':'.substr(md5($hybrid), 0, 8).":{$photoId}";
    }

    /**
     * The hybrid cutoff for a weight: each weight blends scores on its own scale, so the eval calibrates one cutoff per
     * weight (`hybrid_min_scores`, keyed by the weight with one decimal); a weight without one uses `hybrid_min_score`.
     */
    public function hybridMinScore(float $w): float
    {
        $byWeight = (array) config('ai.similar_photos.hybrid_min_scores', []);

        return (float) ($byWeight[number_format(round($w, 1), 1, '.', '')] ?? config('ai.similar_photos.hybrid_min_score'));
    }

    /**
     * The calibrated minimum similarity, read in one place (config default from the eval, env AI_SIMILAR_MIN_SCORE).
     */
    public function minScore(): float
    {
        return (float) config('ai.similar_photos.min_score');
    }

    /**
     * The matches of a photo embedded with the current label for a given minimum score, uncached: the other
     * listings' best photos in order, without near duplicates, up to the limit (the configured one unless given).
     *
     * @return list<SimilarPhotoMatch>
     */
    public function rank(int $photoId, float $minScore, ?int $limit = null): array
    {
        $label = $this->encoder->label();
        $cutoff = (float) config('ai.similar_photos.near_duplicate_cutoff');
        $limit = max(1, $limit ?? (int) config('ai.similar_photos.limit', 8));
        $candidates = $this->photos->nearest(
            $photoId,
            $label,
            max($limit, (int) config('ai.similar_photos.candidates', 40)),
            (int) config('ai.similar_photos.ef_search', 100),
        );

        $seen = array_filter([$this->photos->listingId($photoId)]);
        $kept = [];

        foreach ($candidates as $candidate) {
            if (in_array($candidate->listingId, $seen, true)
                || $candidate->similarity >= $cutoff
                || $candidate->similarity < $minScore) {
                continue;
            }

            $seen[] = $candidate->listingId;
            $kept[] = $candidate;

            if (count($kept) === $limit) {
                break;
            }
        }

        return $kept;
    }

    /**
     * The hybrid matches of a photo embedded with the current label, uncached: image candidates cut at the near
     * duplicate cutoff on the image similarity, scored with the caption similarity, one photo per other listing, best
     * first, at least the minimum score, up to the limit.
     *
     * @return list<SimilarPhotoMatch>
     */
    public function rankHybrid(int $photoId, float $w, float $minScore, ?int $limit = null): array
    {
        return $this->scoreHybrid($photoId, $w, $minScore, $limit)[0];
    }

    /**
     * The hybrid matches and the counts of the candidate pool they were scored from.
     *
     * @return array{0: list<SimilarPhotoMatch>, 1: HybridPool}
     */
    private function scoreHybrid(int $photoId, float $w, float $minScore, ?int $limit = null): array
    {
        $limit = max(1, $limit ?? (int) config('ai.similar_photos.limit', 8));
        $candidates = $this->hybridCandidates($photoId, $limit);

        $captionSimilarities = $this->captions->captionSimilarities(
            $photoId,
            array_map(fn (SimilarPhotoMatch $candidate): int => $candidate->photoId, $candidates),
            $this->encoder->label(),
        );

        $queryHasCaption = $captionSimilarities !== [] || $this->captions->hasCaptionVector($photoId, $this->encoder->label());

        // Without a caption on the query photo every score is the image similarity, so the image cutoff applies.
        if (! $queryHasCaption) {
            $minScore = $this->minScore();
        }

        $pool = new HybridPool($queryHasCaption, count($candidates), count($captionSimilarities), $minScore);

        return [$this->blend($candidates, $captionSimilarities, $w, $minScore, $limit), $pool];
    }

    /**
     * The image candidates of a photo for the hybrid ranking: the nearest photos of other listings below the near
     * duplicate cutoff on the image similarity, best image first.
     *
     * @return list<SimilarPhotoMatch>
     */
    public function hybridCandidates(int $photoId, ?int $limit = null): array
    {
        $cutoff = (float) config('ai.similar_photos.near_duplicate_cutoff');
        $limit = max(1, $limit ?? (int) config('ai.similar_photos.limit', 8));
        $own = $this->photos->listingId($photoId);

        return array_values(array_filter(
            $this->photos->nearest(
                $photoId,
                $this->encoder->label(),
                max($limit, (int) config('ai.similar_photos.candidates', 40)),
                (int) config('ai.similar_photos.ef_search', 100),
            ),
            fn (SimilarPhotoMatch $candidate): bool => $candidate->listingId !== $own && $candidate->similarity < $cutoff,
        ));
    }

    /**
     * Scores the candidates as w * image + (1 - w) * text similarity, a candidate without a text similarity keeping its
     * image similarity and the image-only flag, then keeps the best photo per listing at least at the minimum score, up
     * to the limit. Ties go by photo id.
     *
     * @param  list<SimilarPhotoMatch>  $candidates
     * @param  array<int, float>  $textSimilarities  by photo id
     * @return list<SimilarPhotoMatch>
     */
    public function blend(array $candidates, array $textSimilarities, float $w, float $minScore, int $limit): array
    {
        $scored = array_map(function (SimilarPhotoMatch $candidate) use ($textSimilarities, $w): SimilarPhotoMatch {
            $caption = $textSimilarities[$candidate->photoId] ?? null;
            $score = $caption === null ? $candidate->similarity : $w * $candidate->similarity + (1 - $w) * $caption;

            return new SimilarPhotoMatch(
                $candidate->photoId,
                $candidate->listingId,
                $candidate->listingTitle,
                $candidate->make,
                $candidate->model,
                $candidate->path,
                $score,
                new HybridParts($candidate->similarity, $caption, $caption === null),
            );
        }, $candidates);

        usort($scored, fn (SimilarPhotoMatch $a, SimilarPhotoMatch $b): int => [$b->similarity, $a->photoId] <=> [$a->similarity, $b->photoId]);

        $seen = [];
        $kept = [];

        foreach ($scored as $match) {
            if (in_array($match->listingId, $seen, true) || $match->similarity < $minScore) {
                continue;
            }

            $seen[] = $match->listingId;
            $kept[] = $match;

            if (count($kept) === $limit) {
                break;
            }
        }

        return $kept;
    }

    /**
     * The weight is rounded to one decimal first. A weight of 1 or more (or none) means image only, reported as null; below that it is clamped to 0.
     */
    private function hybridWeight(?float $w): ?float
    {
        $w = $w === null ? null : round($w, 1);

        return $w === null || $w >= 1.0 ? null : max(0.0, $w);
    }

    /**
     * The index generation of a label: it moves whenever photo vectors of that label are stored, so any cache keyed
     * by it (similar photos here, text search in PhotoTextSearchService) drops its old entries.
     */
    public function generation(string $label): int
    {
        return (int) Cache::get($this->generationKey($label), 1);
    }

    private function textGenerationKey(string $label): string
    {
        return "similar-photos:text-generation:{$label}";
    }

    private function generationKey(string $label): string
    {
        return "similar-photos:generation:{$label}";
    }
}
