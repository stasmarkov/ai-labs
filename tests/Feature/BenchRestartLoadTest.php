<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-restart-load-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app');
    $this->envBefore = "APP_ENV=local\nAPP_DEBUG=true\nSECRET=keep\n";
    file_put_contents($this->root.'/app/.env', $this->envBefore);

    $stubs = [
        'ddev' => <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$*" in
  "server --current") cat "$STUB_STATE" 2>/dev/null || echo frankenphp ;;
  "server --default") echo frankenphp ;;
  "server --url") echo "https://ai-lab.ddev.site" ;;
  "server frankenphp"*) echo "$2" > "$STUB_STATE" ;;
  "artisan frankenphp:workers:restart")
    : > "$STUB_STATE.restarting"
    sleep 0.6
    rm -f "$STUB_STATE.restarting"
    echo "restarted in 120 ms"; exit "${STUB_RESTART_EXIT-0}" ;;
esac
BASH,
        'curl' => <<<'BASH'
#!/usr/bin/env bash
case "$*" in
  *healthz*) echo "curl $*" >> "$STUB_LOG"; printf '200' ;;
  *)
    # The request probe: refused connections while a restart runs, 200 otherwise.
    if [[ -e "$STUB_STATE.restarting" ]]; then printf '000 0.001'; exit 7; fi
    printf '200 0.010' ;;
esac
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
  inspect)
    if [[ "${@: -1}" == restart-load-* ]]; then
      [[ -e "$STUB_STATE.running" ]] && echo true || echo false
    else
      echo 172.19.0.2
    fi ;;
  image) echo "stub/image@sha256:abc" ;;
  pull|rm) ;;
  run)
    seconds=0
    while [[ $# -gt 0 ]]; do
      case "$1" in
        -z) seconds="${2%s}" ;;
        --name) name="$2" ;;
        *:/out) out="${1%:/out}" ;;
      esac
      shift
    done
    [[ "$name" == restart-load-* && -n "${out-}" ]] && : > "$STUB_STATE.running"
    sleep "$seconds"
    if [[ -n "${out-}" ]]; then
      # oha writes its request database only when the run ends; a real sqlite file with oha's columns.
      sqlite3 "$out/oha.db" "create table oha (start real, end real, duration real, status integer);
        insert into oha values (0.2, 0.3, 0.010, 200), (2.5, 3.4, 0.900, 503), (3.0, 3.1, 0.100, 200), (6.0, 6.1, 0.020, 200), (6.5, 6.6, 0.030, 200);"
      rm -f "$STUB_STATE.running"
      echo "load-exit" >> "$STUB_LOG"
    fi
    echo '{"summary":{"requestsPerSec":100},"latencyPercentiles":{"p50":0.01,"p95":0.05,"p99":0.9},"statusCodeDistribution":{"200":4,"503":1},"errorDistribution":{}}' ;;
esac
BASH,
    ];
    foreach ($stubs as $name => $script) {
        file_put_contents($this->root.'/bin/'.$name, $script);
        chmod($this->root.'/bin/'.$name, 0755);
    }

    $this->restartLoad = function (array $env, string ...$args): Process {
        $process = new Process(
            ['bash', base_path('bench/restart-load.sh'), ...$args],
            $this->root,
            $env + [
                'BENCH_APPROOT' => $this->root.'/app',
                'BENCH_WARMUP' => '1',
                'BENCH_MEASURE' => '4',
                'BENCH_REPEATS' => '3',
                'BENCH_PROBE_RPS' => '10',
                'STUB_LOG' => $this->root.'/calls.log',
                'STUB_STATE' => $this->root.'/server',
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
            ],
        );
        $process->setTimeout(120)->run();

        return $process;
    };
    $this->calls = fn (): string => file_exists($this->root.'/calls.log') ? file_get_contents($this->root.'/calls.log') : '';
    $this->result = fn (string $name): array => json_decode(file_get_contents($this->root.'/app/bench/results/raw/'.$name.'.json'), true);
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-7 restart-load measures both routes per repeat and writes inside and outside figures', function (): void {
    $process = ($this->restartLoad)([], '--quick', '--modes', 'frankenphp-worker');
    $calls = ($this->calls)();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('/api/ping', '/listings?make=BMW', '--db-url /out/oha.db', '-c 50')
        ->and($calls)->not->toContain('/listings/1')
        ->and(substr_count($calls, 'ddev artisan frankenphp:workers:restart'))->toBe(2 * 3);

    $result = ($this->result)('frankenphp-worker-api-ping');

    expect($result)->toMatchArray(['server' => 'frankenphp-worker', 'route' => '/api/ping', 'repeats' => 3, 'connections' => 50, 'window_tail_s' => 2, 'probe_rps' => 10])
        ->and($result['runs'])->toHaveCount(3)
        ->and($result['runs'][0]['inside'])->toEqual(['requests' => 2, 'non_2xx' => 1, 'p99_ms' => 900])
        ->and($result['runs'][0]['outside'])->toEqual(['requests' => 3, 'non_2xx' => 0, 'p99_ms' => 30])
        ->and($result['median_p99_ms'])->toEqual(['inside' => 900, 'outside' => 30])
        ->and($result['load_non_2xx'])->toBe(['inside' => 3, 'outside' => 0]);

    expect(file_exists($this->root.'/app/bench/results/raw/frankenphp-worker-listings-make-BMW.json'))->toBeTrue();
});

