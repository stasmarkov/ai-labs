<?php

declare(strict_types=1);

use App\Models\Dealer;
use App\Models\Listing;
use Database\Seeders\DealerSeeder;
use Database\Seeders\ListingSeeder;
use Illuminate\Support\Facades\DB;

function seedChecksum(): string
{
    $rows = DB::table('listings')
        ->orderBy('id')
        ->get(['id', 'dealer_id', 'make', 'model', 'fuel', 'year', 'price_cents', 'city', 'listed_at']);

    return md5($rows->map(fn ($row) => implode('|', (array) $row))->implode("\n"));
}

function seedSmall(): void
{
    app(DealerSeeder::class)->run(10);
    app(ListingSeeder::class)->run(200);
}

it('BR-05 produces identical data on every run', function (): void {
    seedSmall();
    $first = seedChecksum();
    $firstIds = DB::table('listings')->orderBy('id')->pluck('id')->all();

    seedSmall();

    expect(seedChecksum())->toBe($first)
        ->and(DB::table('listings')->orderBy('id')->pluck('id')->all())->toBe($firstIds)
        ->and(Dealer::count())->toBe(10)
        ->and(Listing::count())->toBe(200);
});

it('BR-05 seeds listings in the city of their dealer', function (): void {
    seedSmall();

    $mismatched = DB::table('listings')
        ->join('dealers', 'dealers.id', '=', 'listings.dealer_id')
        ->whereColumn('dealers.city', '!=', 'listings.city')
        ->count();

    expect($mismatched)->toBe(0);
});

it('BR-05 seeds exactly 500 dealers and 50 000 listings', function (): void {
    $this->seed();

    expect(Dealer::count())->toBe(500)
        ->and(Listing::count())->toBe(50_000)
        ->and(DB::table('listings')->min('id'))->toBe(1)
        ->and(DB::table('listings')->max('id'))->toBe(50_000);
});
