<?php

declare(strict_types=1);

use App\Models\AiRun;
use App\Models\AiToolStep;
use App\Models\Dealer;
use App\Models\Listing;
use App\Models\ListingTag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function plainListing(): Listing
{
    $dealer = Dealer::factory()->create(['name' => 'Autohaus Nord', 'city' => 'Hamburg']);

    return Listing::factory()->for($dealer)->create([
        'id' => 12,
        'make' => 'Volvo',
        'model' => 'V60',
        'fuel' => 'diesel',
        'year' => 2020,
        'price_cents' => 2_000_000,
        'mileage_km' => 1000,
        'city' => 'Hamburg',
        'listed_at' => '2026-01-01 00:00:00+00',
    ]);
}

function normalisedListingPage(Listing $listing, mixed $test): string
{
    $body = $test->get('/listings/'.$listing->id)->assertOk()->getContent();

    $body = (string) preg_replace('/data-price-rendered-ms="\d+"/', 'data-price-rendered-ms="0"', $body);

    return str_replace(rtrim(asset(''), '/'), 'ASSET', $body);
}

function newToolRun(): AiRun
{
    return AiRun::create([
        'invocation_id' => (string) fake()->uuid(),
        'agent' => 'AdminAssistantLoop',
        'provider' => 'ollama',
        'model' => 'm',
        'latency_ms' => 5,
        'status' => 'completed',
    ]);
}

function insertToolStep(AiRun $run, int $position, string $outcome, ?string $error): void
{
    DB::table('ai_tool_steps')->insert([
        'ai_run_id' => $run->id,
        'position' => $position,
        'tool' => 'searchListings',
        'arguments' => '{}',
        'outcome' => $outcome,
        'error' => $error,
        'duration_ms' => 1,
    ]);
}

it('MNC-2 a listing without title and tags renders exactly as before', function (): void {
    $this->withoutVite();
    $listing = plainListing();

    $body = normalisedListingPage($listing, $this);

    expect($body)->toBe(file_get_contents(base_path('tests/Fixtures/listing-show-plain.html')));
});

it('AC-10 a title replaces make and model in the heading and the page title', function (): void {
    $this->withoutVite();
    $listing = plainListing();
    $listing->update(['title' => 'One owner, full service history']);

    $this->get('/listings/'.$listing->id)
        ->assertOk()
        ->assertSee('<h1 class="text-3xl font-semibold">One owner, full service history</h1>', false)
        ->assertSee('<title>One owner, full service history', false);
});

it('AC-10 tags are shown as chips under the heading', function (): void {
    $this->withoutVite();
    $listing = plainListing();
    ListingTag::factory()->for($listing)->create(['tag' => 'family']);
    ListingTag::factory()->for($listing)->create(['tag' => 'low-mileage']);

    $this->get('/listings/'.$listing->id)
        ->assertOk()
        ->assertSee('<h1 class="text-3xl font-semibold">Volvo V60</h1>', false)
        ->assertSeeInOrder(['Volvo V60', 'family', 'low-mileage', 'listing-price'], false);
});

it('AC-10 escapes the title and the tags', function (): void {
    $this->withoutVite();
    $listing = plainListing();
    $listing->update(['title' => '<script>alert(1)</script>']);
    ListingTag::factory()->for($listing)->create(['tag' => '<b>']);

    $this->get('/listings/'.$listing->id)
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<b>', false);
});

it('MNC-3 the widened outcome check accepts the new outcomes and keeps the old ones', function (): void {
    $run = newToolRun();
    insertToolStep($run, 1, 'ok', null);
    insertToolStep($run, 2, 'error', 'bad_arguments');
    insertToolStep($run, 3, 'failed', 'timeout');
    insertToolStep($run, 4, 'pending', null);
    insertToolStep($run, 5, 'confirmed', null);
    insertToolStep($run, 6, 'rejected', 'expired');

    expect(AiToolStep::count())->toBe(6);
});

it('MNC-3 the old rules still hold: an ok step has no error, an error step has one, other outcomes are refused', function (string $outcome, ?string $error): void {
    $run = newToolRun();

    expect(fn () => DB::transaction(fn () => insertToolStep($run, 1, $outcome, $error)))->toThrow(QueryException::class);
})->with([
    'ok with error' => ['ok', 'boom'],
    'error without error' => ['error', null],
    'failed without error' => ['failed', null],
    'unknown outcome' => ['maybe', null],
]);

it('AC-10 a tag is unique per listing and goes with its listing', function (): void {
    $listing = plainListing();
    ListingTag::factory()->for($listing)->create(['tag' => 'family']);

    expect(fn () => DB::transaction(fn () => ListingTag::factory()->for($listing)->create(['tag' => 'family'])))->toThrow(QueryException::class);

    $listing->delete();

    expect(ListingTag::count())->toBe(0);
});

it('MNC-3 the migrations roll back and run again', function (): void {
    Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true]);

    expect(Schema::hasTable('listing_tags'))->toBeFalse()
        ->and(Schema::hasColumn('listings', 'title'))->toBeFalse();
    expect(fn () => DB::transaction(fn () => DB::statement("INSERT INTO ai_tool_steps (ai_run_id, position, tool, arguments, outcome, duration_ms) VALUES (1, 1, 't', '{}', 'pending', 1)")))
        ->toThrow(QueryException::class);

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasTable('listing_tags'))->toBeTrue()
        ->and(Schema::hasColumn('listings', 'title'))->toBeTrue();
});

it('MNC-3 the outcome rollback refuses to delete data', function (): void {
    $run = newToolRun();
    insertToolStep($run, 1, 'pending', null);

    expect(fn () => Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]))->toThrow(RuntimeException::class);
});
