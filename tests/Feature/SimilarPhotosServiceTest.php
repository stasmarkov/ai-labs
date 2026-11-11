<?php

declare(strict_types=1);

use App\Contracts\ListingPhotoRepositoryInterface;
use App\Contracts\SimilarPhotosRepositoryInterface;
use App\DTOs\PhotoEmbedding;
use App\DTOs\SimilarPhotoMatch;
use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Services\SimilarPhotosService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const SIMILAR_LABEL = 'photo-encoder/openai/clip-vit-base-patch32';

beforeEach(function (): void {
    config([
        'ai.features.embeddings-photos.provider' => 'photo-encoder',
        'ai.features.embeddings-photos.model' => null,
        'ai.providers.photo-encoder.url' => 'http://encoder.test:7997',
        'ai.similar_photos.min_score' => 0.80,
        'ai.similar_photos.near_duplicate_cutoff' => 0.98,
    ]);
});

/**
 * A unit vector at the given cosine similarity to the query vector [1, 0, 0, ...].
 *
 * @return list<float>
 */
function vectorAtScore(float $score): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[0] = $score;
    $vector[1] = sqrt(1 - $score * $score);

    return $vector;
}

/**
 * @param  list<float>|null  $vector
 */
function similarPhoto(?array $vector, string $label = SIMILAR_LABEL, ?Listing $listing = null): ListingPhoto
{
    $photo = ListingPhoto::factory()->create(['listing_id' => ($listing ?? Listing::factory()->create())->id]);

    if ($vector !== null) {
        app(ListingPhotoRepositoryInterface::class)->storeEmbeddings([new PhotoEmbedding($photo->id, $vector)], $label, CarbonImmutable::now());
    }

    return $photo;
}

/**
 * @return list<int>
 */
function similarIds(int $photoId): array
{
    return array_map(fn ($match): int => $match->photoId, app(SimilarPhotosService::class)->for($photoId)->matches);
}

it('AC-3 excludes every photo of the query listing', function (): void {
    $listing = Listing::factory()->create();
    $query = similarPhoto(vectorAtScore(1.0), listing: $listing);
    $sibling = similarPhoto(vectorAtScore(0.90), listing: $listing);
    $other = similarPhoto(vectorAtScore(0.90));

    $ids = similarIds($query->id);

    expect($ids)->toBe([$other->id])->and($ids)->not->toContain($sibling->id, $query->id);
});

it('AC-3 excludes the query listing even when the candidates leave out the query photo', function (): void {
    $sibling = new SimilarPhotoMatch(11, 100, '2020 Ford Focus', 'Ford', 'Focus', 'a.jpg', 0.90);
    $other = new SimilarPhotoMatch(21, 200, '2019 Audi A4', 'Audi', 'A4', 'b.jpg', 0.85);

    $this->app->bind(SimilarPhotosRepositoryInterface::class, fn (): SimilarPhotosRepositoryInterface => new class($sibling, $other) implements SimilarPhotosRepositoryInterface
    {
        public function __construct(private SimilarPhotoMatch $sibling, private SimilarPhotoMatch $other) {}

        public function embeddingModel(int $photoId): ?string
        {
            return SIMILAR_LABEL;
        }

        public function listingId(int $photoId): ?int
        {
            return 100;
        }

        public function nearest(int $photoId, string $model, int $limit, int $efSearch): array
        {
            return [$this->sibling, $this->other];
        }
    });

    $ids = array_map(fn ($match): int => $match->photoId, app(SimilarPhotosService::class)->for(10)->matches);

    expect($ids)->toBe([21]);
});

it('AC-3 drops a match at or above the near-duplicate cutoff', function (): void {
    $query = similarPhoto(vectorAtScore(1.0));
    similarPhoto(vectorAtScore(0.99));
    $kept = similarPhoto(vectorAtScore(0.95));

    expect(similarIds($query->id))->toBe([$kept->id]);
});

it('AC-3 drops a match below the minimum score', function (): void {
    $query = similarPhoto(vectorAtScore(1.0));
    similarPhoto(vectorAtScore(0.50));
    $kept = similarPhoto(vectorAtScore(0.85));

    expect(similarIds($query->id))->toBe([$kept->id]);
});

it('AC-3 returns at most 8 matches, best first, one photo per other listing', function (): void {
    $query = similarPhoto(vectorAtScore(1.0));
    $scores = [0.81, 0.97, 0.90, 0.85, 0.95, 0.92, 0.83, 0.88, 0.86, 0.94];
    $photos = [];

    foreach ($scores as $score) {
        $photos[(string) $score] = similarPhoto(vectorAtScore($score));
    }

    similarPhoto(vectorAtScore(0.96), listing: $photos['0.97']->listing);

    $result = app(SimilarPhotosService::class)->for($query->id);

    expect($result->matches)->toHaveCount(8)
        ->and(array_map(fn ($match): int => $match->photoId, $result->matches))->toBe(array_map(
            fn (string $score): int => $photos[$score]->id,
            ['0.97', '0.95', '0.94', '0.92', '0.9', '0.88', '0.86', '0.85'],
        ))
        ->and($result->matches[0]->similarity)->toEqualWithDelta(0.97, 0.0001)
        ->and($result->reason)->toBeNull();
});

it('AC-3 reports a reason and no matches when nothing qualifies', function (): void {
    $query = similarPhoto(vectorAtScore(1.0));
    similarPhoto(vectorAtScore(0.40));

    $result = app(SimilarPhotosService::class)->for($query->id);

    expect($result->matches)->toBe([])->and($result->reason)->toBe('no photo of another listing is similar enough');
});

