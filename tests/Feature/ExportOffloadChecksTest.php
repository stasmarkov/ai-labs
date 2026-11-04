<?php

declare(strict_types=1);

const CHECKS_SERVERS = ['nginx-fpm', 'apache-fpm', 'frankenphp', 'frankenphp-octane', 'frankenphp-worker', 'openlitespeed'];

function checksRoot(string $relative = ''): string
{
    return rtrim(dirname(__DIR__, 2).'/'.$relative, '/');
}

test('MNC-1 the parity snapshots of the six servers carry the same hashes', function () {
    $bodies = [];

    foreach (CHECKS_SERVERS as $server) {
        $lines = file(checksRoot("bench/parity/{$server}.txt"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $bodies[$server] = array_values(array_filter($lines, fn (string $line): bool => ! str_starts_with($line, '#')));
    }

    expect($bodies['nginx-fpm'])->toHaveCount(7);

    foreach (CHECKS_SERVERS as $server) {
        expect($bodies[$server])->toBe($bodies['nginx-fpm']);
    }
});

test('MNC-1 the parity request set does not include the export route', function () {
    expect(file_get_contents(checksRoot('bench/parity.sh')))->not->toContain('/exports');
});

test('MNC-2 config.yaml has no worker limits and no concurrency overlay exists', function () {
    $config = file_get_contents(checksRoot('.ddev/config.yaml'));

    expect($config)->not->toMatch('/FRANKENPHP_CONFIG|num_threads|max_threads|PHP_LSAPI_CHILDREN|pm\.max_children/');

    foreach (['config.zz-bench-concurrency.yaml', 'config.zzz-bench-concurrency.yaml', 'docker-compose.bench-concurrency.yaml', 'web-entrypoint.d/zz-bench-fpm.sh', 'bench-concurrency'] as $overlay) {
        expect(file_exists(checksRoot(".ddev/{$overlay}")))->toBeFalse();
    }
});

test('MNC-3 the fingerprinted entries are still in the Vite manifest', function () {
    $manifest = checksRoot('public/build/manifest.json');

    if (! is_file($manifest)) {
        $this->markTestSkipped('No Vite manifest; run npm run build first.');
    }

    $entries = json_decode(file_get_contents($manifest), true);

    expect($entries)->toHaveKeys(['resources/css/app.css', 'resources/js/app.js']);
});

test('MNC-4 slow-load keeps sourcing the shared probe and its results stay committed', function () {
    $script = file_get_contents(checksRoot('bench/slow-load.sh'));

    expect($script)->toContain('source "$SCRIPT_DIR/lib/health-probe.sh"')
        ->and(file_get_contents(checksRoot('bench/export-load.sh')))->toContain('lib/health-probe.sh')
        ->and(glob(checksRoot('bench/results/slow-load/*/*.json')))->not->toBeEmpty();
});
