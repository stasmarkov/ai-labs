<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-slow-load-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app');
    $this->envBefore = "APP_ENV=local\nAPP_DEBUG=true\nAI_PROVIDER=ollama\nSECRET=keep\n";
    file_put_contents($this->root.'/app/.env', $this->envBefore);

    $stubs = [
        'ddev' => <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$*" in
  "server --current") echo "${STUB_SERVER-nginx-fpm}" ;;
  "server --url") echo "https://interview.ddev.site" ;;
  "artisan config:show app.env") echo "${STUB_APP_ENV-production}" ;;
  "artisan config:show app.debug") echo "${STUB_APP_DEBUG-false}" ;;
esac
BASH,
        'curl' => <<<'BASH'
#!/usr/bin/env bash
echo "curl $*" >> "$STUB_LOG"
out=""
url="${*: -1}"
while [[ $# -gt 0 ]]; do
  case "$1" in
    -o) out="$2"; shift 2 ;;
    -w|-H|--max-time) shift 2 ;;
    *) shift ;;
  esac
done
if [[ "$url" == */healthz && -n "${STUB_HEALTHZ_STATUS-}" ]]; then printf '%s' "$STUB_HEALTHZ_STATUS"; exit 0; fi
if [[ "$url" == */slow && -n "${STUB_SLOW_STATUS-}" ]]; then printf '%s' "$STUB_SLOW_STATUS"; exit 0; fi
printf '{"data":{"opcache_enabled":%s,"xdebug_loaded":%s}}' "${STUB_OPCACHE-true}" "${STUB_XDEBUG-false}" > "$out"
printf '200'
BASH,
        'git' => <<<'BASH'
#!/usr/bin/env bash
case "$*" in
  *status*) printf '%s' "${STUB_GIT_STATUS-}" ;;
  *rev-parse*) echo abc1234 ;;
esac
BASH,
        'docker' => <<<'BASH'
