<?php

declare(strict_types=1);

test('MNC-1 .ddev/config.yaml keeps nginx-fpm and no per-server settings', function (): void {
    $config = file_get_contents(base_path('.ddev/config.yaml'));

    expect($config)->toMatch('/^webserver_type: nginx-fpm$/m')
        ->not->toMatch('/^[^#\n]*(frankenphp|openlitespeed)/im');
});

test('MNC-2 every server keeps a parity baseline', function (): void {
    foreach (['frankenphp', 'frankenphp-octane', 'frankenphp-worker'] as $server) {
        expect(base_path("bench/parity/{$server}.txt"))->toBeFile();
    }
});
