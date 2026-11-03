<?php

declare(strict_types=1);

use App\Models\Dealer;
use App\Models\Listing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['app.debug_routes' => true]);
});

function slowListingId(): int
{
    return Listing::factory()->for(Dealer::factory())->create()->id;
}

function expectedVin(int $id): string
{
    return 'AP'.str_pad((string) $id, 15, '0', STR_PAD_LEFT);
}

test('AC-1 slow listing answers 200 with the vin report and waited_ms', function () {
    $id = slowListingId();
    $vin = expectedVin($id);
    Http::fake(['vinstub:3000/vin/*' => function () use ($vin) {
        usleep(300_000);

        return Http::response(['vin' => $vin, 'make_check' => 'ok', 'recalls' => 0, 'source' => 'vinstub']);
    }]);

    $response = $this->getJson("/listings/{$id}/slow")->assertOk();

    $response->assertJsonPath('listing_id', $id)
        ->assertJsonPath('vin', $vin)
        ->assertJsonPath('vin_report.source', 'vinstub');
    expect($response->json('waited_ms'))->toBeGreaterThanOrEqual(300)->toBeLessThan(2000);
    Http::assertSent(fn (Request $request): bool => $request->url() === "http://vinstub:3000/vin/{$vin}");
});

test('AC-1 slow listing answers 404 json before any stub call', function () {
    Http::fake();

    $this->get('/listings/999999/slow')
        ->assertNotFound()
        ->assertJson(['success' => false, 'status' => 404]);

    Http::assertNothingSent();
});

test('AC-1 slow listing answers 503 json when the stub fails', function () {
    $id = slowListingId();
    Http::fake(['*' => Http::response('boom', 500)]);

    $this->get("/listings/{$id}/slow")
        ->assertStatus(503)
        ->assertExactJson(['success' => false, 'status' => 503, 'message' => 'VIN service unavailable.', 'errors' => []]);
});

test('AC-1 slow listing answers 503 json when the stub times out', function () {
    $id = slowListingId();
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

    $this->get("/listings/{$id}/slow")
        ->assertStatus(503)
        ->assertJsonPath('message', 'VIN service unavailable.');
});

test('MNC-3 slow listing config targets the stub with a 2 s timeout', function () {
    expect(config('services.vinstub.url'))->toBe('http://vinstub:3000')
        ->and(config('services.vinstub.timeout'))->toBe(2)
        ->and(config('services.vinstub.connect_timeout'))->toBe(1);
});

test('AC-1 slow listing is a json 404 without an Accept header when the id does not match the route', function (string $id) {
    Http::fake();

    $this->get("/listings/{$id}/slow")
        ->assertNotFound()
        ->assertJson(['success' => false, 'status' => 404, 'message' => 'Not found']);

    Http::assertNothingSent();
})->with(['zero' => '0', 'letters' => 'abc']);

test('AC-1 slow listing is hidden with a 404 while DEBUG_ROUTES is off', function () {
    config(['app.debug_routes' => false]);
    Http::fake();
    $id = slowListingId();

    $this->get("/listings/{$id}/slow")
        ->assertNotFound()
        ->assertJson(['success' => false, 'status' => 404]);

    Http::assertNothingSent();
});

test('AC-1 slow listing answers 429 over the slow limiter', function () {
    config(['services.vinstub.per_minute' => 2]);
    Http::fake(['*' => Http::response(['vin' => 'x', 'make_check' => 'ok', 'recalls' => 0, 'source' => 'vinstub'])]);
    $id = slowListingId();

    $this->get("/listings/{$id}/slow")->assertOk();
    $this->get("/listings/{$id}/slow")->assertOk();
    $this->get("/listings/{$id}/slow")->assertStatus(429);
});

test('AC-1 slow limiter defaults to 60 a minute', function () {
    expect(config('services.vinstub.per_minute'))->toBe(60);
});
