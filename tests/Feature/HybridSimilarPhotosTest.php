<?php

declare(strict_types=1);

use App\Contracts\ListingPhotoRepositoryInterface;
use App\DTOs\PhotoEmbedding;
use App\DTOs\PhotoTextEmbedding;
use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Services\SimilarPhotosService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

const HYBRID_LABEL = 'photo-encoder/openai/clip-vit-base-patch32';

beforeEach(function (): void {
    config([
        'ai.features.embeddings-photos.provider' => 'photo-encoder',
        'ai.features.embeddings-photos.model' => null,
        'ai.providers.photo-encoder.url' => 'http://encoder.test:7997',
        'ai.similar_photos.min_score' => 0.50,
        'ai.similar_photos.hybrid_min_score' => 0.50,
        'ai.similar_photos.near_duplicate_cutoff' => 0.98,
    ]);
    Cache::flush();
});

/**
 * A unit vector at the given cosine similarity to [1, 0, 0, ...].
 *
 * @return list<float>
 */
function hybridVector(float $score): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[0] = $score;
    $vector[1] = sqrt(1 - $score * $score);

    return $vector;
}

/**
 * @param  list<float>|null  $caption
 */
function hybridPhoto(float $image, ?array $caption): ListingPhoto
{
    $photo = ListingPhoto::factory()->create(['listing_id' => Listing::factory()->create()->id]);
    $repository = app(ListingPhotoRepositoryInterface::class);
    $repository->storeEmbeddings([new PhotoEmbedding($photo->id, hybridVector($image))], HYBRID_LABEL, CarbonImmutable::now());

    if ($caption !== null) {
        $repository->storeTextEmbeddings([new PhotoTextEmbedding($photo->id, $caption, 'hash')], HYBRID_LABEL, CarbonImmutable::now());
    }

    return $photo;
}

/**
 * @return list<int>
 */
function hybridIds(int $photoId, ?float $w): array
{
    return array_map(fn ($match): int => $match->photoId, app(SimilarPhotosService::class)->for($photoId, $w)->matches);
}

it('AC-3 weight 1 equals the image-only result exactly', function (): void {
    $query = hybridPhoto(1.0, hybridVector(1.0));
    hybridPhoto(0.90, hybridVector(0.60));
    hybridPhoto(0.85, hybridVector(0.95));
    hybridPhoto(0.99, hybridVector(0.99));
    $service = app(SimilarPhotosService::class);

    $plain = $service->for($query->id);
    Cache::flush();
    $one = $service->for($query->id, 1.0);

    expect($one->matches)->toEqual($plain->matches)
        ->and($one->matches[0]->parts)->toBeNull()
        ->and($service->cacheKey($query->id, 1.0))->toBe($service->cacheKey($query->id))
        ->and($service->cacheKey($query->id, null))->toBe($service->cacheKey($query->id));
});

it('AC-2 reorders by the weighted score at w 0.5 on crafted vectors', function (): void {
    $query = hybridPhoto(1.0, hybridVector(1.0));
    $imageFirst = hybridPhoto(0.90, hybridVector(0.55));
    $captionFirst = hybridPhoto(0.85, hybridVector(0.95));

    expect(hybridIds($query->id, 1.0))->toBe([$imageFirst->id, $captionFirst->id])
        ->and(hybridIds($query->id, 0.5))->toBe([$captionFirst->id, $imageFirst->id]);

    $top = app(SimilarPhotosService::class)->for($query->id, 0.5)->matches[0];

    expect($top->similarity)->toEqualWithDelta(0.5 * 0.85 + 0.5 * 0.95, 1e-5)
        ->and($top->parts->image)->toEqualWithDelta(0.85, 1e-5)
        ->and($top->parts->caption)->toEqualWithDelta(0.95, 1e-5)
        ->and($top->parts->imageOnly)->toBeFalse();
});

