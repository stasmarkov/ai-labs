<?php

declare(strict_types=1);

use App\Models\Dealer;
use App\Models\Listing;

function makeListing(array $attributes = []): Listing
{
    return Listing::factory()->for(Dealer::factory())->create($attributes);
}

it('BR-03 returns the success envelope with pagination meta', function (): void {
    makeListing();

    $this->getJson('/listings')
        ->assertOk()
        ->assertJsonStructure([
            'success', 'status', 'message',
            'data' => [['id', 'make', 'model', 'year', 'fuel', 'mileage_km', 'price_cents', 'currency', 'city', 'listed_at', 'dealer_id']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ])
        ->assertJsonPath('success', true)
        ->assertJsonPath('status', 200)
        ->assertJsonPath('message', 'Listings retrieved')
        ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1]);
});

it('BR-03 filters by each field alone', function (string $query, array $matching): void {
    makeListing(['make' => 'BMW', 'model' => 'X3', 'fuel' => 'diesel', 'city' => 'Berlin', 'year' => 2019, 'price_cents' => 2_500_000]);
    makeListing(['make' => 'Audi', 'model' => 'A4', 'fuel' => 'petrol', 'city' => 'Munich', 'year' => 2015, 'price_cents' => 3_500_000]);

    $response = $this->getJson('/listings?'.$query)->assertOk();

    expect(collect($response->json('data'))->pluck('make')->all())->toBe($matching);
})->with([
    'make case-insensitive' => ['make=bmw', ['BMW']],
    'model case-insensitive' => ['model=a4', ['Audi']],
    'fuel' => ['fuel=petrol', ['Audi']],
    'city case-insensitive' => ['city=BERLIN', ['BMW']],
    'min_year' => ['min_year=2018', ['BMW']],
    'max_price in whole EUR' => ['max_price=30000', ['BMW']],
]);

it('BR-03 make is an exact match, not a prefix', function (): void {
    makeListing(['make' => 'BMW']);

    $this->getJson('/listings?make=BM')->assertOk()->assertJsonPath('meta.total', 0);
});

it('BR-03 max_price includes the boundary and excludes anything above', function (): void {
    makeListing(['price_cents' => 3_000_000]);
    makeListing(['price_cents' => 3_000_001]);

    $this->getJson('/listings?max_price=30000')->assertOk()->assertJsonPath('meta.total', 1);
});

it('BR-03 combines filters with AND', function (): void {
    makeListing(['make' => 'BMW', 'fuel' => 'diesel', 'year' => 2019, 'price_cents' => 2_500_000]);
    makeListing(['make' => 'BMW', 'fuel' => 'petrol', 'year' => 2019, 'price_cents' => 2_500_000]);
    makeListing(['make' => 'BMW', 'fuel' => 'diesel', 'year' => 2017, 'price_cents' => 2_500_000]);
    makeListing(['make' => 'BMW', 'fuel' => 'diesel', 'year' => 2019, 'price_cents' => 4_000_000]);
    makeListing(['make' => 'Audi', 'fuel' => 'diesel', 'year' => 2019, 'price_cents' => 2_500_000]);

    $this->getJson('/listings?make=BMW&fuel=diesel&min_year=2018&max_price=30000&per_page=20')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.make', 'BMW')
        ->assertJsonPath('data.0.fuel', 'diesel');
});

it('BR-03 defaults per_page to 20 and paginates', function (): void {
    Dealer::factory()->has(Listing::factory()->count(25))->create();

    $this->getJson('/listings')
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.last_page', 2);

    $this->getJson('/listings?page=2')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.current_page', 2);
});

it('BR-03 accepts the per_page bounds', function (int $perPage): void {
    $this->getJson('/listings?per_page='.$perPage)->assertOk()->assertJsonPath('meta.per_page', $perPage);
})->with([1, 50]);

it('BR-03 orders newest first with id as the tie-breaker', function (): void {
    $old = makeListing(['listed_at' => '2026-01-01 10:00:00']);
    $tieA = makeListing(['listed_at' => '2026-03-01 10:00:00']);
    $tieB = makeListing(['listed_at' => '2026-03-01 10:00:00']);

    $ids = collect($this->getJson('/listings')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$tieB->id, $tieA->id, $old->id]);
});

it('BR-03 rejects invalid values with 422 and the error envelope', function (string $query, string $field): void {
    $this->getJson('/listings?'.$query)
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('status', 422)
        ->assertJsonStructure(['success', 'status', 'message', 'errors' => [$field]]);

    $this->get('/listings?'.$query)
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'bad fuel' => ['fuel=kerosene', 'fuel'],
    'min_year too low' => ['min_year=1979', 'min_year'],
    'min_year too high' => ['min_year=9999', 'min_year'],
    'max_price zero' => ['max_price=0', 'max_price'],
    'per_page zero' => ['per_page=0', 'per_page'],
    'per_page 51' => ['per_page=51', 'per_page'],
    'per_page text' => ['per_page=abc', 'per_page'],
    'page zero' => ['page=0', 'page'],
]);

it('BR-03 min_year accepts 1980 and next year', function (): void {
    $this->getJson('/listings?min_year=1980')->assertOk();
    $this->getJson('/listings?min_year='.(now()->year + 1))->assertOk();
    $this->getJson('/listings?min_year='.(now()->year + 2))->assertStatus(422);
});
