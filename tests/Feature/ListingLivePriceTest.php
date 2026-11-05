<?php

declare(strict_types=1);

use App\Models\Dealer;
use App\Models\Listing;
use App\Support\MercureTokens;
use App\Support\PriceFormatter;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The mercureAuthorization cookies a response sets, leaving out the expired one that clears a leftover cookie.
 */
function subscriberCookies($response): array
{
    return array_values(array_filter(
        $response->headers->getCookies(),
        fn ($cookie) => $cookie->getName() === 'mercureAuthorization' && $cookie->getExpiresTime() > time(),
    ));
}

beforeEach(function (): void {
    config([
        'mercure.topic_base' => 'https://x.test',
        'mercure.subscriber_jwt_key' => 'subscriber-key-for-tests',
        'mercure.publisher_jwt_key' => 'publisher-key-for-tests',
    ]);
    $this->listing = Listing::factory()->for(Dealer::factory())->create(['price_cents' => 1999900, 'currency' => 'EUR']);
});

test('AC-1 the detail page names the hub and the listing price topic', function (): void {
    $this->get('/listings/'.$this->listing->id)
        ->assertOk()
        ->assertSee('<meta name="mercure-hub" content="/.well-known/mercure">', false)
        ->assertSee('data-price-topic="https://x.test/listings/'.$this->listing->id.'/price"', false)
        ->assertSee('19,999.00 EUR')
        ->assertDontSee('data-price-topic-private', false);
});

test('AC-1 the topic differs per listing', function (): void {
    $other = Listing::factory()->for(Dealer::factory())->create();

    $this->get('/listings/'.$other->id)
        ->assertSee('data-price-topic="https://x.test/listings/'.$other->id.'/price"', false)
        ->assertDontSee('/listings/'.$this->listing->id.'/price"', false);
});

test('NFR-2 a live price update is announced politely, not as an alert', function (): void {
    $html = $this->get('/listings/'.$this->listing->id)->getContent();

    expect($html)->toContain('aria-live="polite"')
        ->not->toContain('role="alert"')
        ->not->toContain('aria-live="assertive"');
});

test('AC-5 the page script loads from the Vite entry and the give-up rule is in it', function (): void {
    $script = (string) file_get_contents(resource_path('js/app.js'));

    expect($script)->toContain('EventSource')
        ->toContain('.close()')
        ->toContain('withCredentials')
        ->toContain('data-price-topic');
});

test('AC-2 without the debug gate watch=private is ignored', function (): void {
    config(['app.debug_routes' => false]);

    $response = $this->get('/listings/'.$this->listing->id.'?watch=private');

    $response->assertOk()->assertDontSee('data-price-topic-private', false);
    expect(subscriberCookies($response))->toBeEmpty();
});

test('AC-2 NFR-1 with the debug gate watch=private sets a scoped subscriber cookie and the private topic', function (): void {
    config(['app.debug_routes' => true]);

    $response = $this->get('/listings/'.$this->listing->id.'?watch=private');

    $response->assertOk()
        ->assertSee('data-price-topic-private="https://x.test/listings/'.$this->listing->id.'/price/private"', false);

    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'mercureAuthorization');
    $header = collect($response->headers->all('set-cookie'))->first(fn ($line) => str_starts_with($line, 'mercureAuthorization='));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict')
        ->and($cookie->getPath())->toBe('/.well-known/mercure')
        ->and($cookie->isRaw())->toBeTrue();
    expect($header)->toContain('Max-Age=')->toContain('path=/.well-known/mercure')->toContain('secure')->toContain('httponly')->toContain('samesite=strict');

    $claims = (new MercureTokens)->verify($cookie->getValue(), 'subscriber-key-for-tests');
    expect($claims['mercure'])->toBe(['subscribe' => ['https://x.test/listings/'.$this->listing->id.'/price/private']])
        ->and($claims['exp'] - $claims['iat'])->toBe(3600)
        ->and($cookie->getExpiresTime())->toBe($claims['exp']);

    preg_match('/Max-Age=(\d+)/', $header, $maxAge);
    expect((int) $maxAge[1])->toBeBetween(3598, 3600);
});

