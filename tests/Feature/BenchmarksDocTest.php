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
    preg_match('/^## Versions\n(.*?)(?=^## |\z)/ms', $doc, $versions);
    expect(preg_match_all('/^\| (nginx|apache)-fpm \|/m', $versions[1] ?? ''))->toBe(2);
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

/**
 * The body of one `## ` or `### ` section of BENCHMARKS.md.
 */
function benchmarksSection(string $heading): string
{
    $doc = (string) file_get_contents(base_path('BENCHMARKS.md'));
    preg_match('/^'.preg_quote($heading, '/').'\n(.*?)(?=^#{2,3} |\z)/ms', $doc, $matches);

    return $matches[1] ?? '';
}

test('AC-6 BENCHMARKS.md has fair and tuned ping and search tables', function (): void {
    foreach (['### /api/ping (bootstrap-dominated)', '### /listings?make=BMW (DB-dominated)'] as $heading) {
        $section = benchmarksSection($heading);

        expect($section)->toContain('| Server | Setting | RPS | p50 ms | p95 ms | p99 ms | Errors % |')
            ->toContain('RPS vs nginx-fpm')
            ->toContain('| Notes |');

        foreach (['nginx-fpm', 'apache-fpm', 'openlitespeed', 'frankenphp'] as $server) {
            expect(preg_match_all("/^\\| {$server} \\| fair: /m", $section))->toBe(1)
                ->and(preg_match_all("/^\\| {$server} \\| tuned: /m", $section))->toBe(1);
        }
    }
});

test('AC-7 BENCHMARKS.md has the memory table', function (): void {
    $section = benchmarksSection('### Memory and CPU at 50 concurrent users (fair ping run)');

    expect($section)->toContain('| Idle MiB | Peak MiB | Avg CPU % | db idle MiB | db peak MiB |');

    foreach (['nginx-fpm', 'apache-fpm', 'openlitespeed', 'frankenphp'] as $server) {
        expect($section)->toMatch("/^\\| {$server} \\| (web|openlitespeed) \\| \\d+ \\| \\d+ \\|/m");
    }
});

test('AC-9 BENCHMARKS.md has the timeout table with defaults and short rows', function (): void {
    $section = benchmarksSection('### Timeouts: GET /debug/sleep/30');

    foreach (['nginx-fpm', 'apache-fpm', 'openlitespeed', 'frankenphp'] as $server) {
        expect($section)->toMatch("/^\\| {$server} \\| defaults \\| 200 \\|/m")
            ->toMatch("/^\\| {$server} \\| short \\| 50\\d \\|/m");
    }

    expect($section)->not->toContain('unknown');
});

test('AC-12 BENCHMARKS.md has an interview story with a measured number', function (): void {
    $story = benchmarksSection('## Interview story');
    $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', trim($story)) ?: [];

    expect(count($sentences))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(6)
        ->and($story)->toMatch('/\\d{3,} RPS/');
});

test('AC-9 BENCHMARKS.md has the leak table', function (): void {
    $doc = file_get_contents(base_path('BENCHMARKS.md'));

    preg_match('/^## State leaks\n(.*?)(?=^## |\z)/ms', $doc, $matches);
    $section = $matches[1] ?? '';

    expect($section)->toContain('bench/leaks.sh')
        ->toContain('| Scenario | Mode | nginx-fpm | frankenphp-octane (max-requests 500) | frankenphp-octane (max-requests 1) | frankenphp-worker |');

    foreach (['dealer-scope', 'price-cache', 'number-locale', 'request-at-boot'] as $scenario) {
        foreach (['leak', 'fixed'] as $mode) {
            expect(preg_match_all("/^\| $scenario \| $mode \| \d+\/20 \| \d+\/20 \| \d+\/20 \| \d+\/20 \|$/m", $section))
                ->toBe(1);
        }
    }

    expect(preg_match_all('/^\| \S+ \| fixed \| 0\/20 \| 0\/20 \| 0\/20 \| 0\/20 \|$/m', $section))->toBe(4);
    expect(preg_match_all('/^\| \S+ \| leak \| 0\/20 \|/m', $section))->toBe(4);
    expect($section)->toContain('request-at-boot');
});