it('AC-2 flags a photo without a caption as image only and keeps its image score', function (): void {
    $query = hybridPhoto(1.0, hybridVector(1.0));
    $missing = hybridPhoto(0.90, null);

    $match = app(SimilarPhotosService::class)->for($query->id, 0.5)->matches[0];

    expect($match->photoId)->toBe($missing->id)
        ->and($match->parts->imageOnly)->toBeTrue()
        ->and($match->parts->caption)->toBeNull()
        ->and($match->similarity)->toEqualWithDelta(0.90, 1e-5);
});

it('AC-2 flags every match as image only when the query photo has no caption', function (): void {
    $query = hybridPhoto(1.0, null);
    hybridPhoto(0.90, hybridVector(0.90));

    $match = app(SimilarPhotosService::class)->for($query->id, 0.5)->matches[0];

    expect($match->parts->imageOnly)->toBeTrue()->and($match->similarity)->toEqualWithDelta(0.90, 1e-5);
});

it('AC-2 cuts near duplicates on the image similarity and weak scores at hybrid_min_score', function (): void {
    $query = hybridPhoto(1.0, hybridVector(1.0));
    $duplicate = hybridPhoto(0.99, hybridVector(0.60));
    $weak = hybridPhoto(0.80, hybridVector(0.10));
    $kept = hybridPhoto(0.85, hybridVector(0.85));
    config(['ai.similar_photos.hybrid_min_score' => 0.60]);

    $ids = hybridIds($query->id, 0.5);

    expect($ids)->toBe([$kept->id])->and($ids)->not->toContain($duplicate->id, $weak->id);
});

it('AC-2 keeps one photo per listing and excludes the query listing', function (): void {
    $query = hybridPhoto(1.0, hybridVector(1.0));
    $sibling = ListingPhoto::factory()->create(['listing_id' => $query->listing_id]);
    app(ListingPhotoRepositoryInterface::class)->storeEmbeddings([new PhotoEmbedding($sibling->id, hybridVector(0.9))], HYBRID_LABEL, CarbonImmutable::now());
    $first = hybridPhoto(0.90, hybridVector(0.90));
    $second = ListingPhoto::factory()->create(['listing_id' => $first->listing_id]);
    app(ListingPhotoRepositoryInterface::class)->storeEmbeddings([new PhotoEmbedding($second->id, hybridVector(0.88))], HYBRID_LABEL, CarbonImmutable::now());

    expect(hybridIds($query->id, 0.5))->toBe([$first->id]);
});

it('NFR-1 a hybrid request adds one caption query over the candidates', function (): void {
    $query = hybridPhoto(1.0, hybridVector(1.0));
    foreach ([0.9, 0.8, 0.7] as $score) {
        hybridPhoto($score, hybridVector($score));
    }
    $service = app(SimilarPhotosService::class);

    DB::enableQueryLog();
    $service->for($query->id);
    $imageOnly = count(DB::getQueryLog());
    Cache::flush();
    DB::flushQueryLog();
    $service->for($query->id, 0.5);
    $hybrid = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($hybrid)->toBe($imageOnly + 1);
});

it('AC-2 the cache varies by w and by the text generation and serves the parts back', function (): void {
    $query = hybridPhoto(1.0, hybridVector(1.0));
    hybridPhoto(0.90, hybridVector(0.80));
    $service = app(SimilarPhotosService::class);

    expect($service->cacheKey($query->id, 0.5))->not->toBe($service->cacheKey($query->id, 0.25))
        ->and($service->cacheKey($query->id, 0.5))->not->toBe($service->cacheKey($query->id));

    $before = $service->cacheKey($query->id, 0.5);
    $imageKey = $service->cacheKey($query->id);
    $first = $service->for($query->id, 0.5);
    $second = $service->for($query->id, 0.5);

    expect($first->cached)->toBeFalse()
        ->and($second->cached)->toBeTrue()
        ->and($second->matches)->toEqual($first->matches)
        ->and($service->for($query->id, 0.25)->cached)->toBeFalse();

    $service->invalidateText();

    expect($service->cacheKey($query->id, 0.5))->not->toBe($before)
        ->and($service->cacheKey($query->id))->toBe($imageKey)
        ->and($service->for($query->id, 0.5)->cached)->toBeFalse();
});
