<?php

declare(strict_types=1);

use App\Models\Dealer;
use App\Models\Listing;
use App\Services\ListingPriceService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'mercure.enabled' => true,
        'mercure.publisher_jwt_key' => 'publisher-key-for-tests',
        'mercure.publish_url' => 'http://hub.test/.well-known/mercure',
        'mercure.topic_base' => 'https://x.test',
    ]);
    $this->listing = Listing::factory()->for(Dealer::factory())->create(['price_cents' => 1_000_00, 'currency' => 'EUR']);
});

test('AC-1 a price change publishes one public update with the formatted price', function () {
    Http::fake(['hub.test/*' => Http::response('urn:uuid:1')]);

    $this->artisan('listings:reprice', ['id' => $this->listing->id, 'price' => 1_234_56])
        ->expectsOutputToContain('1,000.00 EUR -> 1,234.56 EUR')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $data = json_decode($request['data'], true);

        return $request['topic'] === "https://x.test/listings/{$this->listing->id}/price"
            && ! isset($request['private'])
            && $data['price_cents'] === 1_234_56
            && $data['formatted'] === '1,234.56 EUR'
            && $data['id'] === $this->listing->id
            && is_int($data['published_at_ms']);
    });
    expect($this->listing->fresh()->price_cents)->toBe(1_234_56);
});

test('AC-1 nothing is published when the price does not change', function () {
    Http::fake();

    $this->artisan('listings:reprice', ['id' => $this->listing->id, 'price' => 1_000_00])->assertSuccessful();

    Http::assertNothingSent();
});

test('the model has no publish side effect: a plain save publishes nothing', function () {
    Http::fake();

    $this->listing->update(['price_cents' => 42_00, 'city' => 'Elsewhere']);

    Http::assertNothingSent();
});

test('the service returns what was published and builds the update once', function () {
    Http::fake(['hub.test/*' => Http::response('urn:uuid:9')]);

    $result = app(ListingPriceService::class)->reprice($this->listing->id, 1_500_00, private: true);

    expect($result->update->previousPriceCents)->toBe(1_000_00)
        ->and($result->update->priceCents)->toBe(1_500_00)
        ->and($result->publicPublished)->toBeTrue()
        ->and($result->privatePublished)->toBeTrue();

    $unchanged = app(ListingPriceService::class)->reprice($this->listing->id, 1_500_00);
    expect($unchanged->publicPublished)->toBeNull()->and($unchanged->privatePublished)->toBeNull();
});

test('the service reports a failed publish per topic and still saves the price', function () {
    Http::fake(fn (Request $request) => str_contains((string) $request['topic'], '/private') ? Http::response('no', 503) : Http::response('urn:uuid:1'));

    $result = app(ListingPriceService::class)->reprice($this->listing->id, 1_700_00, private: true);

    expect($result->publicPublished)->toBeTrue()
        ->and($result->privatePublished)->toBeFalse()
        ->and($this->listing->fresh()->price_cents)->toBe(1_700_00);
});

test('AC-2 --private also publishes to the private topic with private=on', function () {
    Http::fake(['hub.test/*' => Http::response('urn:uuid:2')]);

    $this->artisan('listings:reprice', ['id' => $this->listing->id, 'price' => 2_000_00, '--private' => true])->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => $r['topic'] === "https://x.test/listings/{$this->listing->id}/price/private" && $r['private'] === 'on');
    Http::assertSent(fn (Request $r) => $r['topic'] === "https://x.test/listings/{$this->listing->id}/price" && ! isset($r['private']));
});

test('MNC-4 a hub that is down is reported and the command still succeeds', function () {
    Http::fake(['hub.test/*' => Http::response('nope', 503)]);
    $reported = 0;
    $this->app->instance(ExceptionHandler::class, new class($this->app->make(ExceptionHandler::class), $reported) implements ExceptionHandler
    {
        public function __construct(private ExceptionHandler $inner, public int &$count) {}

        public function report(Throwable $e): void
        {
            $this->count++;
        }

        public function shouldReport(Throwable $e): bool
        {
            return true;
        }

        public function render($request, Throwable $e)
        {
            return $this->inner->render($request, $e);
        }

        public function renderForConsole($output, Throwable $e): void
        {
            $this->inner->renderForConsole($output, $e);
        }
    });

    $this->artisan('listings:reprice', ['id' => $this->listing->id, 'price' => 3_000_00, '--private' => true])
        ->expectsOutputToContain('public update was not published')
        ->expectsOutputToContain('private update was not published')
        ->assertSuccessful();

    expect($this->listing->fresh()->price_cents)->toBe(3_000_00)->and($reported)->toBeGreaterThan(0);
});

test('--restore sets the price back and an unknown listing fails', function () {
    Http::fake();

    $this->artisan('listings:reprice', ['id' => $this->listing->id, '--restore' => 555_00])->assertSuccessful();
    expect($this->listing->fresh()->price_cents)->toBe(555_00);

    $this->artisan('listings:reprice', ['id' => 999999, 'price' => 100])->assertFailed();
    $this->artisan('listings:reprice', ['id' => $this->listing->id, 'price' => 'abc'])->assertExitCode(2);
});

test('no update is published and no warning shown when mercure is disabled', function () {
    config(['mercure.enabled' => false]);
    Http::fake();

    $this->artisan('listings:reprice', ['id' => $this->listing->id, 'price' => 42_00, '--private' => true])
        ->doesntExpectOutputToContain('was not published')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect($this->listing->fresh()->price_cents)->toBe(42_00);
});
