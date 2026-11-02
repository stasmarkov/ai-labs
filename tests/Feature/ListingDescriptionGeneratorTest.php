<?php

declare(strict_types=1);

use App\DTOs\ListingFacts;
use App\Models\Listing;
use App\Models\ListingDocument;
use App\Services\ListingDocumentGenerator;
use Database\Seeders\Catalog;
use Illuminate\Support\Facades\DB;

function factsFor(int $id, string $make = 'Volvo', string $model = 'V60', string $fuel = 'diesel'): ListingFacts
{
    return new ListingFacts(id: $id, year: 2019, make: $make, model: $model, fuel: $fuel, mileageKm: 42_000);
}

function listingsHash(): string
{
    return DB::scalar('SELECT md5(string_agg(listings::text, \',\' ORDER BY id)) FROM listings');
}

it('AC-1 the same listing always gets the same description', function (): void {
    $generator = new ListingDocumentGenerator;

    $first = $generator->generate(factsFor(7));

    expect($generator->generate(factsFor(7)))->toEqual($first)
        ->and($generator->generate(factsFor(8))->description)->not->toBe($first->description);
});

it('AC-1 gives different texts across many ids', function (): void {
    $generator = new ListingDocumentGenerator;
    $texts = [];

    foreach (range(1, 200) as $id) {
        $texts[] = $generator->generate(factsFor($id))->description;
    }

    expect(count(array_unique($texts)))->toBeGreaterThan(100);
});

it('AC-1 writes 25 to 80 words naming year, make, model, body type, fuel and mileage', function (): void {
    $generator = new ListingDocumentGenerator;

    foreach (Catalog::MODELS_BY_MAKE as $make => $models) {
        foreach ($models as $model) {
            foreach (['petrol', 'electric', 'lpg'] as $fuel) {
                $text = $generator->generate(factsFor(3, $make, $model, $fuel));
                $words = preg_split('/\s+/', $text->description);

                expect(count($words))->toBeGreaterThanOrEqual(25)->toBeLessThanOrEqual(80)
                    ->and($text->description)->toContain('2019', $make, $model, $text->bodyType, '42,000 km');
            }
        }
    }

    expect($generator->generate(factsFor(3, fuel: 'lpg'))->description)->toContain('LPG');
});

it('AC-1 knows a body type for every catalog model', function (): void {
    $bodyTypes = array_keys(Catalog::FEATURES_BY_BODY_TYPE);

    foreach (Catalog::MODELS_BY_MAKE as $models) {
        foreach ($models as $model) {
            expect(Catalog::BODY_TYPE_BY_MODEL)->toHaveKey($model)
                ->and($bodyTypes)->toContain(Catalog::BODY_TYPE_BY_MODEL[$model]);
        }
    }
});

it('AC-1 leaves the global mt_rand sequence untouched', function (): void {
    mt_srand(Catalog::SEED);
    $expected = [mt_rand(), mt_rand()];

    mt_srand(Catalog::SEED);
    (new ListingDocumentGenerator)->generate(factsFor(5));

    expect([mt_rand(), mt_rand()])->toBe($expected);
});

it('AC-1 listings:describe-all creates one row per listing and a second run changes nothing', function (): void {
    Listing::factory()->count(5)->create();

    $this->artisan('listings:describe-all')->assertSuccessful();

    $rows = DB::table('listing_documents')->orderBy('listing_id')->get();
    $updatedAt = $rows->pluck('updated_at')->all();

    expect($rows)->toHaveCount(5)
        ->and($rows->every(fn ($row) => $row->description !== '' && $row->embedding === null))->toBeTrue();

    $this->artisan('listings:describe-all')->expectsOutputToContain('changed: 0')->assertSuccessful();

    expect(DB::table('listing_documents')->orderBy('listing_id')->pluck('updated_at')->all())->toBe($updatedAt)
        ->and(ListingDocument::count())->toBe(5);
});

it('AC-1 a changed listing gets its description rewritten on the next run', function (): void {
    $listing = Listing::factory()->create(['year' => 2010]);
    $this->artisan('listings:describe-all')->assertSuccessful();
    $before = DB::table('listing_documents')->value('description');

    DB::table('listings')->where('id', $listing->id)->update(['year' => 2020]);
    $this->artisan('listings:describe-all')->assertSuccessful();

    expect(DB::table('listing_documents')->value('description'))->not->toBe($before)->toContain('2020');
});

it('MNC-1 the listings table hash is identical before and after describe-all', function (): void {
    Listing::factory()->count(20)->create();
    $before = listingsHash();

    $this->artisan('listings:describe-all')->assertSuccessful();

    expect(listingsHash())->toBe($before);
});

it('MNC-1 the listings table keeps its columns', function (): void {
    expect(DB::getSchemaBuilder()->getColumnListing('listings'))->toBe([
        'id', 'dealer_id', 'make', 'model', 'fuel', 'year', 'price_cents', 'currency',
        'mileage_km', 'city', 'listed_at', 'created_at', 'updated_at',
    ]);
});

it('AC-1 listing_documents has an HNSW cosine index on the embedding', function (): void {
    $definition = DB::scalar('SELECT indexdef FROM pg_indexes WHERE indexname = ?', [ListingDocument::EMBEDDING_INDEX]);

    expect($definition)->toContain('hnsw')->toContain('vector_cosine_ops');
});