#!/usr/bin/env bash
echo "docker $*" >> "$STUB_LOG"
case "$1" in
  inspect) echo 172.19.0.2 ;;
  pull) ;;
  image) echo "stub/image@sha256:abc" ;;
  rm) ;;
  run)
    if [[ "$*" == *--name\ slow-load-probe-* ]]; then
      n=$(( $(cat "$STUB_COUNTER.probe" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$STUB_COUNTER.probe"
      p95=$(cut -d, -f"$n" <<< "${STUB_HEALTH_P95-0.010}")
      if [[ "$p95" == none ]]; then
        echo '{"summary":{"requestsPerSec":0},"latencyPercentiles":null,"statusCodeDistribution":{},"errorDistribution":{"timeout":4}}'
        exit 0
      fi
      if [[ -n "${STUB_HEALTH_MIX-}" ]]; then
        mix=$(cut -d';' -f"$n" <<< "$STUB_HEALTH_MIX")
        echo '{"summary":{"requestsPerSec":5},"latencyPercentiles":{"p50":0.004,"p95":'"${STUB_HEALTH_P95_MIX-0.02}"',"p99":0.05},"statusCodeDistribution":{"200":'"$(cut -d, -f1 <<< "$mix")"',"502":'"$(cut -d, -f2 <<< "$mix")"',"504":'"$(cut -d, -f3 <<< "$mix")"',"500":'"$(cut -d, -f4 <<< "$mix")"',"404":'"$(cut -d, -f5 <<< "$mix")"'},"errorDistribution":{"timeout":'"$(cut -d, -f6 <<< "$mix")"'}}'
        exit 0
      fi
      echo '{"summary":{"requestsPerSec":20},"latencyPercentiles":{"p50":0.004,"p95":'"$p95"',"p99":0.05},"statusCodeDistribution":{"200":199,"503":1},"errorDistribution":{"timeout":0}}'
    elif [[ "$*" == *grafana/k6* ]]; then
      for arg in "$@"; do
        [[ "$arg" == *:/out ]] && out="${arg%:/out}"
      done
      # Two checks per stream (200 and [DONE]) are in the summary on purpose: the harness must read stream_failed only.
      if [[ "${STUB_K6-}" == none ]]; then
        echo '{"metrics":{"http_reqs":{"count":7,"rate":0.1},"http_req_duration":{"med":60000,"p(95)":60000,"p(99)":60000},"stream_failed":{"passes":7,"fails":0,"value":1},"checks":{"value":0,"passes":0,"fails":14}}}' > "$out/k6.json"
      else
        echo '{"metrics":{"http_reqs":{"count":300,"rate":5.5},"http_req_duration":{"med":10000,"p(95)":10400,"p(99)":10900},"stream_done_ms":{"med":6100,"p(95)":6400,"p(99)":6900},"stream_failed":{"passes":10,"fails":90,"value":0.1},"checks":{"value":0.9,"passes":180,"fails":20}}}' > "$out/k6.json"
      fi
    else
      for arg in "$@"; do
        if [[ "$arg" == *:/ids:ro ]]; then
          ids="${arg%:/ids:ro}"
          echo "urls_first=$(head -n 1 "$ids/urls.txt") count=$(wc -l < "$ids/urls.txt" | tr -d ' ')" >> "$STUB_LOG"
        fi
      done
      n=$(( $(cat "$STUB_COUNTER.load" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$STUB_COUNTER.load"
      rps=$(cut -d, -f"$n" <<< "${STUB_LOAD_RPS-100}")
      echo '{"summary":{"requestsPerSec":'"$rps"'},"latencyPercentiles":{"p50":0.31,"p95":0.35,"p99":0.4},"statusCodeDistribution":{"200":90,"503":10},"errorDistribution":{"timeout":0}}'
    fi ;;
esac
BASH,
    ];
    foreach ($stubs as $name => $script) {
        file_put_contents($this->root.'/bin/'.$name, $script);
        chmod($this->root.'/bin/'.$name, 0755);
    }

    $this->slowLoad = function (array $env, string ...$args): Process {
        $process = new Process(
            ['bash', base_path('bench/slow-load.sh'), ...$args],
            $this->root,
            $env + [
                'BENCH_APPROOT' => $this->root.'/app',
                'BENCH_SETTLE' => '0',
                'STUB_LOG' => $this->root.'/calls.log',
                'STUB_COUNTER' => $this->root.'/counter',
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
            ],
        );
        $process->run();

        return $process;
    };
    $this->calls = fn (): string => file_exists($this->root.'/calls.log') ? file_get_contents($this->root.'/calls.log') : '';
    $this->result = fn (string $name, string $dir = 'raw'): array => json_decode(file_get_contents($this->root.'/app/bench/results/'.$dir.'/'.$name.'.json'), true);
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-4 slow-load http runs a slow load and a health probe as two capped containers and prints a table', function (): void {
    $process = ($this->slowLoad)([], '--workload', 'http', '--quick', '--label', 'nginx-fpm-8');
    $calls = ($this->calls)();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('/listings/1/slow', '/healthz', '--name slow-load-load-', '--name slow-load-probe-')
        ->and(substr_count($calls, '--cpus=2'))->toBe(3)
        ->and($calls)->toContain('-c 100')
        ->and($process->getOutput())->toContain('workload=http', 'load_rps', 'health_p95_ms', 'median', 'health p95 spread');

    $result = ($this->result)('http-nginx-fpm-8');

    expect($result)->toMatchArray([
        'server' => 'nginx-fpm', 'workload' => 'http', 'label' => 'nginx-fpm-8', 'commit' => 'abc1234',
        'concurrency' => 'default', 'users' => 100, 'warmup_s' => 5, 'measure_s' => 15, 'repeats' => 1, 'quick' => true, 'flagged' => false,
    ])
        ->and($result['median']['load_rps'])->toEqual(100)
        ->and($result['median']['load_p95_ms'])->toEqual(350)
        ->and($result['median']['health_p95_ms'])->toEqual(10)
        ->and($result['median']['health_errors'])->toEqual(1)
        ->and($result['runs'])->toHaveCount(1)
        ->and($result['load_image_digest'])->not->toBeEmpty();
});

it('AC-5 slow-load sse reads the token stream with the k6 closed-loop reader', function (): void {
    $process = ($this->slowLoad)([], '--workload', 'sse', '--quick', '--users', '48', '--label', 'frankenphp-14');
    $calls = ($this->calls)();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('/bench/k6-sse.js', 'VUS=48', 'grafana/k6')
        ->and($calls)->not->toContain('/listings/1/slow')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore);

    $result = ($this->result)('sse-frankenphp-14');

    expect($result['workload'])->toBe('sse')
        ->and($result['users'])->toBe(48)
        ->and($result['median']['load_rps'])->toEqual(5.5)
        ->and($result['median']['load_p50_ms'])->toEqual(6100)
        ->and($result['median']['load_p95_ms'])->toEqual(6400)
        ->and($result['median']['load_p99_ms'])->toEqual(6900)
        ->and($result['median']['load_errors'])->toEqual(10)
        ->and($result['median']['load_error_percent'])->toEqualWithDelta(10.0, 0.0001)
        ->and($result['runs'][0]['load']['requests'])->toBe(100)
        ->and($result['median']['load_no_stream_completed'])->toBeFalse()
        ->and($result['median']['health_p95_ms'])->toEqual(10);
});

it('AC-5 k6-sse.js is a closed loop over the describe stream', function (): void {
    $script = file_get_contents(base_path('bench/k6-sse.js'));

    expect($script)->toContain('constant-vus', '/describe', 'gracefulStop', 'VUS');
});

it('AC-5 k6-sse.js counts one failure per stream in a Rate metric and has no per-check counts', function (): void {
    $script = file_get_contents(base_path('bench/k6-sse.js'));

    expect($script)->toContain("new Rate('stream_failed')", "new Trend('stream_done_ms'", 'streamFailed.add(')
        ->and(substr_count($script, 'streamFailed.add('))->toBe(1)
        ->and($script)->not->toContain('check(')
        ->and($script)->toContain("__ENV.VUS || '30'", 'ID_OFFSET');
});

it('AC-5 slow-load reads the error share from stream_failed, one per stream, even with two checks per stream', function (): void {
    $process = ($this->slowLoad)([], '--workload', 'sse', '--quick', '--label', 'perstream');
    $load = ($this->result)('sse-perstream')['runs'][0]['load'];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($load['errors'])->toBe(10)
        ->and($load['error_percent'])->toEqualWithDelta(10.0, 0.0001)
        ->and($load['requests'])->toBe(100);
});

it('AC-5 slow-load reports null times and a flag when no stream completed, never http_req_duration', function (): void {
    $process = ($this->slowLoad)(['STUB_K6' => 'none'], '--workload', 'sse', '--quick', '--label', 'nostream');
    $result = ($this->result)('sse-nostream');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result['runs'][0]['load'])->toMatchArray(['p50_ms' => null, 'p95_ms' => null, 'p99_ms' => null, 'no_stream_completed' => true, 'errors' => 7])
        ->and($result['median']['load_p95_ms'])->toBeNull()
        ->and($result['median']['load_no_stream_completed'])->toBeTrue()
        ->and($result['load_no_stream_repeats'])->toBe(1)
        ->and($result['median']['load_error_percent'])->toEqual(100)
        ->and($process->getOutput())->toContain('no stream completed');
});

it('AC-5 k6-sse.js reads to [DONE], times it, and rotates the listing id from 1 to 50000', function (): void {
    $script = file_get_contents(base_path('bench/k6-sse.js'));

    expect($script)->toContain('data: [DONE]', 'stream_done_ms', '50000', '__ITER', '__VU', 'idOffset')
        ->and($script)->not->toContain('LISTING_ID');
});

it('AC-4 slow-load sets the benchmark env, and for sse the fake stream, then restores .env', function (): void {
    $before = $this->root.'/env.during';
    file_put_contents($this->root.'/bin/ddev', <<<BASH
#!/usr/bin/env bash
echo "ddev \$*" >> "\$STUB_LOG"
case "\$*" in
  "server --current") echo nginx-fpm ;;
  "server --url") echo "https://interview.ddev.site" ;;
  "artisan optimize") cat "$this->root/app/.env" > "$before" ;;
  "artisan config:show app.env") echo production ;;
  "artisan config:show app.debug") echo false ;;
