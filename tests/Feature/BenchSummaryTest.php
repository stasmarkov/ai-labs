<?php

declare(strict_types=1);

require_once __DIR__.'/../../bench/summary.php';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function benchRun(float $rps, int $set = 1, array $overrides = []): array
{
    return $overrides + [
        'server' => 'nginx-fpm',
        'scenario' => 'ping',
        'label' => 'fair',
        'concurrency' => 'default',
        'rps' => $rps,
        'p50_ms' => 10.0,
        'p95_ms' => 20.0,
        'p99_ms' => 30.0,
        'error_percent' => 0.0,
        'peak_memory_mib' => ['web' => 120.5, 'db' => 300.0],
        'avg_cpu_percent' => ['web' => 42.0, 'db' => 10.0],
        'set' => $set,
        'repeat' => 1,
    ];
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bench-summary-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function (): void {
    array_map('unlink', glob($this->dir.'/*.json') ?: []);
    rmdir($this->dir);
});

it('AC-2 picks the median run by rps', function (): void {
    $summary = benchSummarize([benchRun(1000), benchRun(1040, overrides: ['p50_ms' => 11.0]), benchRun(1020)]);

    expect($summary['median']['rps'])->toEqual(1020)
        ->and($summary['runs'])->toBe(3);

    $summary = benchSummarize([benchRun(1040), benchRun(1000), benchRun(1020, overrides: ['p50_ms' => 12.0])]);

    expect($summary['median']['p50_ms'])->toEqual(12.0);
});

it('AC-2 computes spread as max minus min over the median', function (): void {
    $summary = benchSummarize([benchRun(980), benchRun(1000), benchRun(1020)]);

    expect($summary['spread_percent'])->toEqualWithDelta(4.0, 0.0001)
        ->and($summary['noisy'])->toBeFalse()
        ->and($summary['rerun_needed'])->toBeFalse();
});

it('AC-2 spread above 5 percent triggers one rerun then marks noisy', function (): void {
    $firstSet = [benchRun(900), benchRun(1000), benchRun(1100)];

    $afterFirst = benchSummarize($firstSet);
    expect($afterFirst['spread_percent'])->toEqualWithDelta(20.0, 0.0001)
        ->and($afterFirst['rerun_needed'])->toBeTrue()
        ->and($afterFirst['noisy'])->toBeFalse();

    $secondQuiet = benchSummarize([...$firstSet, benchRun(995, 2), benchRun(1000, 2), benchRun(1005, 2)]);
    expect($secondQuiet['rerun_needed'])->toBeFalse()
        ->and($secondQuiet['noisy'])->toBeFalse()
        ->and($secondQuiet['median']['rps'])->toEqual(1000)
        ->and($secondQuiet['runs'])->toBe(3);

    $secondNoisy = benchSummarize([...$firstSet, benchRun(800, 2), benchRun(1000, 2), benchRun(1200, 2)]);
    expect($secondNoisy['rerun_needed'])->toBeFalse()
        ->and($secondNoisy['noisy'])->toBeTrue()
        ->and($secondNoisy['spread_percent'])->toEqualWithDelta(40.0, 0.0001);
});

it('AC-2 ignores runs marked discarded', function (): void {
    $summary = benchSummarize([
        benchRun(900, overrides: ['discarded' => true]),
        benchRun(1000, overrides: ['discarded' => true]),
        benchRun(1100, overrides: ['discarded' => true]),
        benchRun(995, 2), benchRun(1000, 2), benchRun(1005, 2),
    ]);

    expect($summary['runs'])->toBe(3)
        ->and($summary['noisy'])->toBeFalse();
});

