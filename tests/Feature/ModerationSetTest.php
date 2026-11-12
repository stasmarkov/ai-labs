<?php

declare(strict_types=1);

use App\DTOs\ListingFacts;
use App\Models\DocsChunk;
use App\Models\Listing;
use App\Models\ListingDocument;
use App\Services\ListingDocumentGenerator;
use App\Services\ModerationSetBuilder;
use App\Services\PromptInjectionGuard;
use Database\Seeders\Catalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->output = storage_path('framework/testing/moderation-set-'.uniqid().'.jsonl');
});

afterEach(function (): void {
    File::delete($this->output);
});

function moderationSetItems(string $path): array
{
    return array_map(
        fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", File::get($path)))),
    );
}

it('AC-5 writes the same 100 items for the same seed', function (): void {
    $other = $this->output.'.second';

    $this->artisan('moderation:make-set', ['--seed' => 11, '--output' => $this->output])->assertSuccessful();
    $this->artisan('moderation:make-set', ['--seed' => 11, '--output' => $other])->assertSuccessful();

    expect(File::get($this->output))->toBe(File::get($other))
        ->and(moderationSetItems($this->output))->toHaveCount(100);

    File::delete($other);
});

it('AC-5 changes the generated items with another seed and keeps the committed ones', function (): void {
    $other = $this->output.'.second';

    $this->artisan('moderation:make-set', ['--seed' => 11, '--output' => $this->output])->assertSuccessful();
    $this->artisan('moderation:make-set', ['--seed' => 12, '--output' => $other])->assertSuccessful();

    $first = collect(moderationSetItems($this->output));
    $second = collect(moderationSetItems($other));

    expect($first->pluck('description')->all())->not->toBe($second->pluck('description')->all())
        ->and($first->slice(35, 15)->pluck('description')->all())->toBe($second->slice(35, 15)->pluck('description')->all());

    File::delete($other);
});

it('AC-5 matches the committed listings.v1.jsonl for the default seed', function (): void {
    $this->artisan('moderation:make-set', ['--output' => $this->output])->assertSuccessful();

    expect(File::get($this->output))->toBe(File::get(base_path('bench/moderation/listings.v1.jsonl')));
});

it('AC-5 has the documented counts per label and reason', function (): void {
    $this->artisan('moderation:make-set', ['--output' => $this->output])->assertSuccessful();
    $items = collect(moderationSetItems($this->output));

    $keys = ['id', 'title', 'make', 'model', 'year', 'price_eur', 'mileage_km', 'city', 'description', 'label', 'kind', 'reasons'];
    $single = fn (string $reason) => $items->filter(fn (array $item): bool => $item['reasons'] === [$reason])->count();
    $withReason = fn (string $reason) => $items->filter(fn (array $item): bool => in_array($reason, $item['reasons'], true))->count();

    expect($items->every(fn (array $item): bool => array_keys($item) === $keys))->toBeTrue()
        ->and($items->pluck('id')->unique())->toHaveCount(100)
        ->and($items->where('label', 'safe'))->toHaveCount(50)
        ->and($items->where('label', 'risky'))->toHaveCount(50)
        ->and($items->where('label', 'safe')->every(fn (array $item): bool => $item['reasons'] === []))->toBeTrue()
        ->and($items->where('kind', 'safe'))->toHaveCount(35)
        ->and($items->where('kind', 'hard_negative'))->toHaveCount(15)
        ->and($items->where('kind', 'hard_negative')->pluck('label')->unique()->all())->toBe(['safe'])
        ->and($items->where('kind', 'risky'))->toHaveCount(40)
        ->and($items->where('kind', 'mixed'))->toHaveCount(10)
        ->and($single('advance_payment'))->toBe(10)
        ->and($single('off_platform_contact'))->toBe(10)
        ->and($single('price_far_below_market'))->toBe(10)
        ->and($single('reported_vin'))->toBe(10)
        ->and($items->filter(fn (array $item): bool => count($item['reasons']) === 2)->count())->toBe(10)
        ->and($withReason('other'))->toBe(5)
        ->and($items->where('label', 'risky')->every(fn (array $item): bool => $item['reasons'] !== []))->toBeTrue();
});