it('F2 restart-load counts connection errors inside the restart windows from the timestamped probe', function (): void {
    $process = ($this->restartLoad)(['BENCH_REPEATS' => '1'], '--quick', '--modes', 'frankenphp-worker');
    $result = ($this->result)('frankenphp-worker-api-ping');
    $probe = $result['runs'][0]['probe'];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($probe['inside']['connection_errors'])->toBeGreaterThan(0)
        ->and($probe['inside']['failures'])->toBe($probe['inside']['connection_errors'])
        ->and($probe['outside']['failures'])->toBe(0)
        ->and($probe['outside']['requests'])->toBeGreaterThan(0)
        ->and($result['failures'])->toBe(['inside' => $probe['inside']['failures'], 'outside' => 0])
        ->and($result['probe_requests']['inside'] + $result['probe_requests']['outside'])->toBe($probe['inside']['requests'] + $probe['outside']['requests']);
});

it('F15 restart-load aggregates the request rows in sqlite instead of reading them into jq', function (): void {
    $script = file_get_contents(base_path('bench/restart-load.sh'));

    expect($script)->toContain('sqlite3 -json')
        ->and($script)->not->toContain('select start, end, duration, status from oha"');
});

it('F18 restart-load switches .env only through bench/lib/env-switch.sh', function (): void {
    $script = file_get_contents(base_path('bench/restart-load.sh'));

    expect($script)->toContain('source "$SCRIPT_DIR/lib/env-switch.sh"', 'env_switch_begin', 'env_switch_restore')
        ->and($script)->not->toContain('cp -p "$ENV_FILE"')
        ->and($script)->not->toContain('set_env_value');
});

it('AC-7 restart-load fires every restart while the load runs, even when oha writes its db only at the end', function (): void {
    $process = ($this->restartLoad)(['BENCH_REPEATS' => '2'], '--quick', '--modes', 'frankenphp-worker');
    $lines = array_values(array_filter(
        explode("\n", ($this->calls)()),
        fn (string $line): bool => $line === 'load-exit' || $line === 'ddev artisan frankenphp:workers:restart',
    ));
    $result = ($this->result)('frankenphp-worker-api-ping');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($lines)->toBe(array_merge(...array_fill(0, 4, ['ddev artisan frankenphp:workers:restart', 'load-exit'])));

    foreach ($result['restarts'] as $restart) {
        expect($restart['window_from_s'])->toBeGreaterThanOrEqual(0)
            ->and($restart['window_to_s'])->toBeLessThanOrEqual($result['measure_s'])
            ->and($restart['window_from_s'])->toBeLessThan($restart['window_to_s']);
    }
});

it('AC-7 restart-load defaults to a 30 s warm-up, 3 repeats of 60 s and 3 restarts, and --quick to 5 s, 30 s, 1 and 1', function (): void {
    $script = file_get_contents(base_path('bench/restart-load.sh'));

    expect($script)->toContain('WARMUP="${BENCH_WARMUP:-30}"', 'MEASURE="${BENCH_MEASURE:-60}"', 'REPEATS="${BENCH_REPEATS:-3}"', 'RESTARTS=3')
        ->and($script)->toContain('WARMUP="${BENCH_WARMUP:-5}"', 'MEASURE="${BENCH_MEASURE:-30}"', 'REPEATS="${BENCH_REPEATS:-1}"', 'RESTARTS=1');
});