esac
BASH);

    $process = ($this->slowLoad)([], '--workload', 'sse', '--quick');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($before))->toContain('APP_ENV=production', 'APP_DEBUG=false', 'DEBUG_ROUTES=true', 'AI_PROVIDER=fake-stream', 'AI_FAKE_TOKENS=120', 'AI_FAKE_TOKEN_DELAY_MS=50', 'AI_DESCRIBE_PER_MINUTE=1000000', 'SECRET=keep')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore)
        ->and(file_exists($this->root.'/app/.env.bench-backup'))->toBeFalse()
        ->and(($this->calls)())->toContain('ddev artisan optimize:clear');
});

it('AC-1 slow-load http enables the slow route (DEBUG_ROUTES) and raises its limiter, then restores .env', function (): void {
    $during = $this->root.'/env.during';
    file_put_contents($this->root.'/bin/ddev', <<<BASH
#!/usr/bin/env bash
echo "ddev \$*" >> "\$STUB_LOG"
case "\$*" in
  "server --current") echo nginx-fpm ;;
  "server --url") echo "https://interview.ddev.site" ;;
  "artisan optimize") cat "$this->root/app/.env" > "$during" ;;
  "artisan config:show app.env") echo production ;;
  "artisan config:show app.debug") echo false ;;