it('AC-6 ratio is relative to the nginx-fpm baseline and marked approximate when it is noisy', function (): void {
    $baseline = benchSummarize([benchRun(1000), benchRun(1000), benchRun(1000)]);
    $frankenphp = ['server' => 'frankenphp'];
    $other = benchSummarize([benchRun(1500, overrides: $frankenphp), benchRun(1500, overrides: $frankenphp), benchRun(1500, overrides: $frankenphp)]);

    expect(benchRatio($other, $baseline))->toBe('1.50x')
        ->and(benchRatio($baseline, $baseline))->toBe('1.00x');

    $noisyBaseline = benchSummarize([benchRun(800, 2), benchRun(1000, 2), benchRun(1200, 2)]);

    expect(benchRatio($other, $noisyBaseline))->toBe('~1.50x')
        ->and(benchRatio($other, null))->toBe('n/a');
});

it('AC-2 prints a BENCHMARKS.md table row', function (): void {
    $baseline = benchSummarize([benchRun(1000), benchRun(1000), benchRun(1000)]);
    $frankenphp = ['server' => 'frankenphp', 'concurrency' => '8 workers'];
    $summary = benchSummarize([benchRun(1500, overrides: $frankenphp), benchRun(1480, overrides: $frankenphp), benchRun(1520, overrides: $frankenphp)]);

    expect(benchRow($summary, $baseline))
        ->toBe('| frankenphp | 8 workers | 1500 | 10.0 | 20.0 | 30.0 | 0.00 | 121 | 42 | 2.7% | 1.50x |  |');

    $noisy = benchSummarize([benchRun(800, 2), benchRun(1000, 2), benchRun(1200, 2)]);

    expect(benchRow($noisy, $baseline))->toContain('40.0% noisy');
});

it('AC-6 AC-7 row uses the serving container and ends with a notes cell', function (): void {
    $run = benchRun(900, overrides: [
        'server' => 'openlitespeed',
        'idle_memory_mib' => ['web' => 15.0, 'openlitespeed' => 60.0],
        'peak_memory_mib' => ['web' => 120.5, 'openlitespeed' => 210.0],
        'avg_cpu_percent' => ['web' => 42.0, 'openlitespeed' => 77.0],
    ]);
    $summary = benchSummarize([$run, $run, $run]);
    $row = benchRow($summary, null);

    expect($row)->toContain('| 210 | 77 |')
        ->and($row)->not->toContain('| 121 |')
        ->and($row)->toEndWith('| n/a |  |');

    expect(benchMemory($summary))->toBe([
        'serving' => 'openlitespeed',
        'idle' => ['web' => 15.0, 'openlitespeed' => 60.0],
        'peak' => ['web' => 120.5, 'openlitespeed' => 210.0],
    ]);
});

it('AC-2 reads result files from a directory and prints rows from the command line', function (): void {
    foreach ([[1, 1000], [2, 1010], [3, 990]] as [$repeat, $rps]) {
        file_put_contents(
            $this->dir.'/ping-fair-run'.$repeat.'.json',
            json_encode(benchRun($rps, overrides: ['repeat' => $repeat])),
        );
    }

    $summary = benchSummarizeFiles(glob($this->dir.'/*.json'));
    expect($summary['median']['rps'])->toEqual(1000);

    $output = shell_exec('php '.escapeshellarg(base_path('bench/summary.php')).' '.escapeshellarg($this->dir).' 2>&1');
    expect($output)->toContain('| nginx-fpm | default | 1000 |');
});

