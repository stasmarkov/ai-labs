<?php

declare(strict_types=1);

use App\Contracts\ListingPhotoRepositoryInterface;
use App\Contracts\SimilarPhotosRepositoryInterface;
use App\DTOs\PhotoEmbedding;
use App\Models\Dealer;
use App\Models\Listing;
use App\Models\ListingPhoto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

const PAGE_LABEL = 'photo-encoder/openai/clip-vit-base-patch32';

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config([
        'ai.features.embeddings-photos.provider' => 'photo-encoder',
        'ai.features.embeddings-photos.model' => null,
        'ai.providers.photo-encoder.url' => 'http://encoder.test:7997',
        'ai.similar_photos.min_score' => 0.80,
        'ai.similar_photos.near_duplicate_cutoff' => 0.98,
    ]);
});

/**
 * @return list<float>
 */
function pageVector(float $score): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[0] = $score;
    $vector[1] = sqrt(1 - $score * $score);

    return $vector;
}

function pagePhoto(?float $score, ?Listing $listing = null, array $attributes = []): ListingPhoto
{
    $photo = ListingPhoto::factory()->create(['listing_id' => ($listing ?? Listing::factory()->for(Dealer::factory())->create())->id, ...$attributes]);

    if ($score !== null) {
        app(ListingPhotoRepositoryInterface::class)->storeEmbeddings([new PhotoEmbedding($photo->id, pageVector($score))], PAGE_LABEL, CarbonImmutable::now());
    }

    return $photo;
}

it('AC-5 shows a similar cars block with the matching listings on the listing page', function (): void {
    $listing = Listing::factory()->for(Dealer::factory())->create();
    pagePhoto(1.0, $listing);
    $other = Listing::factory()->for(Dealer::factory())->create(['make' => 'Saab', 'model' => '9-3', 'year' => 2009]);
    $match = pagePhoto(0.90, $other, ['path' => 'photos/000001/a.jpg']);

    $this->get('/listings/'.$listing->id)
        ->assertOk()
        ->assertSee('Similar cars')
        ->assertSee('2009 Saab 9-3')
        ->assertSee('/listings/'.$other->id, false)
        ->assertSee('/storage/'.$match->path, false);
});

it('AC-5 leaves the similar cars markup out when nothing matches', function (): void {
    $listing = Listing::factory()->for(Dealer::factory())->create();
    pagePhoto(1.0, $listing);
    pagePhoto(0.50);

    $this->get('/listings/'.$listing->id)->assertOk()->assertDontSee('Similar cars')->assertDontSee('similar-cars');
});

it('MNC-1 renders a listing without a photo exactly as before', function (): void {
    $listing = Listing::factory()->for(Dealer::factory())->create();
    pagePhoto(0.90);

    $html = $this->get('/listings/'.$listing->id)->assertOk()->assertDontSee('Similar cars')->assertDontSee('similar-cars')->getContent();

    expect($html)->not->toContain('/storage/');
});

it('MNC-1 keeps the detail page at two queries for a listing without a photo', function (): void {
    $listing = Listing::factory()->for(Dealer::factory())->create();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get('/listings/'.$listing->id)->assertOk();

    expect($queries)->toBe(2);
});

it('NFR-3 never shows encoder errors and makes no encoder request when the page renders', function (): void {
    config(['ai.providers.photo-encoder.url' => 'http://unreachable.invalid:1']);
    $listing = Listing::factory()->for(Dealer::factory())->create();
    pagePhoto(1.0, $listing);

    $this->get('/listings/'.$listing->id)->assertOk()->assertDontSee('encoder')->assertDontSee('Exception');

    Http::assertNothingSent();
});

it('NFR-3 escapes listing text in the block', function (): void {
    $listing = Listing::factory()->for(Dealer::factory())->create();
    pagePhoto(1.0, $listing);
    $other = Listing::factory()->for(Dealer::factory())->create(['make' => '<script>x</script>', 'model' => 'M']);
    pagePhoto(0.90, $other);

    $this->get('/listings/'.$listing->id)->assertOk()->assertDontSee('<script>x</script>', false)->assertSee('&lt;script&gt;', false);
});

it('NFR-3 renders the listing without the block and reports the failure when the similar photos lookup throws', function (): void {
    Exceptions::fake();
    $listing = Listing::factory()->for(Dealer::factory())->create();
    pagePhoto(1.0, $listing);

    $this->app->bind(SimilarPhotosRepositoryInterface::class, fn (): SimilarPhotosRepositoryInterface => new class implements SimilarPhotosRepositoryInterface
    {
        public function embeddingModel(int $photoId): ?string
        {
            throw new RuntimeException('pgvector exploded');
        }

        public function listingId(int $photoId): ?int
        {
            throw new RuntimeException('pgvector exploded');
        }

        public function nearest(int $photoId, string $model, int $limit, int $efSearch): array
        {
            throw new RuntimeException('pgvector exploded');
        }
    });

    $this->get('/listings/'.$listing->id)
        ->assertOk()
        ->assertDontSee('Similar cars')
        ->assertDontSee('similar-cars')
        ->assertDontSee('pgvector exploded')
        ->assertDontSee('RuntimeException');

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'pgvector exploded');
});

it('MNC-1 a listing without matches keeps the blank line between the hero image and the title, byte for byte', function (): void {
    $listing = Listing::factory()->for(Dealer::factory())->create();

    $html = (string) $this->get('/listings/'.$listing->id)->assertOk()->getContent();

    // Blade drops the newline after @endif, so the template carries one extra; the parity snapshots hash this page.
    expect($html)->toContain("w-full rounded-lg\">\n\n    <h1 class=\"text-3xl font-semibold\">")
        ->and($html)->not->toContain('Similar cars');
});