esac
BASH);

    $process = ($this->slowLoad)([], '--workload', 'http', '--quick');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($during))->toContain('DEBUG_ROUTES=true', 'VINSTUB_PER_MINUTE=1000000', 'APP_ENV=production')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore);
});

it('NFR-1 slow-load records the health p95 spread of the repeats and flags one above 20 percent', function (string $p95, bool $flagged): void {
    $process = ($this->slowLoad)(
        ['BENCH_REPEATS' => '3', 'STUB_HEALTH_P95' => $p95],
        '--workload', 'http', '--label', 'spread',
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $result = ($this->result)('http-spread', 'slow-load/'.gmdate('Y-m-d'));

    expect($result['repeats'])->toBe(3)
        ->and($result['runs'])->toHaveCount(3)
        ->and($result['flagged'])->toBe($flagged)
        ->and($result['spread_limit_percent'])->toBe(20)
        ->and($result['spread_percent']['health_p95'])->toBeNumeric()
        ->and($result['spread_percent']['load_rps'])->toBeNumeric()
        ->and($process->getOutput())->when($flagged, fn ($output) => $output->toContain('FLAGGED'), fn ($output) => $output->not->toContain('FLAGGED'));
})->with([
    'steady' => ['0.010,0.011,0.0105', false],
    'noisy' => ['0.010,0.020,0.015', true],
]);

it('NFR-1 slow-load takes the median of the repeats', function (): void {
    ($this->slowLoad)(['BENCH_REPEATS' => '3', 'BENCH_WARMUP' => '0', 'STUB_LOAD_RPS' => '90,110,100'], '--workload', 'http', '--label', 'median');

    $result = ($this->result)('http-median', 'slow-load/'.gmdate('Y-m-d'));

    expect($result['median']['load_rps'])->toEqual(100)
        ->and($result['spread_percent']['load_rps'])->toEqualWithDelta(20.0, 0.0001);
});

it('NFR-2 slow-load keeps the probe and the load apart and stops before any load when a condition fails', function (string $check, array $env): void {
    $process = ($this->slowLoad)($env, '--workload', 'http', '--quick');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain($check)
        ->and(($this->calls)())->not->toContain('docker run')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore)
        ->and(file_exists($this->root.'/app/.env.bench-backup'))->toBeFalse();
})->with([
    'app env' => ['APP_ENV', ['STUB_APP_ENV' => 'local']],
    'app debug' => ['APP_DEBUG', ['STUB_APP_DEBUG' => 'true']],
    'opcache' => ['OPcache', ['STUB_OPCACHE' => 'false']],
    'xdebug' => ['Xdebug', ['STUB_XDEBUG' => 'true']],
    'healthz' => ['/healthz', ['STUB_HEALTHZ_STATUS' => '404']],
    'slow endpoint' => ['/listings/1/slow', ['STUB_SLOW_STATUS' => '503']],
]);

