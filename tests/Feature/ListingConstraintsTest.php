<?php

declare(strict_types=1);

use App\Models\Dealer;
use App\Models\Listing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('BR-10 rejects a listing without a dealer', function (): void {
    expect(fn () => Listing::factory()->create(['dealer_id' => null]))->toThrow(QueryException::class);
});

it('BR-10 rejects a listing pointing at a missing dealer', function (): void {
    expect(fn () => Listing::factory()->create(['dealer_id' => 123456]))->toThrow(QueryException::class);
});

it('BR-10 refuses to delete a dealer that has listings', function (): void {
    $dealer = Dealer::factory()->has(Listing::factory())->create();

    expect(fn () => $dealer->delete())->toThrow(QueryException::class);
});

it('BR-10 belongs a listing to exactly one dealer', function (): void {
    $listing = Listing::factory()->for(Dealer::factory())->create();

    expect($listing->dealer)->toBeInstanceOf(Dealer::class);
});

it('BR-11 rejects invalid listing values at the database', function (array $attributes): void {
    expect(fn () => Listing::factory()->for(Dealer::factory())->create($attributes))->toThrow(QueryException::class);
})->with([
    'price zero' => [['price_cents' => 0]],
    'price negative' => [['price_cents' => -100]],
    'year 1979' => [['year' => 1979]],
]);

it('BR-11 never generates a year outside 1980 and next year', function (): void {
    $years = Listing::factory()->count(200)->make()->pluck('year');

    expect($years->min())->toBeGreaterThanOrEqual(1980)
        ->and($years->max())->toBeLessThanOrEqual(now()->year + 1);
});

it('BR-11 accepts valid boundary values', function (): void {
    $dealer = Dealer::factory()->create();

    Listing::factory()->for($dealer)->create(['year' => 1980, 'price_cents' => 1, 'fuel' => 'lpg']);

    expect(Listing::count())->toBe(1);
});

it('BR-11 rejects a fuel outside the enum at the database', function (): void {
    $dealer = Dealer::factory()->create();

    expect(fn () => DB::table('listings')->insert([
        'dealer_id' => $dealer->id,
        'make' => 'Audi',
        'model' => 'A4',
        'fuel' => 'steam',
        'year' => 2020,
        'price_cents' => 100,
        'mileage_km' => 0,
        'city' => 'Berlin',
        'listed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
