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
