<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->bash = (new ExecutableFinder)->find('bash');
    if ($this->bash === null) {
        $this->markTestSkipped('bash is not installed');
    }

    $this->root = sys_get_temp_dir().'/bench-metrics-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app/bench', 0777, true);

    $stubs = [
        'ddev' => <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$*" in
  "server --current") echo "${STUB_CURRENT:-frankenphp}" ;;
  "server --default") echo "frankenphp" ;;
esac
BASH,
        'git' => "#!/usr/bin/env bash\necho abc1234\n",
        'docker' => <<<'BASH'
#!/usr/bin/env bash
echo "docker $*" >> "$STUB_LOG"
case "$1" in
  exec)
    if [[ "$2" != *-prometheus ]]; then
      out="${STUB_OUT:?}"
      printf 'png' > "$out/${STUB_CELL}.png"
      exit 0
    fi
    query="${*: -1}"
    case "$query" in
      *sum_over_time*) value="${STUB_COUNT:-100}" ;;
      *busy_workers*) value="${STUB_WORKERS:-3}" ;;
      *total_workers*) value="4" ;;
      *busy_threads*) value="${STUB_BUSY:-12}" ;;
      *queue_depth*) value="7" ;;
      *process_resident_memory_bytes*) value="104857600" ;;
      *histogram_quantile*) value="${STUB_P95:-0.4125}" ;;
      *) value="NaN" ;;
    esac
    echo "{\"status\":\"success\",\"data\":{\"resultType\":\"vector\",\"result\":[{\"metric\":{},\"value\":[1763300000,\"$value\"]}]}}"
    ;;
  run)
    for arg in "$@"; do
      case "$arg" in *:/out) out="${arg%:/out}" ;; esac
    done
    [[ -n "${out:-}" ]] && printf 'png' > "$out/${STUB_CELL}.png"
    ;;
esac
BASH,
    ];
    foreach ($stubs as $name => $script) {
        file_put_contents($this->root.'/bin/'.$name, $script);
        chmod($this->root.'/bin/'.$name, 0755);
    }

    $this->run = function (string $script, array $args, array $env = []): Process {
        $process = new Process(
            [$this->bash, base_path('bench/'.$script), ...$args],
            $this->root,
            $env + [
                'BENCH_APPROOT' => $this->root.'/app',
                'BENCH_DATE' => '2026-11-16',
                'DDEV_SITENAME' => 'ai-lab',
                'STUB_LOG' => $this->root.'/calls.log',
                'STUB_CELL' => 'http-frankenphp-14',
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
            ],
        );
        $process->run();

        return $process;
    };
    $this->json = fn (string $cell): string => $this->root.'/app/bench/results/metrics/2026-11-16/'.$cell.'.json';
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-4 the snapshot, matrix and screenshot scripts parse', function (): void {
    foreach (['metrics-snapshot.sh', 'metrics-matrix.sh'] as $script) {
        $process = new Process([$this->bash, '-n', base_path('bench/'.$script)]);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    }

    expect(base_path('bench/metrics-screenshot.mjs'))->toBeFile();
});

