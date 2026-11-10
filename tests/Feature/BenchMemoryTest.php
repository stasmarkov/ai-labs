<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bench-memory-'.bin2hex(random_bytes(4));
    mkdir($this->dir.'/bin', 0777, true);
    mkdir($this->dir.'/bench', 0777, true);
    $this->envBefore = "APP_ENV=local\nAPP_DEBUG=true\nDEBUG_ROUTES=false\n";
    file_put_contents($this->dir.'/.env', $this->envBefore);
    touch($this->dir.'/log');

    copy(base_path('bench/memory.sh'), $this->dir.'/bench/memory.sh');

    $stub = function (string $path, string $body): void {
        file_put_contents($path, "#!/usr/bin/env bash\n".$body);
        chmod($path, 0755);
    };

    $stub($this->dir.'/bin/ddev', <<<'SH'
echo "ddev $*" >> "$BENCH_STUB_DIR/log"
if [[ "$*" == "server --current" ]]; then echo "$STUB_SERVER"; fi
if [[ "$*" == "server --url" ]]; then echo "https://ai-lab.ddev.site"; fi
if [[ -n "${STUB_NO_VIEWS-}" && "$*" == *storage/framework/views* ]]; then exit 1; fi
if [[ "$*" == "artisan config:show app.env" ]]; then echo "  app.env ... production"; fi
if [[ "$*" == "artisan config:show app.debug" ]]; then echo "  app.debug ... false"; fi
exit 0
SH);

    // A restart (any concurrency.sh call) gives the worker a fresh process: counters back to zero.
    $stub($this->dir.'/bench/concurrency.sh', <<<'SH'
d="$BENCH_STUB_DIR"
echo "concurrency $*" >> "$d/log"
max=1000000
[[ "${2:-}" == "--max-requests" ]] && max="$3"
echo "$max" > "$d/max"
echo "$(( $(cat "$d/started" 2>/dev/null || echo 0) + 1 ))" > "$d/started"
echo 0 > "$d/served"; echo 0 > "$d/hits"; echo 0 > "$d/leaked"; rm -f "$d/warm"
SH);

    // One worker thread: every request counts toward max-requests; past it the worker restarts.
    // Classic FrankenPHP keeps nothing between requests: hits stay 1, first_seen_at changes, memory is flat.
    $stub($this->dir.'/bin/curl', <<<'SH'
out=/dev/null; url=
while [[ $# -gt 0 ]]; do
  case "$1" in
    -o) out="$2"; shift 2 ;;
    --max-time | -w | -H) shift 2 ;;
    -*) shift ;;
    *) url="$1"; shift ;;
  esac
done
d="$BENCH_STUB_DIR"
if [[ -n "${STUB_STATUS-}" ]]; then echo '{}' > "$out"; echo "$STUB_STATUS"; exit 0; fi
if [[ "$url" == */debug/runtime ]]; then
  echo '{"data":{"opcache_enabled":true,"xdebug_loaded":false}}' > "$out"; echo 200; exit 0
fi
max=$(cat "$d/max" 2>/dev/null || echo 1000000)
served=$(( $(cat "$d/served") + 1 )); hits=$(cat "$d/hits"); leaked=$(cat "$d/leaked"); started=$(cat "$d/started")
if (( served > max )); then
  served=1; hits=0; leaked=0; started=$(( started + 1 )); echo "$started" > "$d/started"
fi
if [[ "$url" == */debug/leaks/price-cache* ]]; then
  if [[ -n "${STUB_LAB_STATUS-}" ]]; then echo '{}' > "$out"; echo "$STUB_LAB_STATUS"; exit 0; fi
  echo "env: $(grep -h 'APP_ENV\|APP_DEBUG\|DEBUG_ROUTES' "$BENCH_APPROOT/.env" | tr '\n' ' ')" >> "$d/log"
  mode="${url#*mode=}"
  echo "${url##*amount=} ${mode%%&*}" >> "$d/urls"
  touch "$d/warm"
  [[ "$url" == *mode=leak* && "$STUB_SERVER" != "frankenphp" ]] && leaked=$(( leaked + 1 ))
  echo '{"success":true}' > "$out"
