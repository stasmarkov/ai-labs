<?php

declare(strict_types=1);

use App\Models\Dealer;
use App\Models\Listing;
use Illuminate\Support\Facades\DB;

it('renders the detail page with dealer data and a deterministic hero image', function (): void {
    $this->withoutVite();
    $dealer = Dealer::factory()->create(['name' => 'Autohaus Nord', 'city' => 'Hamburg']);
    $listing = Listing::factory()->for($dealer)->create(['make' => 'Volvo', 'model' => 'V60']);

    $this->get('/listings/'.$listing->id)
        ->assertOk()
        ->assertSee('Volvo')
        ->assertSee('V60')
        ->assertSee('Autohaus Nord')
        ->assertSee('/images/heroes/hero-'.($listing->id % 6).'.webp');
});

it('loads the listing and its dealer in two queries', function (): void {
    $this->withoutVite();
    $listing = Listing::factory()->for(Dealer::factory())->create();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get('/listings/'.$listing->id)->assertOk();

    expect($queries)->toBe(2);
});

it('BR-04 answers 404 for unknown, non-numeric and overflowing ids', function (string $id): void {
    $this->get('/listings/'.$id)->assertNotFound();
    $this->getJson('/listings/'.$id)->assertNotFound()->assertJsonPath('success', false)->assertJsonPath('status', 404);
})->with([
    'unknown' => '999999',
    'non numeric' => 'abc',
    'overflowing' => '99999999999999999999999',
    'zero' => '0',
    'negative' => '-1',
]);