it('AC-3 reports "not embedded" for a photo without a vector or an unknown photo, and never errors', function (): void {
    $plain = similarPhoto(null);

    expect(app(SimilarPhotosService::class)->for($plain->id)->reason)->toBe('not embedded')
        ->and(app(SimilarPhotosService::class)->for(999_999)->reason)->toBe('not embedded');
});

it('AC-7 reports the index was built with another model when the label differs, and ignores that vector', function (): void {
    $query = similarPhoto(vectorAtScore(1.0), 'photo-encoder/laion/CLIP-ViT-B-32-laion2B-s34B-b79K');
    similarPhoto(vectorAtScore(0.9));

    $result = app(SimilarPhotosService::class)->for($query->id);

    expect($result->matches)->toBe([])
        ->and($result->reason)->toBe('index built with another model; run photos:embed --fresh');
});

it('AC-7 does not return photos embedded with another label', function (): void {
    $query = similarPhoto(vectorAtScore(1.0));
    similarPhoto(vectorAtScore(0.9), 'photo-encoder/laion/CLIP-ViT-B-32-laion2B-s34B-b79K');
    $kept = similarPhoto(vectorAtScore(0.88));

    expect(similarIds($query->id))->toBe([$kept->id]);
});

it('AC-4 serves the second identical request from the cache, querying only the photo\'s model label', function (): void {
    $query = similarPhoto(vectorAtScore(1.0));
    $match = similarPhoto(vectorAtScore(0.9));
    $service = app(SimilarPhotosService::class);

    $first = $service->for($query->id);

    DB::enableQueryLog();
    $second = $service->for($query->id);

    expect($first->cached)->toBeFalse()
        ->and($second->cached)->toBeTrue()
        ->and(DB::getQueryLog())->toHaveCount(1)
        ->and(DB::getQueryLog()[0]['query'])->toContain('select "embedding_model" from "listing_photos"')
        ->and($second->matches)->toEqual($first->matches)
        ->and($second->matches[0]->photoId)->toBe($match->id);
});

it('AC-4 stores plain arrays in the cache', function (): void {
    $query = similarPhoto(vectorAtScore(1.0));
    similarPhoto(vectorAtScore(0.9));

    app(SimilarPhotosService::class)->for($query->id);

    $payload = Cache::get(app(SimilarPhotosService::class)->cacheKey($query->id));

    expect($payload)->toBeArray()->and($payload['matches'][0])->toBeArray();
});

it('AC-4 invalidates the cache when the vectors change', function (): void {
    $query = similarPhoto(vectorAtScore(1.0));
    $service = app(SimilarPhotosService::class);

    expect($service->for($query->id)->matches)->toBe([]);

    $late = similarPhoto(vectorAtScore(0.9));
    expect($service->for($query->id)->cached)->toBeTrue();

    $service->invalidate();
    $fresh = $service->for($query->id);

    expect($fresh->cached)->toBeFalse()->and($fresh->matches[0]->photoId)->toBe($late->id);
});

it('AC-4 invalidates the cache when photos:embed stores vectors', function (): void {
    Storage::fake('public');
    Http::fake(['encoder.test:7997/embeddings' => fn (Request $request) => Http::response(['data' => array_map(
        fn (int $index): array => ['index' => $index, 'embedding' => array_map(fn (int $i): float => ($i % 5) + 1.0, range(1, 512))],
        array_keys($request['input']),
    )])]);

    $query = similarPhoto(vectorAtScore(1.0));
    $service = app(SimilarPhotosService::class);
    $service->for($query->id);
    expect($service->for($query->id)->cached)->toBeTrue();

    $new = similarPhoto(null);
    Storage::disk('public')->put($new->path, 'bytes');

    $this->artisan('photos:embed')->assertSuccessful();

    expect($service->for($query->id)->cached)->toBeFalse();
});

it('AC-4 invalidates the cache when photos:embed --fresh clears the vectors', function (): void {
    Storage::fake('public');
    Http::fake(['encoder.test:7997/embeddings' => fn (Request $request) => Http::response(['data' => array_map(
        fn (int $index): array => ['index' => $index, 'embedding' => array_map(fn (int $i): float => ($i % 5) + 1.0, range(1, 512))],
        array_keys($request['input']),
    )])]);

    $query = similarPhoto(vectorAtScore(1.0));
    Storage::disk('public')->put($query->path, 'bytes');
    $service = app(SimilarPhotosService::class);
    $service->for($query->id);

    $this->artisan('photos:embed --fresh')->assertSuccessful();

    expect($service->for($query->id)->cached)->toBeFalse();
});

it('AC-4 AC-7 never serves a result cached under a label the photo is no longer embedded with', function (): void {
    $query = similarPhoto(vectorAtScore(1.0));
    similarPhoto(vectorAtScore(0.9));
    $service = app(SimilarPhotosService::class);

    expect($service->for($query->id)->matches)->not->toBeEmpty();

    // The photos are re-embedded under another model; the setting then switches back to the first label.
    DB::table('listing_photos')->update(['embedding_model' => 'photo-encoder/some-other-model']);

    $again = $service->for($query->id);

    expect($again->matches)->toBe([])
        ->and($again->reason)->toBe(SimilarPhotosService::REASON_OTHER_MODEL)
        ->and($again->cached)->toBeFalse();
});

it('AC-4 a changed minimum score is not masked by results cached under the old one', function (): void {
    $query = similarPhoto(vectorAtScore(1.0));
    similarPhoto(vectorAtScore(0.9));
    $service = app(SimilarPhotosService::class);
    $before = $service->cacheKey($query->id);

    config(['ai.similar_photos.min_score' => 0.95]);

    expect($service->cacheKey($query->id))->not->toBe($before)
        ->and($service->for($query->id)->matches)->toBe([]);
});
