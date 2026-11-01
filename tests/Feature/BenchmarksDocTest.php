<?php

declare(strict_types=1);

test('AC-12 BENCHMARKS.md versions table has one nginx-fpm and one apache-fpm row', function (): void {
    $doc = file_get_contents(base_path('BENCHMARKS.md'));

    expect($doc)->toContain("\n## Versions\n");

    $header = '| Active server | Web server version | DDEV version | PHP | SAPI | OPcache | Date |';
    expect($doc)->toContain($header);

    $date = '\d{4}-\d{2}-\d{2}';
    $version = '\d+(\.\d+)+';
    foreach (['nginx-fpm', 'apache-fpm'] as $server) {
        expect(preg_match_all("/^\| $server \| $version \| $version \| $version \| fpm-fcgi \| true \| $date \|$/m", $doc))
            ->toBe(1);
    }
    expect(preg_match_all('/^\| (nginx|apache)-fpm \|/m', $doc))->toBe(2);
});

test('AC-10 BENCHMARKS.md has a frankenphp row and the add-on version', function (): void {
    $doc = file_get_contents(base_path('BENCHMARKS.md'));

    expect(preg_match_all('/^\| frankenphp \| \d+(\.\d+)+ \| \d+(\.\d+)+ \| \d+(\.\d+)+ \| frankenphp \| true \| \d{4}-\d{2}-\d{2} \|$/m', $doc))
        ->toBe(1);
    expect($doc)->toMatch('/^FrankenPHP row: ddev\/ddev-frankenphp v1\.3\.0, Caddy v\d+(\.\d+)+$/m');

    preg_match('/^## Response parity\n(.*?)(?=^## |\z)/ms', $doc, $matches);
    $parity = $matches[1] ?? '';
    expect($parity)->toContain('bench/parity/nginx-fpm.txt')
        ->toContain('bench/parity/apache-fpm.txt')
        ->toContain('bench/parity/frankenphp.txt')
        ->toContain('`bench/parity.sh compare nginx-fpm frankenphp`')
        ->toContain('`bench/parity.sh compare apache-fpm frankenphp`')
        ->toContain('`identical`');
});

test('AC-10 BENCHMARKS.md has an openlitespeed row and pinning note', function (): void {
    $doc = file_get_contents(base_path('BENCHMARKS.md'));

    expect(preg_match_all('/^\| openlitespeed \| \d+(\.\d+)+ \| \d+(\.\d+)+ \| \d+(\.\d+)+ \| litespeed \| true \| \d{4}-\d{2}-\d{2} \|$/m', $doc))
        ->toBe(1);
    expect($doc)->toContain('1.9.4-lsphp84')
        ->toContain('lsphp84-pgsql')
        ->toContain('point-in-time');

    preg_match('/^## Response parity\n(.*?)(?=^## |\z)/ms', $doc, $matches);
    $parity = $matches[1] ?? '';
    expect($parity)->toContain('bench/parity/openlitespeed.txt')
        ->toContain('`bench/parity.sh compare nginx-fpm openlitespeed`')
        ->toContain('`identical`')
        ->toContain('8091')
        ->toContain('idle');
});

test('AC-8 BENCHMARKS.md has both worker-mode rows and the Octane version', function (): void {
    $doc = file_get_contents(base_path('BENCHMARKS.md'));

    foreach (['frankenphp-octane', 'frankenphp-worker'] as $server) {
        expect(preg_match_all("/^\| $server \| \d+(\.\d+)+ \| \d+(\.\d+)+ \| \d+(\.\d+)+ \| frankenphp \| true \| \d{4}-\d{2}-\d{2} \|$/m", $doc))
            ->toBe(1);
    }
    expect($doc)->toMatch('/^Worker rows: laravel\/octane v\d+(\.\d+)+/m')
        ->toContain('4 workers')
        ->toContain('max requests 500');

    preg_match('/^## Response parity\n(.*?)(?=^## |\z)/ms', $doc, $matches);
    $parity = $matches[1] ?? '';
    expect($parity)->toContain('bench/parity/frankenphp-octane.txt')
        ->toContain('bench/parity/frankenphp-worker.txt')
        ->toContain('`bench/parity.sh compare nginx-fpm frankenphp-octane`')
        ->toContain('`bench/parity.sh compare nginx-fpm frankenphp-worker`')
        ->toContain('`identical`');
});

test('AC-10 BENCHMARKS.md has expectations written before results', function (): void {
    $doc = file_get_contents(base_path('BENCHMARKS.md'));

    expect($doc)->toContain("\n## Expectations\n");

    preg_match('/^## Expectations\n(.*?)(?=^## |\z)/ms', $doc, $matches);
    $expectations = $matches[1] ?? '';

    expect($expectations)->toContain('ping')
        ->toContain('search')
        ->toContain('memory')
        ->toContain('timeout')
        ->toContain('worker')
        ->toContain('Surprises');
});

test('AC-11 BENCHMARKS.md explains how the numbers work', function (): void {
    $doc = file_get_contents(base_path('BENCHMARKS.md'));

    expect($doc)->toContain("\n## How the numbers work\n");

    preg_match('/^## How the numbers work\n(.*?)(?=^## |\z)/ms', $doc, $matches);
    $section = $matches[1] ?? '';

    expect($section)->toContain('RPS')
        ->toContain('p50')
        ->toContain('p95')
        ->toContain('p99')
        ->toContain('error')
        ->toContain('spread')
        ->toContain('router')
        ->toContain('listen backlog')
        ->toContain('connection queue')
        ->toContain('request queue')
        ->toContain('ratios')
        ->toContain('7 CPUs')
        ->toContain('2 CPUs')
        ->toContain('3xx')
        ->toMatch('/`~`[^\n]*`noisy`|`noisy`[^\n]*`~`/')
        ->not->toContain('within 5 percent is reported as `~`');

    preg_match('/^## Surprises\n(.*?)(?=^## |\z)/ms', $doc, $surprises);
    expect($surprises[1] ?? '')->toContain('`~`')->toContain('noisy');
});