it('AC-4 --help prints the usage and runs nothing', function (string $script): void {
    $process = ($this->run)($script, ['--help']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('Usage: bench/'.$script)
        ->and(file_exists($this->root.'/calls.log'))->toBeFalse();
})->with(['metrics-snapshot.sh', 'metrics-matrix.sh']);

it('AC-4 the snapshot writes the JSON with max busy threads, max queue depth, max memory and p95 over the window', function (): void {
    $process = ($this->run)('metrics-snapshot.sh', ['http-frankenphp-14', '1763299000000', '1763299100000', '--no-screenshot']);
    $calls = file_get_contents($this->root.'/calls.log');
    $result = json_decode(file_get_contents(($this->json)('http-frankenphp-14')), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result)->toMatchArray([
            'cell' => 'http-frankenphp-14', 'from_ms' => 1763299000000, 'to_ms' => 1763299100000, 'window_s' => 100,
            'server' => 'frankenphp', 'commit' => 'abc1234',
        ])
        ->and($result['metrics'])->toMatchArray([
            'max_busy_threads' => 12, 'max_busy_workers' => 3, 'total_workers' => 4, 'max_queue_depth' => 7, 'max_memory_bytes' => 104857600, 'p95_seconds' => 0.4125, 'p95_capped' => false,
        ])
        ->and($result['scrape_coverage'])->toBe(1)
        ->and($calls)->toContain('ddev-ai-lab-prometheus', 'max_over_time', '%5B100s%5D', 'time=1763299100.000');
});

it('AC-4 the snapshot also writes the PNG through the pinned browser container on ddev_default', function (): void {
    $process = ($this->run)('metrics-snapshot.sh', ['http-frankenphp-14', '1763299000000', '1763299100000']);
    $calls = file_get_contents($this->root.'/calls.log');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(($this->json)('http-frankenphp-14'))->toBeFile()
        ->and(dirname(($this->json)('http-frankenphp-14')).'/http-frankenphp-14.png')->toBeFile()
        ->and($calls)->toContain('--network ddev_default', 'ddev-ai-lab-grafana:3000/d/frankenphp', 'from=1763299000000', 'to=1763299100000', 'kiosk');
});

it('AC-4 the browser container image is pinned by digest', function (): void {
    $process = ($this->run)('metrics-snapshot.sh', ['http-frankenphp-14', '1763299000000', '1763299100000']);
    $dockerRun = collect(explode("\n", file_get_contents($this->root.'/calls.log')))->first(fn (string $line): bool => str_starts_with($line, 'docker run'));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($dockerRun)->toMatch('/\S+@sha256:[0-9a-f]{64}\s/');
});

it('AC-4 the snapshot refuses a bad cell or window and does not overwrite without --force', function (): void {
    $bad = ($this->run)('metrics-snapshot.sh', ['Bad Cell', '1', '2', '--no-screenshot']);
    $reversed = ($this->run)('metrics-snapshot.sh', ['cell', '2000', '1000', '--no-screenshot']);
    $first = ($this->run)('metrics-snapshot.sh', ['cell-a', '1000', '2000', '--no-screenshot']);
    $again = ($this->run)('metrics-snapshot.sh', ['cell-a', '1000', '2000', '--no-screenshot']);
    $forced = ($this->run)('metrics-snapshot.sh', ['cell-a', '1000', '2000', '--no-screenshot', '--force']);

    expect($bad->getExitCode())->toBe(2)
        ->and($reversed->getExitCode())->toBe(2)
        ->and($first->getExitCode())->toBe(0, $first->getErrorOutput())
        ->and($again->getExitCode())->toBe(2)
        ->and($forced->getExitCode())->toBe(0, $forced->getErrorOutput());
});

it('AC-4 the matrix dry-run lists the 8 cells and the three stack-off controls through slow-load.sh and the snapshot', function (): void {
    $process = ($this->run)('metrics-matrix.sh', ['--dry-run']);
    $output = $process->getOutput();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(substr_count($output, 'slow-load.sh --workload'))->toBe(11)
        ->and(substr_count($output, 'metrics-snapshot.sh'))->toBe(8)
        ->and($output)->toContain(
            '--label frankenphp-14', '--label frankenphp-auto', '--label frankenphp-worker-4', '--label frankenphp-octane-4',
            'autoscale --threads 14 --max-threads 64', 'ddev metrics off', '--label frankenphp-14-nostack', '--label frankenphp-worker-4-nostack', '--label frankenphp-octane-4-nostack',
        );
});

it('AC-4 the matrix restores the server it started on, not the default one', function (): void {
    $process = ($this->run)('metrics-matrix.sh', ['--dry-run'], ['STUB_CURRENT' => 'frankenphp-worker']);
    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(end($lines))->toBe('ddev server frankenphp-worker');
});

it('AC-4 the matrix restores the default server when the current one cannot be read', function (): void {
    $process = ($this->run)('metrics-matrix.sh', ['--dry-run'], ['STUB_CURRENT' => 'nginx-fpm']);
    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(end($lines))->toBe('ddev server frankenphp');
});

it('AC-4 MNC-3 the matrix passes the metrics folder only to the snapshot and never exports BENCH_OUT_DIR', function (): void {
    $process = ($this->run)('metrics-matrix.sh', ['--dry-run']);
    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
    $slowLoad = array_filter($lines, fn (string $line): bool => str_contains($line, 'slow-load.sh'));
    $snapshot = array_filter($lines, fn (string $line): bool => str_contains($line, 'metrics-snapshot.sh'));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($slowLoad)->toHaveCount(11)
        ->and($snapshot)->toHaveCount(8)
        ->and(implode("\n", $slowLoad))->not->toContain('bench/results/metrics/')->not->toContain('BENCH_OUT_DIR')
        ->and(implode("\n", $snapshot))->toContain('BENCH_OUT_DIR=')->toContain('bench/results/metrics/')
        ->and(file_get_contents(base_path('bench/metrics-matrix.sh')))->not->toContain('export BENCH_OUT_DIR');
});

it('AC-4 AC-3 the snapshot fails and writes no JSON when the window was scraped below 0.8', function (): void {
    $process = ($this->run)('metrics-snapshot.sh', ['http-frankenphp-14', '1763299000000', '1763299100000', '--no-screenshot'], ['STUB_COUNT' => '50']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('scrape coverage')
        ->and(($this->json)('http-frankenphp-14'))->not->toBeFile();
});

it('AC-4 AC-3 the snapshot fails and writes no JSON when max busy threads is null', function (): void {
    $process = ($this->run)('metrics-snapshot.sh', ['http-frankenphp-14', '1763299000000', '1763299100000', '--no-screenshot'], ['STUB_BUSY' => 'NaN']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('max_busy_threads')
        ->and(($this->json)('http-frankenphp-14'))->not->toBeFile();
});

it('AC-4 AC-3 the matrix dry-run starts and stops a keep-alive around every measured slow-load.sh call', function (): void {
    $process = ($this->run)('metrics-matrix.sh', ['--dry-run']);
    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
    $slowLoad = array_keys(array_filter($lines, fn (string $line): bool => str_contains($line, 'slow-load.sh')));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    foreach (array_slice($slowLoad, 0, 8) as $index) {
        expect($lines[$index - 1])->toContain('keep-alive start')
            ->and($lines[$index + 1])->toContain('keep-alive stop');
    }
    expect(substr_count($process->getOutput(), 'keep-alive start'))->toBe(8)
        ->and($lines[$slowLoad[8] - 1])->not->toContain('keep-alive');
});

it('AC-4 a p95 at the top Caddy bucket is written as above the bucket cap, not as a number', function (): void {
    $process = ($this->run)('metrics-snapshot.sh', ['http-frankenphp-14', '1763299000000', '1763299100000', '--no-screenshot'], ['STUB_P95' => '10']);
    $result = json_decode(file_get_contents(($this->json)('http-frankenphp-14')), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result['metrics']['p95_seconds'])->toBe('>10 (bucket cap)')
        ->and($result['metrics']['p95_capped'])->toBeTrue();
});

it('AC-4 a p95 just below the bucket cap stays a number', function (): void {
    ($this->run)('metrics-snapshot.sh', ['http-frankenphp-14', '1763299000000', '1763299100000', '--no-screenshot'], ['STUB_P95' => '9.75']);
    $result = json_decode(file_get_contents(($this->json)('http-frankenphp-14')), true);

    expect($result['metrics']['p95_seconds'])->toBe(9.75)
        ->and($result['metrics']['p95_capped'])->toBeFalse();
});

it('AC-4 the load window narrows the numbers but not the screenshot', function (): void {
    $process = ($this->run)('metrics-snapshot.sh', ['http-frankenphp-14', '1763299000000', '1763299100000', '--load-window', '1763299020000-1763299080000'], ['STUB_COUNT' => '60']);
    $calls = file_get_contents($this->root.'/calls.log');
    $result = json_decode(file_get_contents(($this->json)('http-frankenphp-14')), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result)->toMatchArray(['window_s' => 100, 'load_from_ms' => 1763299020000, 'load_to_ms' => 1763299080000, 'load_s' => 60, 'scrape_coverage' => 1])
        ->and($calls)->toContain('%5B60s%5D', 'time=1763299080.000', 'from=1763299000000', 'to=1763299100000')
        ->and($calls)->not->toContain('%5B100s%5D');
});

it('AC-4 the snapshot refuses a malformed or reversed load window', function (string $window): void {
    $process = ($this->run)('metrics-snapshot.sh', ['cell', '1000', '2000', '--load-window', $window, '--no-screenshot']);

    expect($process->getExitCode())->toBe(2);
})->with(['abc', '2000-1000', '']);

it('AC-4 the snapshot reuses a running Playwright container when one is given', function (): void {
    $out = $this->root.'/app/bench/results/metrics/2026-11-16';
    mkdir($out, 0777, true);
    $process = ($this->run)('metrics-snapshot.sh', ['http-frankenphp-14', '1763299000000', '1763299100000'], ['PLAYWRIGHT_CONTAINER' => 'pw-1', 'STUB_OUT' => $out]);
    $calls = file_get_contents($this->root.'/calls.log');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($out.'/http-frankenphp-14.png')->toBeFile()
        ->and($calls)->toContain('docker exec pw-1 bash -c', 'refresh=&kiosk')
        ->and($calls)->not->toContain('docker run')
        ->and($calls)->not->toContain('npm install');
});

it('AC-4 scrape coverage counts successful scrapes and the request queries pick one handler per mode', function (): void {
    ($this->run)('metrics-snapshot.sh', ['http-frankenphp-14', '1763299000000', '1763299100000', '--no-screenshot']);
    $calls = file_get_contents($this->root.'/calls.log');

    expect($calls)->toContain('sum_over_time')->not->toContain('count_over_time')
        ->and(urldecode($calls))->toContain('handler=~"php|vars|subroute"');
});

it('AC-4 the matrix --quick run overwrites its scratch results without --force', function (): void {
    $raw = $this->root.'/app/bench/results/raw';
    mkdir($raw.'/metrics/2026-11-16', 0777, true);
    file_put_contents($raw.'/http-frankenphp-14.json', '{}');
    file_put_contents($raw.'/metrics/2026-11-16/http-frankenphp-14.json', '{}');

    $process = ($this->run)('metrics-matrix.sh', ['--quick', '--dry-run']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('--quick')
        ->and($process->getOutput())->toContain('--force');
});

it('AC-4 a full matrix run refuses to overwrite a cell of the same day without --force', function (): void {
    $results = $this->root.'/app/bench/results/slow-load/2026-11-16';
    mkdir($results, 0777, true);
    file_put_contents($results.'/http-fixcheck.json', '{}');
    file_put_contents($results.'/sse-frankenphp-worker-4.json', '{}');

    $refused = ($this->run)('metrics-matrix.sh', ['--dry-run']);
    $forced = ($this->run)('metrics-matrix.sh', ['--dry-run', '--force']);

    expect($refused->getExitCode())->toBe(2)
        ->and($refused->getErrorOutput())->toContain('sse-frankenphp-worker-4.json already exists')
        ->and($refused->getErrorOutput())->not->toContain('fixcheck')
        ->and($forced->getExitCode())->toBe(0, $forced->getErrorOutput());
});

it('AC-4 the matrix starts one Playwright container per run and passes it and the load window to every snapshot', function (): void {
    $process = ($this->run)('metrics-matrix.sh', ['--dry-run']);
    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
    $snapshot = array_values(array_filter($lines, fn (string $line): bool => str_contains($line, 'metrics-snapshot.sh')));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'playwright_start'))))->toHaveCount(1)
        ->and(array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'playwright_stop'))))->toHaveCount(1)
        ->and($snapshot)->toHaveCount(8);

    foreach ($snapshot as $line) {
        expect($line)->toContain('PLAYWRIGHT_CONTAINER=bench-metrics-playwright-', '--load-window LOAD_FROM-LOAD_TO');
    }
});

it('AC-4 the keep-alive recreates only the two metrics containers, one start at a time, and is waited for when it stops', function (): void {
    $script = file_get_contents(base_path('bench/metrics-matrix.sh'));
    $loop = substr($script, strpos($script, 'watch_loop() {'), strpos($script, 'watch_start() {') - strpos($script, 'watch_loop() {'));

    expect($loop)->toContain('up -d --no-deps prometheus grafana')
        ->and($loop)->not->toContain('ddev ')
        ->and($loop)->not->toMatch('/&\s*$/m')
        ->and($script)->toContain('rm -f "$WATCH_DIR/run"'."\n".'    wait "$WATCH_PID"');
});
