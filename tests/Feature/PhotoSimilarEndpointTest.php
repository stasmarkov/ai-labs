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
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

const ENDPOINT_LABEL = 'photo-encoder/openai/clip-vit-base-patch32';

beforeEach(function (): void {
    Http::preventStrayRequests();
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
 * @return list<float>
 */
function endpointVector(float $score): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[0] = $score;
    $vector[1] = sqrt(1 - $score * $score);

    return $vector;
}

/**
 * @param  list<float>|null  $caption
 */
function endpointPhoto(float $image, ?array $caption, string $make = 'Ford', string $model = 'Fiesta'): ListingPhoto
{
    $listing = Listing::factory()->create(['make' => $make, 'model' => $model]);
    $photo = ListingPhoto::factory()->for($listing)->create(['path' => "photos/{$listing->id}/x.jpg"]);
    $repository = app(ListingPhotoRepositoryInterface::class);
    $repository->storeEmbeddings([new PhotoEmbedding($photo->id, endpointVector($image))], ENDPOINT_LABEL, CarbonImmutable::now());

    if ($caption !== null) {
        $repository->storeTextEmbeddings([new PhotoTextEmbedding($photo->id, $caption, 'hash')], ENDPOINT_LABEL, CarbonImmutable::now());
    }

    return $photo;
}

it('AC-4 returns the hybrid matches with the parts, the flags and the caption coverage', function (): void {
    $query = endpointPhoto(1.0, endpointVector(1.0));
    $captionFirst = endpointPhoto(0.85, endpointVector(0.95), 'Ford', 'Focus');
    $imageOnly = endpointPhoto(0.90, null);

    $response = $this->getJson("/ai/photos/{$query->id}/similar?w=0.5")->assertOk();

    $response
        ->assertJsonPath('w', 0.5)
        ->assertJsonPath('label', ENDPOINT_LABEL)
        ->assertJsonPath('matches.0.photo_id', $captionFirst->id)
        ->assertJsonPath('matches.0.same_make_model', false)
        ->assertJsonPath('matches.0.parts.image_only', false)
        ->assertJsonPath('matches.1.photo_id', $imageOnly->id)
        ->assertJsonPath('matches.1.parts.image_only', true)
        ->assertJsonPath('matches.1.parts.caption', null)
        ->assertJsonPath('coverage.captioned', 1)
        ->assertJsonPath('coverage.total', 2);

    expect($response->json('matches.0.parts.image'))->toEqualWithDelta(0.85, 0.001)
        ->and($response->json('matches.0.parts.caption'))->toEqualWithDelta(0.95, 0.001)
        ->and($response->json('matches.0.similarity'))->toEqualWithDelta(0.90, 0.001);
});

it('AC-4 with w 1 returns the image-only strip with no parts', function (): void {
    $query = endpointPhoto(1.0, endpointVector(1.0));
    endpointPhoto(0.90, endpointVector(0.50));

    foreach (['?w=1', '?w=1.0', '?w=0.96'] as $suffix) {
        Cache::flush();
        $this->getJson("/ai/photos/{$query->id}/similar{$suffix}")
            ->assertOk()
            ->assertJsonPath('w', null)
            ->assertJsonPath('coverage', null)
            ->assertJsonMissingPath('matches.0.parts');
    }
});

it('AC-4 a missing w is rejected with 422', function (): void {
    $query = endpointPhoto(1.0, null);

    $this->getJson("/ai/photos/{$query->id}/similar")
        ->assertStatus(422)
        ->assertJsonValidationErrors('w');
});

it('AC-4 rounds w to one decimal and shares the cache entry with the rounded value', function (): void {
    $query = endpointPhoto(1.0, endpointVector(1.0));
    endpointPhoto(0.90, endpointVector(0.50));

    $this->getJson("/ai/photos/{$query->id}/similar?w=0.7")->assertOk()->assertJsonPath('cached', false);
    $this->getJson("/ai/photos/{$query->id}/similar?w=0.73")
        ->assertOk()
        ->assertJsonPath('w', 0.7)
        ->assertJsonPath('cached', true);

    $service = app(SimilarPhotosService::class);
    expect($service->cacheKey($query->id, 0.73))->toBe($service->cacheKey($query->id, 0.7));
});

it('AC-4 reports whether the query photo has a caption', function (): void {
    $captioned = endpointPhoto(1.0, endpointVector(1.0));
    $bare = endpointPhoto(1.0, null);
    endpointPhoto(0.90, endpointVector(0.50));

    $this->getJson("/ai/photos/{$captioned->id}/similar?w=0.5")->assertOk()->assertJsonPath('query_has_caption', true);
    $this->getJson("/ai/photos/{$bare->id}/similar?w=0.5")->assertOk()->assertJsonPath('query_has_caption', false);
});

it('AC-4 counts the candidate pool and the candidates with a caption vector', function (): void {
    $query = endpointPhoto(1.0, endpointVector(1.0));
    endpointPhoto(0.90, endpointVector(0.50));
    endpointPhoto(0.85, endpointVector(0.60));
    endpointPhoto(0.80, null);
    endpointPhoto(0.30, null);

    $this->getJson("/ai/photos/{$query->id}/similar?w=0.5")
        ->assertOk()
        ->assertJsonPath('candidates', 4)
        ->assertJsonPath('candidates_with_caption', 2);

    $this->getJson("/ai/photos/{$query->id}/similar?w=0.5")->assertJsonPath('cached', true)->assertJsonPath('candidates', 4);
});

it('AC-4 reports the hybrid minimum score on a hybrid answer and the image one otherwise', function (): void {
    config(['ai.similar_photos.min_score' => 0.60, 'ai.similar_photos.hybrid_min_score' => 0.55, 'ai.similar_photos.hybrid_min_scores' => []]);
    $query = endpointPhoto(1.0, endpointVector(1.0));
    endpointPhoto(0.90, endpointVector(0.50));

    $this->getJson("/ai/photos/{$query->id}/similar?w=0.5")->assertOk()->assertJsonPath('min_score', 0.55);
    $this->getJson("/ai/photos/{$query->id}/similar?w=1")->assertOk()->assertJsonPath('min_score', 0.6);
});

it('AC-4 rejects a weight outside 0 to 1 or not a number with 422 and a field error', function (string $w): void {
    $query = endpointPhoto(1.0, null);

    $this->getJson("/ai/photos/{$query->id}/similar?w={$w}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('w');
})->with(['-0.1', '1.5', 'abc']);

it('AC-4 answers 404 for an unknown photo', function (): void {
    $this->getJson('/ai/photos/999999/similar?w=0.5')->assertNotFound();
});

it('AC-4 is throttled', function (): void {
    $query = endpointPhoto(1.0, null);
    $limit = (int) config('ai.similar_photos.per_minute');

    for ($i = 0; $i < $limit; $i++) {
        $this->getJson("/ai/photos/{$query->id}/similar?w=0.5")->assertOk();
    }

    $this->getJson("/ai/photos/{$query->id}/similar?w=0.5")->assertStatus(429);
});

it('AC-4 the page hands the lab the similar url and the default weight', function (): void {
    $query = endpointPhoto(1.0, null);

    $this->get('/ai/photos?photo='.$query->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('similarUrl', fn (string $url) => str_contains($url, '/ai/photos/PHOTO_ID/similar'))
            ->where('hybridWeight', (float) config('ai.similar_photos.default_weight')));
});
