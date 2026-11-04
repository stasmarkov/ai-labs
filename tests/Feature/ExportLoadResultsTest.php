<?php

declare(strict_types=1);

function exportLoadResultFiles(): array
{
    $dirs = glob(dirname(__DIR__, 2).'/bench/results/export-load/*', GLOB_ONLYDIR) ?: [];
    sort($dirs);
    $latest = end($dirs);

    return $latest === false ? [] : (glob($latest.'/*.json') ?: []);
}

it('AC-6 the committed export-load run has one file per server and mode', function (): void {
    $names = array_map(fn (string $file): string => basename($file, '.json'), exportLoadResultFiles());
    sort($names);

    $expected = [];
    foreach (['apache-fpm', 'frankenphp', 'nginx-fpm', 'openlitespeed'] as $server) {
        foreach (['offload', 'stream'] as $mode) {
            $expected[] = "{$mode}-{$server}";
        }
    }
    sort($expected);

    expect($names)->toBe($expected);
});

it('AC-6 every export-load result is a full default-limit run with no integrity errors', function (): void {
    $files = exportLoadResultFiles();
    expect($files)->toHaveCount(8);

    foreach ($files as $file) {
        $result = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $name = basename($file);

        expect($result['quick'])->toBeFalse("$name is a quick run");
        expect($result['repeats'])->toBe(3, "$name repeats");
        expect($result['clients'])->toBe(20, "$name clients");
        expect($result['concurrency'])->toBe('default', "$name worker limits");
        expect($result['server'].'-'.$result['mode'])->not->toBe('');
        expect(basename($file, '.json'))->toBe($result['mode'].'-'.$result['label']);
        expect($result['median']['load_integrity_errors'])->toBe(0, "$name integrity errors");
        expect($result['median']['load_downloads'])->toBeGreaterThan(0, "$name downloads");
    }
});