it('AC-4 slow-load refuses a dirty git tree unless it is a quick run', function (): void {
    $dirty = ['STUB_GIT_STATUS' => ' M app/Models/User.php'];
    $full = ($this->slowLoad)($dirty, '--workload', 'http');

    expect($full->getExitCode())->toBe(2)
        ->and($full->getErrorOutput())->toContain('git working tree');

    expect(($this->slowLoad)($dirty, '--workload', 'http', '--quick')->getExitCode())->toBe(0);
});

it('AC-4 slow-load rejects bad arguments before touching anything', function (array $args): void {
    $process = ($this->slowLoad)([], ...$args);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Usage')
        ->and(($this->calls)())->toBe('')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore);
})->with([
    'no workload' => [['--quick']],
    'unknown workload' => [['--workload', 'udp']],
    'bad users' => [['--workload', 'http', '--users', '0']],
    'unknown flag' => [['--workload', 'http', '--bogus']],
]);

it('NFR-1 slow-load rejects BENCH_REPEATS below 1 or not a number with exit 2 before touching anything', function (string $repeats): void {
    $process = ($this->slowLoad)(['BENCH_REPEATS' => $repeats], '--workload', 'http', '--quick');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('BENCH_REPEATS')
        ->and(($this->calls)())->toBe('')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore);
})->with(['zero' => '0', 'negative' => '-1', 'text' => 'abc']);

it('NFR-1 slow-load takes the true median for an even number of repeats', function (): void {
    ($this->slowLoad)(['BENCH_REPEATS' => '2', 'BENCH_WARMUP' => '0', 'STUB_LOAD_RPS' => '90,110'], '--workload', 'http', '--label', 'even');
    $result = ($this->result)('http-even', 'slow-load/'.gmdate('Y-m-d'));

    expect($result['median']['load_rps'])->toEqual(100)
        ->and($result['spread_percent']['load_rps'])->toEqualWithDelta(20.0, 0.0001);
});

it('NFR-1 slow-load reports a null spread, not 0, when the median is 0 but a repeat is not', function (): void {
    ($this->slowLoad)(['BENCH_REPEATS' => '3', 'BENCH_WARMUP' => '0', 'STUB_LOAD_RPS' => '0,0,5'], '--workload', 'http', '--label', 'zero');
    $result = ($this->result)('http-zero', 'slow-load/'.gmdate('Y-m-d'));

    expect($result['median']['load_rps'])->toEqual(0)
        ->and($result['spread_percent']['load_rps'])->toBeNull();
});

it('NFR-1 slow-load keeps a spread of 0 when every repeat is 0 and prints n/a for a health p95 spread with median 0', function (): void {
    ($this->slowLoad)(['BENCH_REPEATS' => '2', 'BENCH_WARMUP' => '0', 'STUB_LOAD_RPS' => '0,0'], '--workload', 'http', '--label', 'allzero');
    expect(($this->result)('http-allzero', 'slow-load/'.gmdate('Y-m-d'))['spread_percent']['load_rps'])->toEqual(0);

    array_map('unlink', glob($this->root.'/counter.*'));
    $process = ($this->slowLoad)(['BENCH_REPEATS' => '3', 'BENCH_WARMUP' => '0', 'STUB_HEALTH_P95' => '0,0,0.01'], '--workload', 'http', '--label', 'p95zero');
    $result = ($this->result)('http-p95zero', 'slow-load/'.gmdate('Y-m-d'));

    expect($result['spread_percent']['health_p95'])->toBeNull()
        ->and($result['flagged'])->toBeFalse()
        ->and($process->getOutput())->toContain('n/a (median 0)');
});

