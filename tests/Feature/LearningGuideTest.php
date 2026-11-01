<?php

declare(strict_types=1);

test('AC-10 learning guide explains state leaks with links', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## \d+\. State leaks in worker mode\n(.*?)(?=^---$|^## \d+\. |\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';

    expect($section)->not->toBe('');

    foreach (['CreateConfigurationSandbox', 'FlushLocaleState', 'GiveNewRequestInstanceToApplication', 'dealer-scope', 'price-cache', 'number-locale', 'request-at-boot', '--max-requests', '/debug/worker', 'worker_hits', 'Number::useLocale'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach (['../app/Debug/Leaks/', '../tests/Feature/LeakLabTest.php', '../BENCHMARKS.md#state-leaks', 'https://laravel.com/docs/13.x/octane'] as $link) {
        expect($section)->toContain("]($link");
    }

    foreach (['../app/Debug/Leaks/', '../tests/Feature/LeakLabTest.php', '../bench/leaks.sh'] as $path) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$path))->toBeTrue();
    }

    expect($guide)->not->toContain('state leaks in worker mode (SL-07)');
});
