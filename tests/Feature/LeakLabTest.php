<?php

declare(strict_types=1);

use App\Debug\Leaks\LeakLabServiceProvider;
use Illuminate\Support\Facades\Cache;
use Tests\Support\OctaneRunner;

/**
 * @return array{0: string, 1: string}
 */
function leakUris(string $scenario, string $mode): array
{
    return [
        "/debug/leaks/{$scenario}?dealer=1&locale=uk&mode={$mode}",
        $scenario === 'number-locale'
            ? "/debug/leaks/{$scenario}?dealer=2&mode={$mode}"
            : "/debug/leaks/{$scenario}?dealer=2&locale=de&mode={$mode}",
    ];
}

/**
 * @return list<string>
 */
function leakScenarios(): array
{
    return ['dealer-scope', 'price-cache', 'number-locale', 'request-at-boot'];
}

test('AC-1 dealer-scope leaks in a worker in leak mode and not in fixed mode', function (): void {
    [$a, $b] = OctaneRunner::worker(leakUris('dealer-scope', 'leak'));
    expect($a['status'])->toBe(200)
        ->and($a['body']['data']['leaked'])->toBeFalse()
        ->and($b['body']['data']['requested'])->toBe(2)
        ->and($b['body']['data']['observed'])->toBe(1)
        ->and($b['body']['data']['leaked'])->toBeTrue();

    [, $b] = OctaneRunner::worker(leakUris('dealer-scope', 'fixed'));
    expect($b['body']['data']['observed'])->toBe(2)
        ->and($b['body']['data']['leaked'])->toBeFalse();
});

test('AC-2 price-cache leaks in a worker in leak mode and not in fixed mode', function (): void {
    [$a, $b] = OctaneRunner::worker(leakUris('price-cache', 'leak'));
    expect($a['body']['data']['leaked'])->toBeFalse()
        ->and($a['body']['data']['cache_entries'])->toBe(1)
        ->and($b['body']['data']['requested'])->toBe('de')
        ->and($b['body']['data']['observed'])->toBe('uk')
        ->and($b['body']['data']['formatted'])->toBe($a['body']['data']['formatted'])
        ->and($b['body']['data']['cache_entries'])->toBe(1)
        ->and($b['body']['data']['leaked'])->toBeTrue();

    $uris = [...leakUris('price-cache', 'leak'), '/debug/leaks/price-cache?dealer=3&locale=en&amount=100&mode=leak'];
    expect(OctaneRunner::worker($uris)[2]['body']['data']['cache_entries'])->toBe(2);

    [, $b] = OctaneRunner::worker(leakUris('price-cache', 'fixed'));
    expect($b['body']['data']['observed'])->toBe('de')
        ->and($b['body']['data']['cache_entries'])->toBeNull()
        ->and($b['body']['data']['leaked'])->toBeFalse();
});

test('AC-3 number-locale leaks in a worker in leak mode and not in fixed mode', function (): void {
    [$a, $b] = OctaneRunner::worker(leakUris('number-locale', 'leak'));
    expect($a['body']['data']['leaked'])->toBeFalse()
        ->and($b['body']['data']['requested'])->toBe('en')
        ->and($b['body']['data']['observed'])->toBe('uk')
        ->and($b['body']['data']['leaked'])->toBeTrue()
        ->and($b['body']['data']['config_locale'])->toBe('en')
        ->and($b['body']['data']['app_locale'])->toBe('en');

    [, $b] = OctaneRunner::worker(leakUris('number-locale', 'fixed'));
    expect($b['body']['data']['observed'])->toBe('en')
        ->and($b['body']['data']['leaked'])->toBeFalse()
        ->and($b['body']['data']['config_locale'])->toBe('en');
});

test('AC-4 request-at-boot leaks in a worker in leak mode and not in fixed mode', function (): void {
    [, $b] = OctaneRunner::worker(leakUris('request-at-boot', 'leak'));
    expect($b['body']['data']['requested'])->toBe(2)
        ->and($b['body']['data']['observed'])->toBeNull()
        ->and($b['body']['data']['leaked'])->toBeTrue();

    [, $b] = OctaneRunner::worker(leakUris('request-at-boot', 'fixed'));
    expect($b['body']['data']['observed'])->toBe(2)
        ->and($b['body']['data']['leaked'])->toBeFalse();
});

