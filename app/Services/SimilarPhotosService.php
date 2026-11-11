<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\PhotoEncoderClient;
use App\Contracts\SimilarPhotosRepositoryInterface;
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
    ) {}

    /**
     * Up to the configured limit of photos of other listings, best first, with near duplicates and weak matches
     * dropped. A photo without a vector, or one embedded by another model, gets an empty result with a reason.
     */
    public function for(int $photoId): SimilarPhotos
    {
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

        $key = $this->cacheKey($photoId);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return new SimilarPhotos(
                array_map(SimilarPhotoMatch::fromArray(...), $cached['matches']),
                $cached['reason'],
                $label,
                true,
            );
        }

        $matches = $this->rank($photoId, $this->minScore());
        $reason = $matches === [] ? self::REASON_NONE_QUALIFY : null;

        Cache::put($key, [
            'matches' => array_map(fn (SimilarPhotoMatch $match): array => $match->toArray(), $matches),
            'reason' => $reason,
        ], max(1, (int) config('ai.similar_photos.ttl_seconds', 3600)));

        return new SimilarPhotos($matches, $reason, $label, false);
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

    public function cacheKey(int $photoId): string
    {
        $label = $this->encoder->label();

        // The thresholds are part of the key, so a changed AI_SIMILAR_MIN_SCORE never shows matches cut at the old one.
        $thresholds = $this->minScore().'|'.config('ai.similar_photos.near_duplicate_cutoff').'|'.config('ai.similar_photos.limit');

        return "similar-photos:{$label}:{$this->generation($label)}:".substr(md5($thresholds), 0, 8).":{$photoId}";
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

    private function generation(string $label): int
    {
        return (int) Cache::get($this->generationKey($label), 1);
    }

    private function generationKey(string $label): string
    {
        return "similar-photos:generation:{$label}";
    }
}