it('NFR-2 AC-3 slow-load-matrix plans the 8 cells and restores the defaults and the default server', function (): void {
    $stubDir = sys_get_temp_dir().'/bench-default-'.bin2hex(random_bytes(4));
    mkdir($stubDir);
    file_put_contents($stubDir.'/ddev', "#!/usr/bin/env bash\n[[ \"\$*\" == \"server --default\" ]] && echo apache-fpm\n");
    chmod($stubDir.'/ddev', 0755);
    $env = ['PATH' => $stubDir.':'.getenv('PATH')];
    $process = new Process(['bash', base_path('bench/slow-load-matrix.sh'), '--dry-run'], null, $env);
    $process->run();
    $lines = explode("\n", trim($process->getOutput()));
    $runs = array_values(array_filter($lines, fn (string $line): bool => str_contains($line, 'slow-load.sh')));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($runs)->toHaveCount(8)
        ->and($process->getOutput())
        ->toContain('concurrency.sh 8', 'concurrency.sh 32', 'concurrency.sh 14', 'autoscale --threads 14 --max-threads 64 --max-wait 5')
        ->toContain('--workload http --users 100 --label nginx-fpm-8', '--workload sse --users 30 --label frankenphp-autoscale-14-64')
        ->and(end($lines))->toBe('ddev server apache-fpm')
        ->and($lines[count($lines) - 2])->toContain('concurrency.sh default');

    $quick = new Process(['bash', base_path('bench/slow-load-matrix.sh'), '--dry-run', '--quick'], null, $env);
    $quick->run();
    expect(substr_count($quick->getOutput(), '--quick'))->toBe(8);

    $bad = new Process(['bash', base_path('bench/slow-load-matrix.sh'), '--nope'], null, $env);
    $bad->run();
    expect($bad->getExitCode())->toBe(2);
    exec('rm -rf '.escapeshellarg($stubDir));
});

it('NFR-1 slow-load marks a probe that got no answer as starved: a null answered p95, the 10 s timeout in the all-sent p95', function (): void {
    $process = ($this->slowLoad)(['STUB_HEALTH_P95' => 'none'], '--workload', 'http', '--quick', '--label', 'starved');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $result = ($this->result)('http-starved');
    $health = $result['runs'][0]['health'];

    expect($result['median']['health_p95_ms'])->toEqual(10000)
        ->and($result['median']['health_p95_answered_ms'])->toBeNull()
        ->and($result['median']['health_starved'])->toBeTrue()
        ->and($result['health_starved_repeats'])->toBe(1)
        ->and($result['median']['health_errors'])->toEqual(4)
        ->and($health)->toMatchArray(['starved' => true, 'p50_ms' => null, 'p95_answered_ms' => null, 'p99_ms' => null])
        ->and($health['p95_ms'])->toEqual(10000)
        ->and($process->getOutput())->toContain('10000 starved', 'health starved in 1 of 1 repeats');
});

it('NFR-1 slow-load does not mark an answered probe as starved', function (): void {
    ($this->slowLoad)([], '--workload', 'http', '--quick', '--label', 'fed');
    $result = ($this->result)('http-fed');

    expect($result['health_starved_repeats'])->toBe(0)
        ->and($result['median']['health_starved'])->toBeFalse()
        ->and($result['median']['health_p95_ms'])->toEqual(10)
        ->and($result['median']['health_p95_answered_ms'])->toEqual(10);
});

it('NFR-1 slow-load counts timeouts as the 10 s timeout in the all-sent p95 and keeps the answered p95', function (string $mix, float $allSent, float $answered): void {
    $process = ($this->slowLoad)(['STUB_HEALTH_MIX' => $mix, 'STUB_HEALTH_P95_MIX' => '0.02'], '--workload', 'http', '--quick', '--label', 'allsent');
    $health = ($this->result)('http-allsent')['runs'][0]['health'];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($health['p95_answered_ms'])->toEqual($answered)
        ->and($health['p95_ms'])->toEqualWithDelta($allSent, 0.01)
        ->and($health['starved'])->toBeFalse();
})->with([
    'timeouts above 5 percent: the all-sent p95 is the timeout' => ['150,0,0,0,0,50', 10000.0, 20.0],
    'few timeouts: read off the answered percentiles' => ['197,0,0,0,0,3', 30.85, 20.0],
    'no timeouts: equal to the answered p95' => ['200,0,0,0,0,0', 20.0, 20.0],
]);