it('AC-1 AC-2 AC-6 writes the committed summary json with every field', function (): void {
    $runs = [
        benchRun(1000, overrides: ['commit' => 'abc1234', 'image' => 'oha:1', 'image_digest' => 'sha256:aa', 'date' => '2026-10-30', 'repeat' => 1]),
        benchRun(1040, overrides: ['p50_ms' => 11.0, 'repeat' => 2, 'commit' => 'abc1234', 'image' => 'oha:1', 'image_digest' => 'sha256:aa', 'date' => '2026-10-30']),
        benchRun(1020, overrides: ['repeat' => 3, 'commit' => 'abc1234', 'image' => 'oha:1', 'image_digest' => 'sha256:aa', 'date' => '2026-10-30']),
    ];
    foreach ($runs as $index => $run) {
        file_put_contents($this->dir.'/run'.$index.'.json', json_encode($run));
    }
    $out = $this->dir.'/results/2026-10-30/nginx-fpm-ping-fair.json';

    shell_exec('php '.escapeshellarg(base_path('bench/summary.php')).' '.escapeshellarg($this->dir.'/run0.json').' '.escapeshellarg($this->dir.'/run1.json').' '.escapeshellarg($this->dir.'/run2.json').' --write='.escapeshellarg($out).' 2>&1');

    $doc = json_decode((string) file_get_contents($out), true);

    expect($doc)->toHaveKeys(['server', 'scenario', 'label', 'setting', 'commit', 'image', 'image_digest', 'runs', 'median', 'spread_percent', 'noisy', 'ratio'])
        ->and($doc['server'])->toBe('nginx-fpm')
        ->and($doc['scenario'])->toBe('ping')
        ->and($doc['setting'])->toBe('default')
        ->and($doc['commit'])->toBe('abc1234')
        ->and($doc['image_digest'])->toBe('sha256:aa')
        ->and($doc['runs'])->toHaveCount(3)
        ->and($doc['runs'][0])->toHaveKeys(['rps', 'p50_ms', 'p95_ms', 'p99_ms', 'error_percent', 'peak_memory_mib', 'avg_cpu_percent'])
        ->and($doc['median']['rps'])->toEqual(1020)
        ->and($doc['spread_percent'])->toEqualWithDelta(3.9216, 0.001)
        ->and($doc['noisy'])->toBeFalse()
        ->and($doc['ratio'])->toBe('1.00x');

    $other = $this->dir.'/other.json';
    file_put_contents($other, json_encode(benchRun(1530, overrides: ['server' => 'frankenphp'])));
    $otherOut = $this->dir.'/results/2026-10-30/frankenphp-ping-fair.json';
    shell_exec('php '.escapeshellarg(base_path('bench/summary.php')).' '.escapeshellarg($other).' --baseline='.escapeshellarg($out).' --write='.escapeshellarg($otherOut).' 2>&1');

    expect(json_decode((string) file_get_contents($otherOut), true))
        ->toMatchArray(['baseline_server' => 'nginx-fpm', 'ratio' => '1.50x']);

    array_map('unlink', glob($this->dir.'/results/2026-10-30/*.json'));
    rmdir($this->dir.'/results/2026-10-30');
    rmdir($this->dir.'/results');
});

it('AC-1 suite.sh writes summaries outside raw after every fair and tuned run', function (): void {
    $suite = file_get_contents(base_path('bench/suite.sh'));

    expect($suite)->toContain('summary.php')
        ->and($suite)->toContain('write_summary "$marker" fair')
        ->and($suite)->toContain('write_summary "$marker" tuned')
        ->and($suite)->toContain('--write=')
        ->and($suite)->toContain('bench/results/$BENCH_DATE/$server-$scenario-$label.json')
        ->and($suite)->not->toContain('results/raw/$date/$server-$scenario');
});

it('AC-2 refuses to merge runs from different server, scenario or label groups', function (): void {
    benchSummarize([benchRun(100), benchRun(101, overrides: ['scenario' => 'search'])]);
})->throws(InvalidArgumentException::class, 'different server/scenario/label groups');