test('AC-4 BENCHMARKS.md has Expectations (classic vs worker) with the five predictions', function (): void {
    $doc = file_get_contents(base_path('BENCHMARKS.md'));

    expect($doc)->toContain("\n## Expectations (classic vs worker)\n");

    preg_match('/^## Expectations \(classic vs worker\)\n(.*?)(?=^## |\z)/ms', $doc, $matches);
    $section = $matches[1] ?? '';

    expect($section)->toContain('2x')
        ->toContain('1.3x')
        ->toContain('5 KiB per 1 000')
        ->toContain('case A')
        ->toContain('case B')
        ->toContain('case C')
        ->toContain('500');
});

test('AC-8 BENCHMARKS.md records the embeddings and vector search measurements', function (): void {
    $doc = file_get_contents(base_path('BENCHMARKS.md'));

    preg_match('/^## Embeddings and vector search \(\d{4}-\d{2}-\d{2}\)\n(.*?)(?=^## |\z)/ms', $doc, $matches);
    $section = $matches[1] ?? '';

    expect($section)->toContain('bge-m3')
        ->toContain('Ollama 0.40.13')
        ->toContain('pgvector 0.8.7')
        ->toContain('| 16 |')
        ->toContain('| 64 |')
        ->toContain('| 256 |')
        ->toContain('embeddings/s')
        ->toContain('HNSW build')
        ->toContain('p50')
        ->toContain('p95')
        ->toContain('recall@10')
        ->toContain('bench/results/2026-11-02-embeddings/');

    foreach (['embed-16-2000', 'embed-64-2000', 'embed-256-2000', 'embed-64-all', 'search-bench'] as $file) {
        expect(base_path("bench/results/2026-11-02-embeddings/{$file}.json"))->toBeFile();
    }
});

test('AC-8 BENCHMARKS.md names one best batch size and the sweep files carry the Ollama version', function (): void {
    $doc = file_get_contents(base_path('BENCHMARKS.md'));

    expect(preg_match_all('/\*\*Best batch size: (\d+)\*\*/', $doc, $matches))->toBe(1)
        ->and((int) $matches[1][0])->toBe((int) config('ai.embeddings.batch'));

    foreach ([16, 64, 256] as $batch) {
        $summary = json_decode(file_get_contents(base_path("bench/results/2026-11-02-embeddings/embed-{$batch}-2000.json")), true, flags: JSON_THROW_ON_ERROR);

        expect($summary['ollama_version'])->toBe('0.40.13')
            ->and($summary)->toHaveKey('ollama_num_parallel')
            ->and(fmod($summary['seconds'], 1.0))->not->toBe(0.0);
    }
});

test('MNC-1 BENCHMARKS.md parity section records the asset fingerprint and the re-baseline', function (): void {
    $doc = file_get_contents(base_path('BENCHMARKS.md'));

    preg_match('/^## Response parity\n(.*?)(?=^## |\z)/ms', $doc, $matches);
    $parity = $matches[1] ?? '';

    expect($parity)->toContain('2026-11-03')
        ->toContain('re-baseline')
        ->toContain('bench/asset-fingerprint.sh');
    expect(preg_match_all('/^resources\/(css\/app\.css|js\/app\.js) assets\/app-[\w-]+\.(css|js) [0-9a-f]{64}$/m', $parity))
        ->toBe(2);
});

test('MNC-4 bench/asset-fingerprint.sh prints the lines recorded in BENCHMARKS.md', function (): void {
    $manifest = public_path('build/manifest.json');
    if (! is_file($manifest)) {
        $this->markTestSkipped('No Vite build to fingerprint.');
    }

    $doc = file_get_contents(base_path('BENCHMARKS.md'));
    preg_match_all('/^resources\/(?:css\/app\.css|js\/app\.js) \S+ [0-9a-f]{64}$/m', $doc, $recorded);

    $output = [];
    exec(escapeshellarg(base_path('bench/asset-fingerprint.sh')).' 2>&1', $output, $exit);

    expect($exit)->toBe(0)
        ->and($recorded[0])->toHaveCount(2)
        ->and($output)->toBe($recorded[0]);
});