it('NFR-1 slow-load rotates the listing ids: offset 0 in a warm-up and repeat x 10007 mod 50000 in a measure window', function (): void {
    $http = ($this->slowLoad)(['BENCH_REPEATS' => '3', 'BENCH_WARMUP' => '1'], '--workload', 'http', '--users', '10', '--quick', '--label', 'ids');
    $urls = [];
    preg_match_all('#urls_first=https://interview\.ddev\.site/listings/(\d+)/slow count=(\d+)#', ($this->calls)(), $urls);

    expect($http->getExitCode())->toBe(0, $http->getErrorOutput())
        ->and($urls[1])->toBe(['1', '10008', '1', '20015', '1', '30022'])
        ->and(array_unique($urls[2]))->toBe(['2000'])
        ->and(($this->calls)())->toContain('--urls-from-file /ids/urls.txt')
        ->and(collect(($this->result)('http-ids')['runs'])->pluck('id_offset')->all())->toBe([10007, 20014, 30021]);

    file_put_contents($this->root.'/calls.log', '');
    $sse = ($this->slowLoad)(['BENCH_REPEATS' => '6', 'BENCH_WARMUP' => '1'], '--workload', 'sse', '--quick', '--label', 'ids');
    $offsets = [];
    preg_match_all('/ID_OFFSET=(\d+)/', ($this->calls)(), $offsets);

    expect($sse->getExitCode())->toBe(0, $sse->getErrorOutput())
        ->and($offsets[1])->toBe(['0', '10007', '0', '20014', '0', '30021', '0', '40028', '0', '35', '0', '10042']);
});

it('NFR-1 slow-load wraps the id list around the end of the 50000 listings', function (): void {
    ($this->slowLoad)(['BENCH_REPEATS' => '5', 'BENCH_WARMUP' => '0'], '--workload', 'http', '--quick', '--label', 'wrap');
    $urls = [];
    preg_match_all('#urls_first=https://interview\.ddev\.site/listings/(\d+)/slow#', ($this->calls)(), $urls);

    expect($urls[1])->toBe(['10008', '20015', '30022', '40029', '36']);
});

it('AC-4 AC-5 slow-load defaults to 100 users for http and 30 for sse', function (string $workload, int $users, string $needle): void {
    $process = ($this->slowLoad)([], '--workload', $workload, '--quick', '--label', 'users');
    $result = ($this->result)($workload.'-users');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result['users'])->toBe($users)
        ->and(($this->calls)())->toContain($needle);
})->with([
    'http' => ['http', 100, '-c 100'],
    'sse' => ['sse', 30, 'VUS=30'],
]);

it('AC-4 slow-load uses 30 s warm-up, 60 s measure and 3 repeats by default and 5, 15 and 1 with --quick', function (): void {
    $full = ($this->slowLoad)([], '--workload', 'http', '--label', 'full');
    $quick = ($this->slowLoad)([], '--workload', 'http', '--quick', '--label', 'quick');

    expect($full->getExitCode())->toBe(0, $full->getErrorOutput())
        ->and($quick->getExitCode())->toBe(0, $quick->getErrorOutput());

    $fullResult = ($this->result)('http-full', 'slow-load/'.gmdate('Y-m-d'));
    $quickResult = ($this->result)('http-quick');

    expect($fullResult)->toMatchArray(['warmup_s' => 30, 'measure_s' => 60, 'repeats' => 3, 'quick' => false])
        ->and($quickResult)->toMatchArray(['warmup_s' => 5, 'measure_s' => 15, 'repeats' => 1, 'quick' => true]);
});

it('AC-7 slow-load writes quick runs to bench/results/raw and full runs to a dated slow-load directory', function (): void {
    ($this->slowLoad)([], '--workload', 'http', '--quick', '--label', 'where');
    ($this->slowLoad)([], '--workload', 'http', '--label', 'where');
    $results = $this->root.'/app/bench/results';

    expect(file_exists($results.'/raw/http-where.json'))->toBeTrue()
        ->and(file_exists($results.'/slow-load/'.gmdate('Y-m-d').'/http-where.json'))->toBeTrue()
        ->and(file_exists($results.'/slow-load/http-where.json'))->toBeFalse();
});