it('AC-7 restart-load places restarts at a quarter, half and three quarters of the measure window', function (): void {
    ($this->restartLoad)(['BENCH_REPEATS' => '1'], '--quick', '--modes', 'frankenphp-worker');
    $result = ($this->result)('frankenphp-worker-api-ping');

    expect($result['restart_offsets_s'])->toBe([2])
        ->and($result['restarts'])->toHaveCount(1);

    $quarters = ($this->restartLoad)(['BENCH_REPEATS' => '1', 'BENCH_OUT_DIR' => $this->root.'/full', 'STUB_GIT_STATUS' => ''], '--modes', 'frankenphp-worker');
    $full = json_decode(file_get_contents($this->root.'/full/frankenphp-worker-api-ping.json'), true);

    expect($quarters->getExitCode())->toBe(0, $quarters->getErrorOutput())
        ->and($full['restart_offsets_s'])->toBe([1, 2, 3])
        ->and($full['restarts'])->toHaveCount(3)
        ->and($full['quick'])->toBeFalse();
});

it('AC-7 restart-load records each restart with a host wall-clock duration in ms', function (): void {
    ($this->restartLoad)(['BENCH_REPEATS' => '1'], '--quick', '--modes', 'frankenphp-worker');
    $restarts = ($this->result)('frankenphp-worker-api-ping')['restarts'];

    expect($restarts)->toHaveCount(1)
        ->and($restarts[0])->toHaveKeys(['offset_s', 'start_ms', 'end_ms', 'duration_ms', 'exit_code', 'output'])
        ->and($restarts[0]['duration_ms'])->toBe($restarts[0]['end_ms'] - $restarts[0]['start_ms'])
        ->and($restarts[0]['duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and($restarts[0]['output'])->toBe('restarted in 120 ms')
        ->and($restarts[0]['exit_code'])->toBe(0);
});

it('AC-7 restart-load keeps a failing restart command in the result and carries on', function (): void {
    $process = ($this->restartLoad)(['BENCH_REPEATS' => '1', 'STUB_RESTART_EXIT' => '1'], '--quick', '--modes', 'frankenphp-worker');
    $restarts = ($this->result)('frankenphp-worker-api-ping')['restarts'];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($restarts[0]['exit_code'])->toBe(1);
});

it('AC-7 restart-load restores .env and switches back to the default server on exit', function (): void {
    $process = ($this->restartLoad)(['BENCH_REPEATS' => '1'], '--quick', '--modes', 'frankenphp-worker,frankenphp-octane');
    $lines = array_values(array_filter(explode("\n", ($this->calls)()), fn (string $line): bool => str_starts_with($line, 'ddev server ')));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore)
        ->and(file_exists($this->root.'/app/.env.bench-backup'))->toBeFalse()
        ->and($lines)->toContain('ddev server frankenphp-worker', 'ddev server frankenphp-octane')
        ->and(end($lines))->toBe('ddev server frankenphp');
});

it('AC-7 restart-load restores .env and the server when reading the requests fails', function (): void {
    file_put_contents($this->root.'/bin/sqlite3', "#!/usr/bin/env bash\nexit 1\n");
    chmod($this->root.'/bin/sqlite3', 0755);
    $process = ($this->restartLoad)(['BENCH_REPEATS' => '1'], '--quick', '--modes', 'frankenphp-worker');

    expect($process->getExitCode())->not->toBe(0)
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore)
        ->and(($this->calls)())->toContain('ddev server frankenphp');
});

it('AC-7 restart-load refuses an unknown mode and a dirty tree without --quick', function (): void {
    $mode = ($this->restartLoad)([], '--modes', 'nginx-fpm');
    $dirty = ($this->restartLoad)(['STUB_GIT_STATUS' => ' M app/Foo.php'], '--modes', 'frankenphp-worker');

    expect($mode->getExitCode())->toBe(2)
        ->and($dirty->getExitCode())->toBe(2)
        ->and($dirty->getErrorOutput())->toContain('git working tree has changes')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore);
});