test('AC-6 every scenario leaks only in leak mode inside one worker', function (): void {
    foreach (leakScenarios() as $scenario) {
        [, $leak] = OctaneRunner::worker(leakUris($scenario, 'leak'));
        [, $fixed] = OctaneRunner::worker(leakUris($scenario, 'fixed'));

        expect($leak['body']['data']['leaked'])->toBeTrue("{$scenario} leak")
            ->and($fixed['body']['data']['leaked'])->toBeFalse("{$scenario} fixed");
    }
});

test('AC-7 no scenario leaks when every request boots the app', function (): void {
    foreach (leakScenarios() as $scenario) {
        foreach (['leak', 'fixed'] as $mode) {
            foreach (OctaneRunner::classic(leakUris($scenario, $mode)) as $response) {
                expect($response['status'])->toBe(200)
                    ->and($response['body']['data']['leaked'])->toBeFalse("{$scenario} {$mode}");
            }
        }
    }
});

test('lab routes validate input and reject unknown scenarios', function (): void {
    config(['app.debug_routes' => true]);

    $this->getJson('/debug/leaks/dealer-scope?dealer=0')->assertStatus(422)->assertJsonPath('success', false);
    $this->getJson('/debug/leaks/dealer-scope?dealer=1&mode=bogus')->assertStatus(422);
    $this->getJson('/debug/leaks/dealer-scope?dealer=1&locale=fr')->assertStatus(422);
    $this->getJson('/debug/leaks/price-cache?dealer=1&amount=abc')->assertStatus(422)->assertJsonPath('success', false);
    $this->getJson('/debug/leaks/nope?dealer=1')->assertNotFound();
});

test('lab routes answer 404 when debug routes are off', function (): void {
    config(['app.debug_routes' => false]);

    $this->getJson('/debug/leaks/dealer-scope?dealer=1')->assertNotFound();
});

test('MNC-2 lab service provider registers nothing when debug routes are off', function (): void {
    expect(config('app.debug_routes'))->toBeFalse();

    foreach ([
        LeakLabServiceProvider::DEALER_LEAK,
        LeakLabServiceProvider::DEALER_FIXED,
        LeakLabServiceProvider::BOOT_REQUEST_LEAK,
        LeakLabServiceProvider::BOOT_REQUEST_FIXED,
    ] as $key) {
        expect(app()->bound($key))->toBeFalse($key);
    }
});

test('a lab request answers 503 when debug routes were enabled after the worker booted', function (): void {
    config(['app.debug_routes' => true]);

    $this->getJson('/debug/leaks/dealer-scope?dealer=1')
        ->assertStatus(503)
        ->assertSee('Restart the workers', false);
});

test('AC-2 fixed price-cache stores each amount and locale under its own key for 60 seconds', function (): void {
    Cache::shouldReceive('remember')
        ->once()
        ->withArgs(fn (string $key, int $ttl, Closure $callback): bool => $key === 'lab:price:7150300:de' && $ttl === 60)
        ->andReturn(['locale' => 'de', 'formatted' => 'x']);

    expect((new App\Debug\Leaks\CachedPriceFormatter)->format(7150300, 'de'))->toBe(['locale' => 'de', 'formatted' => 'x']);
});

test('AC-3 fixed number-locale never changes the global Number locale', function (): void {
    [$a, $b] = OctaneRunner::worker([
        '/debug/leaks/number-locale?dealer=1&locale=uk&mode=fixed',
        '/debug/leaks/number-locale?dealer=2&mode=leak',
    ]);

    expect($a['body']['data']['observed'])->toBe('uk')
        ->and($b['body']['data']['observed'])->toBe('en')
        ->and($b['body']['data']['leaked'])->toBeFalse();
});

test('lab defaults: mode leak and amount 7150300 cents', function (): void {
    [, $b] = OctaneRunner::worker(['/debug/leaks/dealer-scope?dealer=1', '/debug/leaks/dealer-scope?dealer=2']);
    expect($b['body']['data']['leaked'])->toBeTrue();

    [$price] = OctaneRunner::worker(['/debug/leaks/price-cache?dealer=1&locale=en&mode=fixed']);
    expect($price['body']['data']['formatted'])->toContain('71,503');
});