test('NFR-1 a plain detail page clears a leftover mercureAuthorization cookie with the same path and flags', function (): void {
    $response = $this->withUnencryptedCookie('mercureAuthorization', 'stale')->get('/listings/'.$this->listing->id);

    $header = collect($response->headers->all('set-cookie'))->first(fn ($line) => str_starts_with($line, 'mercureAuthorization='));

    expect($header)->not->toBeNull()
        ->toContain('mercureAuthorization=deleted')
        ->toContain('Max-Age=0')
        ->toContain('expires=')
        ->toContain('path=/.well-known/mercure')
        ->toContain('secure')
        ->toContain('httponly')
        ->toContain('samesite=strict');
});

test('NFR-1 watch=private without the debug gate also clears the cookie', function (): void {
    config(['app.debug_routes' => false]);

    $header = collect($this->get('/listings/'.$this->listing->id.'?watch=private')->headers->all('set-cookie'))
        ->first(fn ($line) => str_starts_with($line, 'mercureAuthorization='));

    expect($header)->toContain('Max-Age=0');
});

test('AC-1 the page renders the server time at render for the client replay filter', function (): void {
    $before = (int) floor(microtime(true) * 1000);
    $html = $this->get('/listings/'.$this->listing->id)->getContent();
    $after = (int) floor(microtime(true) * 1000);

    preg_match('/data-price-rendered-ms="(\d+)"/', $html, $m);

    expect((int) $m[1])->toBeBetween($before, $after);
});

test('AC-1 the client filter applies only updates not older than the render or the last applied update', function (): void {
    $node = (new ExecutableFinder)->find('node');
    if ($node === null) {
        $this->markTestSkipped('node is not available');
    }

    $module = resource_path('js/price-updates.js');
    $cases = [
        // [update, renderedMs, lastAppliedMs, expected]
        [['formatted' => 'x', 'published_at_ms' => 2000], 1000, 0, true],
        [['formatted' => 'x', 'published_at_ms' => 1000], 1000, 0, true],
        [['formatted' => 'x', 'published_at_ms' => 999], 1000, 0, false],
        [['formatted' => 'x', 'published_at_ms' => 2000], 1000, 3000, false],
        [['formatted' => 'x', 'published_at_ms' => 3000], 1000, 3000, true],
        [['formatted' => 5, 'published_at_ms' => 2000], 1000, 0, false],
        [['formatted' => 'x'], 1000, 0, false],
        [null, 1000, 0, false],
    ];
    $program = 'import(process.argv[1]).then(({ shouldApply }) => { const cases = JSON.parse(process.argv[2]);'
        .' console.log(JSON.stringify(cases.map(([u, r, l]) => shouldApply(u, r, l)))); });';

    $process = new Process([$node, '-e', $program, 'file://'.$module, json_encode($cases)]);
    $process->mustRun();

    expect(json_decode($process->getOutput(), true))->toBe(array_column($cases, 3));
});

test('AC-1 the script asks the hub for its history and filters by render time', function (): void {
    $script = (string) file_get_contents(resource_path('js/app.js'));

    expect($script)->toContain("'lastEventID', 'earliest'")
        ->toContain('priceRenderedMs')
        ->toContain('shouldApply');
});

test('AC-2 watch=private without keys renders the plain page', function (): void {
    config(['app.debug_routes' => true, 'mercure.subscriber_jwt_key' => null]);

    $response = $this->get('/listings/'.$this->listing->id.'?watch=private');

    $response->assertOk()->assertDontSee('data-price-topic-private', false);
    expect(subscriberCookies($response))->toBeEmpty();
});

test('MNC-1 the page body is the same with and without the debug gate', function (): void {
    config(['app.debug_routes' => false]);
    $off = $this->get('/listings/'.$this->listing->id)->getContent();
    config(['app.debug_routes' => true]);
    $on = $this->get('/listings/'.$this->listing->id)->getContent();

    $mask = fn (string $html) => preg_replace('/data-price-rendered-ms="\d+"/', 'data-price-rendered-ms="N"', $html);

    expect($mask($on))->toBe($mask($off));
});

test('AC-1 the rendered price text equals the shared price formatter', function (): void {
    $expected = PriceFormatter::format($this->listing->price_cents, $this->listing->currency);

    $this->get('/listings/'.$this->listing->id)
        ->assertSeeInOrder(['id="listing-price"', '>'.$expected.'</p>'], false);
});
