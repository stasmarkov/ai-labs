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
