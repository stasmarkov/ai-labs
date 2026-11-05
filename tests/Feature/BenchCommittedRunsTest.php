<?php

declare(strict_types=1);

/**
 * @return array<int, array<string, mixed>>
 */
function committedResults(string $kind, string $name): array
{
    $files = glob(base_path("bench/results/$kind/*/$name.json")) ?: [];

    return array_map(fn (string $file): array => json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), $files);
}

test('AC-3 the 103 path is recorded for frankenphp and nginx-fpm', function (): void {
    foreach (['frankenphp' => true, 'nginx-fpm' => false] as $server => $expected) {
        $results = committedResults('early-hints', $server);

        expect($results)->not->toBeEmpty();
        expect($results[0]['direct_103'])->toBe($expected)
            ->and($results[0]['router_103'])->toBe($expected);
    }
});

test('AC-2 the worker and Octane servers send no 103, on either path', function (): void {
    foreach (['frankenphp-worker', 'frankenphp-octane'] as $server) {
        $results = committedResults('early-hints', $server);

        expect($results)->not->toBeEmpty()
            ->and($results[0]['router_103'])->toBeFalse()
            ->and($results[0]['direct_103'])->toBeFalse();
    }
});

test('committed bench results hold no certificate lines, internal IPs, user name or host name', function (): void {
    $files = array_merge(
        glob(base_path('bench/results/early-hints/*/*')) ?: [],
        glob(base_path('bench/results/lcp/*/*.json')) ?: [],
        glob(base_path('bench/results/lcp/*/*/*.json')) ?: [],
        glob(base_path('bench/results/compression/*/*.json')) ?: [],
    );

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $text = file_get_contents($file);

        expect(preg_match('/stasmarkov|Stass-MacBook|subject:|issuer:|mkcert|OU=|\b\d{1,3}(\.\d{1,3}){3}\b|\p{Cyrillic}/iu', $text))->toBe(0, $file);
    }
});

test('AC-4 LCP has 3 variants of 5 cold runs, early hint used only with frankenphp hints on', function (): void {
    $expected = ['frankenphp-hints' => true, 'frankenphp-no-hints' => false, 'nginx-fpm' => false];

    foreach ($expected as $variant => $hintUsed) {
        $results = committedResults('lcp', $variant);

        expect($results)->not->toBeEmpty();
        $result = $results[0];
        expect($result['runs'])->toBe(5)
            ->and($result['samples'])->toHaveCount(5)
            ->and($result['throttle'])->toMatchArray(['rtt_ms' => 150, 'down_kbps' => 1600, 'up_kbps' => 750, 'cpu_slowdown' => 4])
            ->and($result['throttle']['method'])->toContain('toxiproxy')
            ->and($result['throttle_image_digest'])->toContain('@sha256:')
            ->and($result['valid_runs'])->toBeGreaterThanOrEqual(3)
            ->and($result['valid_runs'] + $result['invalid_runs'])->toBe(5)
            ->and($result['path_reason'])->not->toBeEmpty();
        foreach (['lcp_ms', 'fcp_ms', 'ttfb_ms'] as $metric) {
            expect($result[$metric])->toHaveKeys(['median', 'min', 'max']);
        }
        expect($result['early_hint_used'])->toBe($hintUsed)
            ->and($result['cold'])->toBeTrue()
            ->and($result['ttfb_ms']['median'])->toBeGreaterThanOrEqual(150)
            ->and($result['ttfb_source'])->toContain('Network.responseReceived');
    }
});

test('AC-5 compression is recorded per encoding and resource on both servers', function (): void {
    foreach (['nginx-fpm', 'frankenphp'] as $server) {
        $results = committedResults('compression', $server);

        expect($results)->not->toBeEmpty();
        $accepted = array_unique(array_column($results[0]['resources'], 'accept_encoding'));
        expect($accepted)->toContain('identity', 'gzip', 'br', 'zstd', 'zstd, br, gzip')
            ->and(array_unique(array_column($results[0]['resources'], 'resource')))->toContain('html', 'css', 'page-js', 'lab-bundle', 'export')
            ->and(array_unique(array_column($results[0]['resources'], 'resource')))->not->toContain('js');
    }
});

test('AC-6 the committed export is compressed at most once and matches the file hash', function (): void {
    foreach (['nginx-fpm', 'frankenphp'] as $server) {
        $export = committedResults('compression', $server)[0]['export'] ?? null;

        expect($export)->not->toBeNull()
            ->and($export['ok'])->toBeTrue()
            ->and(array_column($export['rows'], 'single_encoding'))->each->toBeTrue()
            ->and(array_column($export['rows'], 'sha_ok'))->each->toBeTrue();
    }
});

test('AC-2 the final 200 headers on frankenphp differ with hints on and off only by the Server header, and the deviation is documented', function (): void {
    $dir = base_path('bench/results/early-hints/2026-11-05');
    $headers = fn (string $state): array => array_values(array_filter(array_map(
        trim(...),
        file($dir."/frankenphp-final-headers-hints-$state.txt") ?: [],
    ), fn (string $line): bool => $line !== ''));

    expect($headers('on'))->toContain('HTTP/2 200')
        ->and($headers('off'))->toContain('HTTP/2 200');

    $onlyOff = array_values(array_diff($headers('off'), $headers('on')));
    expect($onlyOff)->toBe(['server: FrankenPHP Caddy'])
        ->and(array_diff($headers('on'), $headers('off')))->toBe([]);

    $spec = file_get_contents(base_path('.ai/specs/spec-20261104-2340-early-hints-compression/spec-lite.md'));
    $benchmarks = file_get_contents(base_path('BENCHMARKS.md'));

    expect($spec)->toContain('**Final 200 headers on FrankenPHP (Q2 deviation).**')
        ->and($benchmarks)->toContain('**The Server header is missing from the final 200 when a 103 is sent.**');
});