it('NFR-1 slow-load header describes the probe as 5 requests per second, not 20', function (): void {
    $header = implode("\n", array_slice(explode("\n", file_get_contents(base_path('bench/slow-load.sh'))), 0, 30));

    expect($header)->toContain('5 requests per second', '50 connections', '--latency-correction')
        ->and($header)->not->toContain('20 requests per second');
});

it('AC-4 slow-load probes /healthz at 5 requests per second with a 10 s timeout', function (): void {
    ($this->slowLoad)([], '--workload', 'http', '--quick', '--label', 'probe');
    $probe = collect(explode("\n", ($this->calls)()))->first(fn (string $line): bool => str_contains($line, '--name slow-load-probe-'));

    expect($probe)->toContain('-q 5', '-t 10s', '-c 50', '--latency-correction')->not->toContain('-t 30s', '-c 2 ');
    expect(($this->result)('http-probe')['probe_timeout_s'])->toBe(10);
});

it('AC-7 slow-load breaks health failures down and computes figures over answered requests', function (): void {
    $process = ($this->slowLoad)(
        ['STUB_HEALTH_MIX' => '150,10,5,2,1,7', 'STUB_HEALTH_P95_MIX' => '0.02'],
        '--workload', 'http', '--quick', '--label', 'mixed',
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $health = ($this->result)('http-mixed')['runs'][0]['health'];

    expect($health['breakdown'])->toEqual(['502' => 10, '504' => 5, 'other_5xx' => 2, 'other_non_2xx' => 1, 'timeouts' => 7])
        ->and($health['p50_ms'])->toEqual(4)
        ->and($health['p95_answered_ms'])->toEqual(20)
        ->and($health['p95_ms'])->toEqualWithDelta(49.6875, 0.001)
        ->and($health['p99_ms'])->toEqual(50)
        ->and($health['requests'])->toBe(175)
        ->and($health['errors'])->toBe(25)
        ->and($health['error_percent'])->toEqualWithDelta(25 / 175 * 100, 0.0001)
        ->and($process->getOutput())->toContain('502=10', '504=5', 'timeouts=7');
});

it('NFR-1 slow-load records a median and a spread for every figure and still flags from the health p95 spread', function (): void {
    $process = ($this->slowLoad)(
        ['BENCH_REPEATS' => '3', 'BENCH_WARMUP' => '0', 'STUB_HEALTH_MIX' => '1500,10,5,2,1,7;1500,12,5,2,1,9;1500,14,5,2,1,8', 'STUB_HEALTH_P95' => '0.010,0.011,0.0105', 'STUB_LOAD_RPS' => '90,110,100'],
        '--workload', 'http', '--label', 'figures',
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $result = ($this->result)('http-figures', 'slow-load/'.gmdate('Y-m-d'));
    $keys = ['rps', 'p50_ms', 'p95_ms', 'p99_ms', 'errors', 'error_percent'];

    foreach (['load', 'health'] as $side) {
        foreach ($keys as $key) {
            expect($result['median'])->toHaveKey("{$side}_{$key}")
                ->and($result['spread_percent'])->toHaveKey("{$side}_{$key}");
        }
    }
    foreach (['502', '504', 'other_5xx', 'other_non_2xx', 'timeouts'] as $key) {
        expect($result['median'])->toHaveKey("health_{$key}")
            ->and($result['spread_percent'])->toHaveKey("health_{$key}");
    }

    expect($result['median']['health_502'])->toEqual(12)
        ->and($result['median']['health_timeouts'])->toEqual(8)
        ->and($result['spread_percent']['health_502'])->toEqualWithDelta(4 / 12 * 100, 0.0001)
        ->and($result['spread_percent']['load_rps'])->toEqualWithDelta(20.0, 0.0001)
        ->and($result['flagged'])->toBeFalse();
});