else
  hits=$(( hits + 1 ))
  seen="2030-01-01T00:00:0${started}Z"
  if [[ "$STUB_SERVER" == "frankenphp" ]]; then
    hits=1; seen="2030-01-01T00:00:$served"
    [[ "$out" != "/dev/null" ]] && echo "$served" > "$d/seen"
  fi
  # Garbage only shows before collection: with ?gc=1 memory_bytes is what stays after collecting it.
  before=$(( 2000000 + leaked * 1024 + served % 7 * 4096 )); memory=$before
  [[ "$url" == *gc=1* ]] && memory=$(( 2000000 + leaked * 1024 ))
  # STUB_WARMUP: a one-off step once the lab has served its first request (its classes are loaded).
  [[ -n "${STUB_WARMUP-}" && -f "$d/warm" ]] && memory=$(( memory + STUB_WARMUP )) && before=$(( before + STUB_WARMUP ))
  echo "{\"data\":{\"memory_bytes\":$memory,\"memory_bytes_before_gc\":$before,\"worker_hits\":$hits,\"first_seen_at\":\"$seen\"}}" > "$out"
fi
echo "$served" > "$d/served"; echo "$hits" > "$d/hits"; echo "$leaked" > "$d/leaked"
echo 200
SH);

    $this->memory = function (string $server, array $arguments = [], array $env = []) {
        $process = new Process(['bash', $this->dir.'/bench/memory.sh', ...$arguments], null, [
            'PATH' => $this->dir.'/bin:'.getenv('PATH'),
            'BENCH_STUB_DIR' => $this->dir,
            'BENCH_APPROOT' => $this->dir,
            'BENCH_DATE' => '2030-01-01',
            'BENCH_SETTLE' => '0',
            'STUB_SERVER' => $server,
            ...$env,
        ]);
        $process->run();

        return $process;
    };
    $this->log = fn (): array => array_values(array_filter(explode("\n", (string) file_get_contents($this->dir.'/log'))));
    $this->result = fn (string $server): array => json_decode((string) file_get_contents($this->dir.'/bench/results/2030-01-01/memory-'.$server.'.json'), true, flags: JSON_THROW_ON_ERROR);
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->dir]))->run();
});

