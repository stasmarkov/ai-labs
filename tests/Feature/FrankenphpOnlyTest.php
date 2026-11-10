<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('ships a web image with no apache modules and no openlitespeed server files', function (): void {
    $root = dirname(__DIR__, 2);

    expect(is_dir($root.'/.ddev/servers/openlitespeed'))->toBeFalse();

    $dockerfile = $root.'/.ddev/web-build/Dockerfile';
    $contents = is_file($dockerfile) ? (string) file_get_contents($dockerfile) : '';

    expect($contents)->not->toContain('apache')
        ->and($contents)->not->toContain('xsendfile');

    $gitignore = (string) file_get_contents($root.'/.gitignore');

    expect($gitignore)->not->toContain('openlitespeed')
        ->and($gitignore)->not->toContain('bench-timeouts.conf')
        ->and($gitignore)->not->toContain('nginx-site.conf.bench-orig');

    expect((string) file_get_contents($root.'/.ddev/commands/host/server'))->not->toContain('bench-orig');
});

it('AC-4 finds no removed server name in tracked files outside the allowlist', function (): void {
    $allowlist = [
        '.ai/' => 'specs, session notes and lessons are history',
        'bench/results/' => 'committed measurements of the removed servers',
        'BENCHMARKS.md' => 'measured sections keep the old numbers',
        'product-brief.md' => 'history',
        'backlog.md' => 'history',
        'docs/learning-guide.md' => 'history narrative',
        'docs/adr/' => 'decision records are history',
        '.ddev/config.yaml' => 'MNC-6: still says webserver_type: nginx-fpm',
        'bench/lib/servers.sh' => 'the REMOVED_SERVERS list',
        'bench/summary.php' => 'renders the historical results',
        'tests/Feature/FrankenphpOnlyTest.php' => 'this guard',
        'tests/Feature/BenchmarksDocTest.php' => 'asserts historical numbers in BENCHMARKS.md',
        'tests/Feature/BenchSummaryTest.php' => 'historical fixtures',
        'tests/Feature/BenchCommittedRunsTest.php' => 'reads committed bench/results',
        'tests/Feature/ExportLoadResultsTest.php' => 'reads committed bench/results',
        'tests/Feature/BenchHttp3Test.php' => 'asserts the removal message',
        'tests/Feature/BenchChatStopTest.php' => 'asserts the removal message',
        'tests/Feature/BenchConcurrencyTest.php' => 'asserts the removal message',
        'tests/Feature/BenchTimeoutsTest.php' => 'asserts the removal message',
        'tests/Feature/BenchScriptsTest.php' => 'asserts the removal message',
        'tests/Feature/BenchSuiteServersTest.php' => 'asserts the removal message',
        'tests/Feature/BenchRunConditionsTest.php' => 'asserts the removal message',
        'tests/Feature/DdevServerCommandTest.php' => 'asserts the removal message',
        'tests/Feature/ServerDefaultCommandTest.php' => 'asserts the removal message',
        'tests/Feature/ServerSwitchChecksTest.php' => 'MNC-1 pins the unchanged config.yaml',
        'tests/Feature/BenchRenameProjectTest.php' => 'fixture copy of the unchanged config.yaml',
        'tests/Feature/ReadmeSwitchingServersTest.php' => 'AC-5 asserts the docs hold no removed name',
        'tests/Feature/DocsDefaultServerTest.php' => 'pins what the docs say about the old default',
        'tests/Feature/PreloadLinksTest.php' => 'reads the historical early-hints spec',
        'tests/Feature/LearningGuideTest.php' => 'reads the history narrative in the learning guide',
    ];

    // Files where only one known sentence may name a removed server; any other matching line fails.
    $allowedLines = [
        'CLAUDE.md' => '/`\.ddev\/config\.yaml` still says `webserver_type: nginx-fpm` on purpose/',
    ];

    $process = new Process(['git', 'ls-files', '-z'], base_path());
    $process->mustRun();

    $offenders = [];

    foreach (array_filter(explode("\0", $process->getOutput())) as $path) {
        foreach (array_keys($allowlist) as $allowed) {
            if ($path === $allowed || (str_ends_with($allowed, '/') && str_starts_with($path, $allowed))) {
                continue 2;
            }
        }

        $file = base_path($path);

        if (! is_file($file)) {
            continue;
        }

        $contents = (string) file_get_contents($file);

        if (str_contains($contents, "\0")) {
            continue;
        }

        if (preg_match('/nginx-fpm|apache-fpm|openlitespeed/i', $contents) !== 1) {
            continue;
        }

        if (isset($allowedLines[$path])) {
            $matching = preg_grep('/nginx-fpm|apache-fpm|openlitespeed/i', explode("\n", $contents));

            if (preg_grep($allowedLines[$path], $matching, PREG_GREP_INVERT) === []) {
                continue;
            }
        }

        $offenders[] = $path;
    }

    expect($offenders)->toBe([]);
});

it('AC-2 current_server refuses a removed or not-set-up server so no result is labelled with it', function (string $current, int $exit, string $message): void {
    $bin = sys_get_temp_dir().'/current-server-'.bin2hex(random_bytes(4));
    mkdir($bin);
    file_put_contents($bin.'/ddev', "#!/usr/bin/env bash\necho ".escapeshellarg($current)."\n");
    chmod($bin.'/ddev', 0755);

    $process = new Process(['bash', '-c', 'source bench/lib/servers.sh; current_server .'], base_path(), ['PATH' => $bin.':'.getenv('PATH')]);
    $process->run();
    exec('rm -rf '.escapeshellarg($bin));

    expect($process->getExitCode())->toBe($exit)
        ->and($exit === 0 ? trim($process->getOutput()) : $process->getErrorOutput())->toContain($message);
})->with([
    'supported' => ['frankenphp-octane', 0, 'frankenphp-octane'],
    'removed' => ['nginx-fpm (removed; run ddev server default)', 2, 'nginx-fpm was removed in SL-50'],
    'not set up' => ['not set up (run ddev setup)', 2, 'not on a FrankenPHP mode'],
]);