it('AC-6 AC-7 tables render fair and tuned rows, the memory table and the timeout table', function (): void {
    $dir = sys_get_temp_dir().'/bench-tables-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $document = function (string $server, string $label, float $rps) {
        $summary = benchSummarize([benchRun($rps, overrides: ['server' => $server, 'label' => $label,
            'idle_memory_mib' => ['web' => 80.0, 'db' => 200.0], 'concurrency' => 'workers=8'])]);

        return benchSummaryDocument($summary, null);
    };
    file_put_contents("$dir/nginx-fpm-ping-fair.json", json_encode($document('nginx-fpm', 'fair', 1000)));
    file_put_contents("$dir/nginx-fpm-ping-tuned.json", json_encode($document('nginx-fpm', 'tuned', 1200)));
    file_put_contents("$dir/frankenphp-ping-fair.json", json_encode($document('frankenphp', 'fair', 900)));
    file_put_contents("$dir/timeouts-nginx-fpm.json", json_encode(['runs' => [
        ['label' => 'short', 'request' => 1, 'status' => 504, 'seconds' => 10.02, 'ending_layer' => 'nginx fastcgi_read_timeout'],
    ]]));

    $tables = benchTables($dir);

    expect($tables)->toContain('| nginx-fpm | fair: workers=8 | 1000 |')
        ->toContain('| nginx-fpm | tuned: workers=8 | 1200 |')
        ->toContain('| frankenphp | fair: workers=8 | 900 |')
        ->toContain('### Memory and CPU at 50 concurrent users')
        ->toContain('| nginx-fpm | web | 80 | 121 | 42 | 200 | 300 | 10 | — |')
        ->toContain('| nginx-fpm | short | 504 | 10.0 | nginx fastcgi_read_timeout |');

    array_map('unlink', glob("$dir/*.json"));
    rmdir($dir);
});

it('AC-2 tables take the server list from the summaries present and use frankenphp as the baseline column', function (): void {
    $dir = sys_get_temp_dir().'/bench-tables-'.bin2hex(random_bytes(4));
    mkdir($dir);
    foreach (['frankenphp-worker' => 2000, 'frankenphp' => 1000, 'frankenphp-octane' => 1500] as $server => $rps) {
        $summary = benchSummarize([benchRun($rps, overrides: ['server' => $server, 'label' => 'fair', 'concurrency' => 'workers=8'])]);
        file_put_contents("$dir/$server-ping-fair.json", json_encode(benchSummaryDocument($summary, null)));
    }

    $tables = benchTables($dir);

    expect($tables)->toContain('RPS vs frankenphp')
        ->not->toContain('nginx-fpm')
        ->not->toContain('apache-fpm')
        ->and(strpos($tables, '| frankenphp |'))->toBeLessThan(strpos($tables, '| frankenphp-octane |'))
        ->and(strpos($tables, '| frankenphp-octane |'))->toBeLessThan(strpos($tables, '| frankenphp-worker |'));

    array_map('unlink', glob("$dir/*.json"));
    rmdir($dir);
});

it('AC-6 tables render the worker memory table from memory files', function (): void {
    $dir = sys_get_temp_dir().'/bench-tables-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $samples = fn (int $base, int $step): array => array_map(
        fn (int $requests): array => ['requests' => $requests, 'memory_bytes' => $base + $requests * $step, 'worker_hits' => 1, 'first_seen_at' => 'x'],
        [0, 1000, 5000, 10000],
    );
    file_put_contents("$dir/memory-frankenphp-worker.json", json_encode([
        'server' => 'frankenphp-worker', 'commit' => 'abc', 'date' => '2030-01-01',
        'cases' => [
            ['case' => 'A', 'mode' => 'leak', 'workers' => 1, 'max_requests' => 1000000, 'samples' => $samples(2000000, 1024), 'restarts' => 0, 'growth_kib_per_1000' => 1000.0],
            ['case' => 'C', 'mode' => 'leak', 'workers' => 1, 'max_requests' => 500, 'samples' => array_slice($samples(2000000, 10), 0, 2), 'restarts' => 19, 'growth_kib_per_1000' => 9.77],
        ],
    ]));

    $tables = benchTables($dir);

    expect($tables)->toContain('### Worker memory growth')
        ->toContain('| frankenphp-worker | A | leak | 1000000 | 2000000 | 3024000 | 7120000 | 12240000 | 0 | 12240000 | 1000.00 |')
        ->toContain('| frankenphp-worker | C | leak | 500 | 2000000 | 2010000 | — | — | 19 | 2010000 | 9.77 |');

    array_map('unlink', glob("$dir/*.json"));
    rmdir($dir);
});