it('AC-3 memory run samples every 100 requests across its cases and restores .env', function (): void {
    $process = ($this->memory)('frankenphp-worker', ['--requests', '1000']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $result = ($this->result)('frankenphp-worker');
    expect($result['server'])->toBe('frankenphp-worker')
        ->and($result['date'])->toBe('2030-01-01')
        ->and($result)->toHaveKey('commit')
        ->and(array_column($result['cases'], 'case'))->toBe(['A', 'B', 'C'])
        ->and(array_column($result['cases'], 'mode'))->toBe(['leak', 'fixed', 'leak'])
        ->and(array_column($result['cases'], 'workers'))->toBe([1, 1, 1])
        ->and(array_column($result['cases'], 'max_requests'))->toBe([1000000, 1000000, 500]);

    [$a, $b, $c] = $result['cases'];
    expect(array_column($a['samples'], 'requests'))->toBe(range(0, 1000, 100))
        ->and($a['samples'][0])->toHaveKeys(['requests', 'memory_bytes', 'memory_bytes_before_gc', 'worker_hits', 'first_seen_at'])
        ->and($a['samples'][3]['memory_bytes_before_gc'])->toBeGreaterThan($a['samples'][3]['memory_bytes'])
        ->and($a['restarts'])->toBe(0)
        ->and($a['growth_kib_per_1000'])->toEqualWithDelta(1000.0, 5.0)
        ->and($b['restarts'])->toBe(0)
        ->and($b['growth_kib_per_1000'])->toEqualWithDelta(0.0, 1.0)
        ->and($c['restarts'])->toBe(2)
        ->and($c['growth_kib_per_1000'])->toEqualWithDelta(1000.0, 50.0);

    $urls = array_values(array_filter(explode("\n", (string) file_get_contents($this->dir.'/urls'))));
    expect($urls[0])->toBe('1000000 leak')
        ->and($urls[999])->toBe('1000999 leak')
        ->and(count($urls))->toBe(3000);

    $log = ($this->log)();
    $calls = array_values(array_filter($log, fn (string $line): bool => str_starts_with($line, 'concurrency ')));
    expect($calls)->toBe([
        'concurrency 1 --max-requests 1000000',
        'concurrency 1 --max-requests 1000000',
        'concurrency 1 --max-requests 500',
        'concurrency default',
    ])->and($log)->toContain('env: APP_ENV=production APP_DEBUG=false DEBUG_ROUTES=true ')
        ->and(file_get_contents($this->dir.'/.env'))->toBe($this->envBefore)
        ->and(file_exists($this->dir.'/.env.bench-backup'))->toBeFalse();
});

it('AC-3 classic frankenphp runs cases A and B with one thread, sees no restarts and no growth', function (): void {
    $process = ($this->memory)('frankenphp', ['--requests', '500']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $result = ($this->result)('frankenphp');
    expect(array_column($result['cases'], 'case'))->toBe(['A', 'B'])
        ->and(array_column($result['cases'], 'restarts'))->toBe([0, 0])
        ->and(array_column($result['cases'], 'max_requests'))->toBe([null, null])
        ->and(array_column($result['cases'], 'growth_kib_per_1000'))->each->toEqualWithDelta(0.0, 1.0);

    $calls = array_values(array_filter(($this->log)(), fn (string $line): bool => str_starts_with($line, 'concurrency ')));
    expect($calls)->toBe(['concurrency 1', 'concurrency 1', 'concurrency default']);
});

it('AC-3 restores .env and the default worker settings when the server stops answering', function (): void {
    $process = ($this->memory)('frankenphp-octane', ['--requests', '200'], ['STUB_STATUS' => '503']);

    expect($process->getExitCode())->not->toBe(0)
        ->and(file_get_contents($this->dir.'/.env'))->toBe($this->envBefore)
        ->and(file_exists($this->dir.'/.env.bench-backup'))->toBeFalse()
        ->and(array_slice(($this->log)(), -2))->toContain('concurrency default');
});

it('AC-3 refuses servers without a worker lab and bad arguments before touching anything', function (): void {
    $process = ($this->memory)('nginx-fpm', ['--requests', '200']);
    expect($process->getExitCode())->toBe(2)
        ->and(file_get_contents($this->dir.'/.env'))->toBe($this->envBefore);

    expect(($this->memory)('frankenphp-worker', ['--requests', '150'])->getExitCode())->toBe(2)
        ->and(($this->memory)('frankenphp-worker', ['--bogus'])->getExitCode())->toBe(2)
        ->and(array_filter(($this->log)(), fn (string $line): bool => str_starts_with($line, 'concurrency ')))->toBe([]);
});

it('AC-3 aborts with exit 2 and writes no memory file when lab requests do not answer 200', function (): void {
    $process = ($this->memory)('frankenphp-worker', ['--requests', '200'], ['STUB_LAB_STATUS' => '503']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('case A')->toContain('200 of 200')
        ->and(file_exists($this->dir.'/bench/results/2030-01-01/memory-frankenphp-worker.json'))->toBeFalse()
        ->and(file_get_contents($this->dir.'/.env'))->toBe($this->envBefore)
        ->and(file_exists($this->dir.'/.env.bench-backup'))->toBeFalse()
        ->and(array_slice(($this->log)(), -2))->toContain('concurrency default');
});

it('AC-3 refuses with exit 2 when the view cache is not built', function (): void {
    $process = ($this->memory)('frankenphp-worker', ['--requests', '200'], ['STUB_NO_VIEWS' => '1']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('view cache')
        ->and(file_get_contents($this->dir.'/.env'))->toBe($this->envBefore)
        ->and(array_slice(($this->log)(), -2))->toContain('concurrency default');
});

it('MNC-1 refuses with exit 2 when the results folder already holds committed results', function (): void {
    mkdir($this->dir.'/bench/results/2030-01-01', 0777, true);
    file_put_contents($this->dir.'/bench/results/2030-01-01/memory-frankenphp-worker.json', '{}');
    (new Process(['git', 'init', '-q'], $this->dir))->mustRun();
    (new Process(['git', 'add', 'bench/results'], $this->dir))->mustRun();

    $process = ($this->memory)('frankenphp-worker', ['--requests', '200']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('already holds committed results')
        ->and(file_get_contents($this->dir.'/bench/results/2030-01-01/memory-frankenphp-worker.json'))->toBe('{}')
        ->and(array_filter(($this->log)(), fn (string $line): bool => str_starts_with($line, 'concurrency ')))->toBe([]);
});

it('AC-3 a one-off warm-up step after the first sample does not count as growth', function (): void {
    $process = ($this->memory)('frankenphp-worker', ['--requests', '1000'], ['STUB_WARMUP' => '500000']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    [$a, $b] = ($this->result)('frankenphp-worker')['cases'];
    expect($b['samples'][1]['memory_bytes'] - $b['samples'][0]['memory_bytes'])->toBeGreaterThanOrEqual(500000)
        ->and($b['growth_kib_per_1000'])->toEqualWithDelta(0.0, 1.0)
        ->and($a['growth_kib_per_1000'])->toEqualWithDelta(1000.0, 5.0);
});