it('AC-5 builds risky items from the signals the facts will compute', function (): void {
    $this->artisan('moderation:make-set', ['--output' => $this->output])->assertSuccessful();
    $items = collect(moderationSetItems($this->output));
    $reference = collect(json_decode(File::get(base_path('bench/moderation/price-reference.json')), true, 512, JSON_THROW_ON_ERROR)['medians']);
    $reported = require base_path('resources/moderation/reported-vins.php');
    $guard = app(PromptInjectionGuard::class);

    $median = fn (array $item): float => (float) $reference->first(
        fn (array $row): bool => $row['make'] === $item['make'] && $row['model'] === $item['model'] && $row['year'] === $item['year'],
    )['median_eur'];

    $belowMarket = $items->filter(fn (array $item): bool => in_array('price_far_below_market', $item['reasons'], true));
    $vinItems = $items->filter(fn (array $item): bool => in_array('reported_vin', $item['reasons'], true));
    $injected = $items->filter(fn (array $item): bool => in_array('other', $item['reasons'], true));

    expect($belowMarket)->toHaveCount(13)
        ->and($belowMarket->every(fn (array $item): bool => $item['price_eur'] <= 0.5 * $median($item)))->toBeTrue()
        ->and($items->where('label', 'safe')->every(fn (array $item): bool => $item['price_eur'] >= 0.5 * $median($item)))->toBeTrue()
        ->and($vinItems)->toHaveCount(14)
        ->and($vinItems->every(fn (array $item): bool => collect($reported)->contains(fn (string $vin): bool => str_contains($item['description'], $vin))))->toBeTrue()
        ->and($injected->every(fn (array $item): bool => $guard->match($item['description']) !== null))->toBeTrue()
        ->and($items->reject(fn (array $item): bool => in_array('other', $item['reasons'], true))->every(fn (array $item): bool => $guard->match($item['description']) === null))->toBeTrue();
});

it('AC-5 keeps every item text out of the indexed corpus', function (): void {
    $this->artisan('moderation:make-set', ['--output' => $this->output])->assertSuccessful();
    $items = moderationSetItems($this->output);

    $listing = Listing::factory()->create();
    ListingDocument::query()->create([
        'listing_id' => $listing->id,
        'body_type' => 'hatchback',
        'description' => app(ListingDocumentGenerator::class)->generate(new ListingFacts($listing->id, $listing->year, $listing->make, $listing->model, $listing->fuel->value, $listing->mileage_km))->description,
    ]);
    $documentId = DB::table('docs_documents')->insertGetId([
        'source' => 'laravel', 'path' => 'queues.md', 'title' => 'Queues', 'content_hash' => str_repeat('b', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DocsChunk::query()->create([
        'document_id' => $documentId, 'position' => 0, 'heading' => 'h', 'body' => 'Queues and jobs.', 'content' => 'Queues and jobs.',
        'token_estimate' => 3, 'oversize' => false, 'content_hash' => str_repeat('a', 64),
    ]);

    $stored = ListingDocument::query()->pluck('description')
        ->merge(DocsChunk::query()->pluck('body'))
        ->merge(DocsChunk::query()->pluck('content'))
        ->map(fn (string $text): string => mb_strtolower($text));

    foreach ($items as $item) {
        $text = mb_strtolower($item['description']);
        expect($stored->contains(fn (string $row): bool => str_contains($row, $text)))->toBeFalse();
    }

    expect(ModerationSetBuilder::GENERATOR_ID_BASE)->toBeGreaterThan(50_000)
        ->and(ModerationSetBuilder::GENERATOR_ID_BASE)->not->toBe(Catalog::SEED);
});

it('AC-5 refreshes the price reference from the listings through the repository', function (): void {
    foreach ([10000, 20000, 30000, 40000, 50000] as $price) {
        Listing::factory()->create(['make' => 'Volkswagen', 'model' => 'Golf', 'year' => 2018, 'price_cents' => $price * 100]);
    }
    $path = storage_path('framework/testing/price-reference-'.uniqid().'.json');

    $this->artisan('moderation:make-set', ['--refresh-prices' => true, '--price-reference' => $path])->assertSuccessful();

    $medians = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR)['medians'];
    File::delete($path);

    expect($medians)->toHaveCount(3)
        ->and(collect($medians)->firstWhere('year', 2018))->toEqual(['make' => 'Volkswagen', 'model' => 'Golf', 'year' => 2018, 'median_eur' => 30000.0]);
});
