<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->jq = (new ExecutableFinder)->find('jq');
    if ($this->jq === null) {
        $this->markTestSkipped('jq is not installed');
    }

    $this->root = sys_get_temp_dir().'/bench-llm-load-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app');
    $this->env = "APP_ENV=local\nOLLAMA_URL=http://host.docker.internal:11434\nAI_DESCRIBE_PER_MINUTE=20\n";
    file_put_contents($this->root.'/app/.env', $this->env);

    $stubs = [
        'ddev' => <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$*" in
  "server --current") echo "${STUB_SERVER-frankenphp}" ;;
  "server --default") echo "${STUB_DEFAULT-frankenphp}" ;;
  "server --url") echo "https://ai-lab.ddev.site" ;;
  "artisan config:show ai.providers.ollama.num_parallel") echo "  ${STUB_PARALLEL-null}" ;;
esac
BASH,
        'curl' => <<<'BASH'
#!/usr/bin/env bash
echo "curl $*" >> "$STUB_LOG"
echo 200
BASH,
        'docker' => <<<'BASH'
#!/usr/bin/env bash
case "$1" in
  pull) ;;
  image) echo "grafana/k6@sha256:k6digest" ;;
  inspect) echo 172.19.0.9 ;;
  rm|kill) echo "docker $*" >> "$STUB_LOG" ;;
  ps) ;;
  run)
    echo "docker $*" >> "$STUB_LOG"
    grep -E '^AI_DESCRIBE_(PROVIDER|MODEL|PER_MINUTE)=' "$STUB_APP/.env" | paste -sd' ' - >> "$STUB_LOG"
    out=""; iterations=""; vus=""
    while [ $# -gt 0 ]; do
      case "$1" in
        -v) case "$2" in *:/out) out="${2%:/out}" ;; esac; shift 2 ;;
        -e) case "$2" in ITERATIONS=*) iterations="${2#ITERATIONS=}" ;; VUS=*) vus="${2#VUS=}" ;; esac; shift 2 ;;
        *) shift ;;
      esac
    done
    [ -n "${STUB_SLOW-}" ] && sleep "$STUB_SLOW"
    events=$((iterations * 10))
    cat > "$out/k6.json" <<JSON
{"metrics":{
 "stream_failed":{"passes":${STUB_FAILED-0},"fails":$((iterations - ${STUB_FAILED-0})),"value":0},
 "stream_done_ms":{"med":4000,"p(95)":9000},
 "stream_ttfb_ms":{"med":800,"p(95)":1500},
 "stream_events_per_s":{"med":12.5},
 "stream_text_events_total":{"count":$events},
 "stream_rate_limited":{"count":0},
 "stream_unreachable":{"count":${STUB_UNREACHABLE-0}},
 "stream_started_at_ms":{"min":1000},
 "stream_ended_at_ms":{"max":11000}
}}
JSON
    ;;
esac
BASH,
        'run-stub' => <<<'BASH'
#!/usr/bin/env bash
label=""; quick=0
while [ $# -gt 0 ]; do
  case "$1" in --label) label="$2"; shift 2 ;; --quick) quick=1; shift ;; *) shift ;; esac
done
echo "run.sh search label=$label quick=$quick" >> "$STUB_LOG"
grep -E '^AI_DESCRIBE_(PROVIDER|MODEL|PER_MINUTE)=' "$STUB_APP/.env" | paste -sd' ' - >> "$STUB_LOG"
[ -e "$STUB_APP/.env.bench-backup" ] && echo "run.sh saw a stale bench backup" >> "$STUB_LOG"
dir="$STUB_APP/bench/results/raw/2026-11-13/x"
mkdir -p "$dir"
if [ "$label" = "llm-load" ]; then p95s="300 100 200"; else p95s="120 100 110"; fi
[ -n "${STUB_RUN_FAIL-}" ] && [ "$label" = "llm-load" ] && exit 1
n=0
echo "Wrote 3 result file(s) to $dir (spread 1%)"
for p95 in $p95s; do
  n=$((n + 1))
  file="$dir/search-$label-$n.json"
  echo "{\"rps\":$((p95 * 2)),\"p50_ms\":50,\"p95_ms\":$p95,\"p99_ms\":400,\"error_percent\":0,\"warmup_s\":30,\"measure_s\":60}" > "$file"
  echo "$file"
done
BASH,
    ];
    foreach ($stubs as $name => $script) {
        file_put_contents($this->root.'/bin/'.$name, $script);
        chmod($this->root.'/bin/'.$name, 0755);
    }

    $this->load = function (array $env, string ...$args): Process {
        $process = new Process(
            ['bash', base_path('bench/llm-load.sh'), ...$args],
            $this->root,
            $env + [
                'BENCH_APPROOT' => $this->root.'/app',
                'BENCH_DATE' => '2026-11-13',
                'DDEV_SITENAME' => 'ai-lab',
                'STUB_LOG' => $this->root.'/calls.log',
                'STUB_APP' => $this->root.'/app',
                'PATH' => $this->root.'/bin:'.dirname($this->jq).':/usr/bin:/bin',
            ],
        );
        $process->setTimeout(60);
        $process->run();

        return $process;
    };
    $this->file = $this->root.'/app/bench/results/llm-load/2026-11-13/concurrency.json';
    $this->searchFile = $this->root.'/app/bench/results/llm-load/2026-11-13/search-under-load.json';
    $this->calls = fn (): string => is_file($this->root.'/calls.log') ? file_get_contents($this->root.'/calls.log') : '';
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-5 concurrency runs levels 1, 4 and 16 with max(8, 2 x level) streams per model and writes the figures', function (): void {
    $process = ($this->load)(['STUB_PARALLEL' => '2'], 'concurrency', '--models=qwen3.5:9b,gemma4:12b');
    $result = json_decode(file_get_contents($this->file), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result)->toMatchArray(['mode' => 'concurrency', 'date' => '2026-11-13', 'server' => 'frankenphp', 'quick' => false, 'ollama_num_parallel' => 2])
        ->and(array_column($result['models'], 'model'))->toBe(['qwen3.5:9b', 'gemma4:12b'])
        ->and(array_column($result['models'][0]['levels'], 'level'))->toBe([1, 4, 16])
        ->and(array_column($result['models'][0]['levels'], 'streams'))->toBe([8, 8, 32])
        ->and($result['models'][0]['levels'][1])->toMatchArray([
            'ttft_p50_ms' => 800, 'ttft_p95_ms' => 1500, 'done_p50_ms' => 4000, 'done_p95_ms' => 9000,
            'events_per_s_per_stream_median' => 12.5, 'failed_share' => 0, 'rate_limited' => 0,
        ])
        ->and($result['models'][0]['levels'][1]['events_per_s_aggregate'])->toEqual(8)
        ->and($result['models'][0]['levels'][2]['events_per_s_aggregate'])->toEqual(32)
        ->and($result['k6_image_digest'])->toBe('grafana/k6@sha256:k6digest')
        ->and($process->getOutput())->toContain('qwen3.5:9b', 'gemma4:12b');
});

it('AC-5 concurrency records the default when OLLAMA_NUM_PARALLEL is unset', function (): void {
    ($this->load)([], 'concurrency', '--quick', '--models=qwen3.5:9b');
    $result = json_decode(file_get_contents($this->file), true);

    expect($result['ollama_num_parallel'])->toBe('default')
        ->and($result['ollama_num_parallel_note'])->toContain('not readable from the app');
});

it('AC-5 concurrency switches the model, provider and describe limit through .env for each model', function (): void {
    ($this->load)([], 'concurrency', '--quick', '--models=qwen3.5:9b,gemma4:12b');
    $calls = ($this->calls)();

    expect($calls)->toContain('AI_DESCRIBE_PROVIDER=ollama AI_DESCRIBE_MODEL="qwen3.5:9b" AI_DESCRIBE_PER_MINUTE=1000')
        ->and($calls)->toContain('AI_DESCRIBE_PROVIDER=ollama AI_DESCRIBE_MODEL="gemma4:12b" AI_DESCRIBE_PER_MINUTE=1000')
        ->and($calls)->toContain('--network ddev_default', 'ITERATIONS=', 'REQUEST_TIMEOUT=300s', 'k6-sse.js');
});

it('AC-5 concurrency --quick runs levels 1 and 4 with 4 streams each', function (): void {
    $process = ($this->load)([], 'concurrency', '--quick', '--models=qwen3.5:9b');
    $result = json_decode(file_get_contents($this->file), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result['quick'])->toBeTrue()
        ->and(array_column($result['models'][0]['levels'], 'level'))->toBe([1, 4])
        ->and(array_column($result['models'][0]['levels'], 'streams'))->toBe([4, 4]);
});

it('AC-5 concurrency reports the failure share of incomplete streams', function (): void {
    ($this->load)(['STUB_FAILED' => '2'], 'concurrency', '--quick', '--models=qwen3.5:9b');
    $level = json_decode(file_get_contents($this->file), true)['models'][0]['levels'][0];

    expect($level['incomplete_streams'])->toBe(2)
        ->and($level['failed_share'])->toBe(0.5);
});

it('AC-5 k6-sse.js gains ITERATIONS and keeps the duration run without it', function (): void {
    $script = file_get_contents(base_path('bench/k6-sse.js'));

    expect($script)->toContain('__ENV.ITERATIONS', 'shared-iterations')
        ->and($script)->toContain("summaryTrendStats: real ? ['avg', 'min', 'med', 'p(95)', 'p(99)', 'max'] : ['avg', 'med', 'p(95)', 'p(99)']")
        ->and($script)->toContain("executor: 'constant-vus', vus, duration, gracefulStop: '30s'")
        ->and($script)->toContain("__ENV.DURATION || '60s'", "__ENV.REQUEST_TIMEOUT || '60s'");
});

it('MNC-3 k6-sse.js keeps the old trend stats in duration mode and adds min and max only in real mode', function (): void {
    $script = file_get_contents(base_path('bench/k6-sse.js'));

    expect($script)->toContain("['avg', 'med', 'p(95)', 'p(99)']")
        ->and($script)->toContain('real ? [')
        ->and($script)->not->toContain("summaryTrendStats: ['avg', 'min'");
});

it('MNC-4 NFR-1 .env is byte-identical after a run and no backup is left', function (): void {
    $process = ($this->load)([], 'concurrency', '--quick', '--models=qwen3.5:9b,gemma4:12b');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->env)
        ->and($this->root.'/app/.env.bench-backup')->not->toBeFile()
        ->and(($this->calls)())->toContain('ddev artisan optimize:clear');
});

it('MNC-4 NFR-1 .env is restored and the k6 container removed when the run is interrupted', function (): void {
    $process = new Process(
        ['bash', base_path('bench/llm-load.sh'), 'concurrency', '--quick', '--models=qwen3.5:9b'],
        $this->root,
        [
            'BENCH_APPROOT' => $this->root.'/app', 'BENCH_DATE' => '2026-11-13', 'DDEV_SITENAME' => 'ai-lab',
            'STUB_LOG' => $this->root.'/calls.log', 'STUB_APP' => $this->root.'/app', 'STUB_SLOW' => '2',
            'PATH' => $this->root.'/bin:'.dirname($this->jq).':/usr/bin:/bin',
        ],
    );
    $process->setTimeout(60);
    $process->start();
    $deadline = microtime(true) + 20;
    while (! str_contains(($this->calls)(), 'ITERATIONS=') && microtime(true) < $deadline) {
        usleep(50_000);
    }
    expect(file_get_contents($this->root.'/app/.env'))->toContain('AI_DESCRIBE_MODEL="qwen3.5:9b"');

    $process->signal(SIGINT);
    $process->wait();

    expect($process->getExitCode())->toBe(130)
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->env)
        ->and($this->root.'/app/.env.bench-backup')->not->toBeFile()
        ->and($this->file)->not->toBeFile()
        ->and(($this->calls)())->toContain('docker rm -f llm-load-k6-');
});

it('NFR-1 a stale .env.bench-backup stops the run with exit 2 before any load', function (): void {
    file_put_contents($this->root.'/app/.env.bench-backup', 'stale');
    $process = ($this->load)([], 'concurrency', '--quick', '--models=qwen3.5:9b');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Stale')
        ->and(($this->calls)())->not->toContain('docker run')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->env)
        ->and(file_get_contents($this->root.'/app/.env.bench-backup'))->toBe('stale');
});

it('NFR-1 the script never touches .ddev/config.yaml', function (): void {
    $script = file_get_contents(base_path('bench/llm-load.sh'));

    expect($script)->not->toContain('config.yaml');
});

it('AC-5 an existing day file is refused with exit 2 unless --force is given', function (): void {
    ($this->load)([], 'concurrency', '--quick', '--models=qwen3.5:9b');
    $second = ($this->load)([], 'concurrency', '--quick', '--models=qwen3.5:9b');
    $forced = ($this->load)([], 'concurrency', '--quick', '--models=qwen3.5:9b', '--force');

    expect($second->getExitCode())->toBe(2)
        ->and($second->getErrorOutput())->toContain('--force')
        ->and($forced->getExitCode())->toBe(0, $forced->getErrorOutput());
});

it('MNC-3 llm-load.sh prints usage for --help and rejects unknown modes', function (): void {
    $help = ($this->load)([], '--help');
    $bad = ($this->load)([], 'nonsense');

    expect($help->getExitCode())->toBe(0)
        ->and($help->getOutput())->toContain('Usage: bench/llm-load.sh concurrency')
        ->and($bad->getExitCode())->toBe(2);
});

it('AC-6 search-under-load records the median of both runs, the p95 delta and the completed describes', function (): void {
    $process = ($this->load)(['BENCH_RUN_SCRIPT' => $this->root.'/bin/run-stub'], 'search-under-load', '--quick');
    $result = json_decode(file_get_contents($this->searchFile), true);
    $entry = $result['servers'][0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result)->toMatchArray(['mode' => 'search-under-load', 'date' => '2026-11-13', 'starting_server' => 'frankenphp', 'quick' => true, 'describe_model' => 'qwen3.5:9b'])
        ->and($entry['server'])->toBe('frankenphp')
        ->and($entry['without_load'])->toMatchArray(['rps' => 220, 'p50_ms' => 50, 'p95_ms' => 110, 'p99_ms' => 400, 'error_percent' => 0, 'repeats' => 3, 'warmup_s' => 30, 'measure_s' => 60])
        ->and($entry['with_load'])->toMatchArray(['rps' => 400, 'p95_ms' => 200])
        ->and($entry['p95_delta_ms'])->toEqual(90)
        ->and($entry['describes'])->toMatchArray(['streams_held' => 4, 'incomplete' => 0])
        ->and($entry['describes']['completed'])->toBeGreaterThan(0)
        ->and($entry['describes'])->toHaveKeys(['elapsed_s', 'completed_per_minute'])
        ->and($process->getOutput())->toContain('frankenphp');
});

it('AC-6 search-under-load holds 4 describe streams only around the load run, with the describe model set', function (): void {
    ($this->load)(['BENCH_RUN_SCRIPT' => $this->root.'/bin/run-stub'], 'search-under-load', '--quick');
    $calls = ($this->calls)();

    expect($calls)->toContain('VUS=4', 'ITERATIONS=', 'REQUEST_TIMEOUT=300s', 'k6-sse.js')
        ->and($calls)->toContain('run.sh search label=llm-noload quick=1', 'run.sh search label=llm-load quick=1')
        ->and($calls)->toContain('AI_DESCRIBE_PROVIDER=ollama AI_DESCRIBE_MODEL="qwen3.5:9b" AI_DESCRIBE_PER_MINUTE=1000')
        ->and(strpos($calls, 'llm-noload'))->toBeLessThan(strpos($calls, 'docker run'))
        ->and(strpos($calls, 'docker run'))->toBeLessThan(strpos($calls, 'label=llm-load'))
        ->and(strpos($calls, 'label=llm-load'))->toBeLessThan(strpos($calls, 'docker kill'))
        ->and($calls)->toContain('docker rm -f llm-load-k6-')
        ->and($calls)->not->toContain('run.sh saw a stale bench backup');
});

it('AC-6 search-under-load covers all three servers in full mode and restores the starting one', function (): void {
    $process = ($this->load)(['BENCH_RUN_SCRIPT' => $this->root.'/bin/run-stub'], 'search-under-load');
    $result = json_decode(file_get_contents($this->searchFile), true);
    $switches = array_values(array_filter(explode("\n", ($this->calls)()), fn (string $line): bool => str_starts_with($line, 'ddev server ') && ! str_contains($line, '--')));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_column($result['servers'], 'server'))->toBe(['frankenphp', 'frankenphp-worker', 'frankenphp-octane'])
        ->and($result['quick'])->toBeFalse()
        ->and($switches)->toBe(['ddev server frankenphp-worker', 'ddev server frankenphp-octane', 'ddev server frankenphp'])
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->env);
});

it('MNC-4 the full search-under-load run refuses to start when the active server is not the default one', function (): void {
    $process = ($this->load)(['BENCH_RUN_SCRIPT' => $this->root.'/bin/run-stub', 'STUB_SERVER' => 'frankenphp-worker'], 'search-under-load');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('ddev server --default')
        ->and(($this->calls)())->not->toContain('docker run')
        ->and(($this->calls)())->not->toContain('ddev server frankenphp')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->env);
});

it('MNC-4 NFR-1 search-under-load restores .env, the server and the k6 container when run.sh fails', function (): void {
    $process = ($this->load)(['BENCH_RUN_SCRIPT' => $this->root.'/bin/run-stub', 'STUB_RUN_FAIL' => '1', 'STUB_SERVER' => 'frankenphp-worker'], 'search-under-load', '--quick');

    expect($process->getExitCode())->not->toBe(0)
        ->and($this->searchFile)->not->toBeFile()
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->env)
        ->and($this->root.'/app/.env.llm-load-backup')->not->toBeFile()
        ->and(($this->calls)())->toContain('docker rm -f llm-load-k6-');
});

it('NFR-1 search-under-load stops with exit 2 on a stale .env.bench-backup before any load', function (): void {
    file_put_contents($this->root.'/app/.env.bench-backup', 'stale');
    $process = ($this->load)(['BENCH_RUN_SCRIPT' => $this->root.'/bin/run-stub'], 'search-under-load', '--quick');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Stale')
        ->and(($this->calls)())->not->toContain('docker run')
        ->and(($this->calls)())->not->toContain('run.sh search')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->env);
});

it('MNC-3 search-under-load only calls run.sh and leaves .ddev/config.yaml alone', function (): void {
    $script = file_get_contents(base_path('bench/llm-load.sh'));

    expect($script)->toContain('search ${quick_flag[@]+"${quick_flag[@]}"} --label')
        ->and($script)->not->toContain('config.yaml')
        ->and($script)->not->toContain('concurrency.sh');
});

it('AC-5 --models is checked against the Ollama tag characters before anything is written to .env', function (string $models): void {
    $process = ($this->load)([], 'concurrency', '--quick', '--models='.$models);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Invalid model name')
        ->and(($this->calls)())->not->toContain('docker run')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->env)
        ->and($this->root.'/app/.env.bench-backup')->not->toBeFile();
})->with([
    'newline' => ["qwen3.5:9b\nAPP_KEY=x"],
    'space' => ['qwen3.5:9b gemma'],
    'dollar' => ['$(id)'],
    'quote' => ['qwen"x'],
    'hash' => ['qwen#x'],
]);

it('AC-5 the cold-load warm-up describe uses a listing outside the ids the first measured stream starts from', function (): void {
    ($this->load)([], 'concurrency', '--quick', '--models=qwen3.5:9b');
    $calls = ($this->calls)();

    expect($calls)->toContain('/listings/50000/describe')
        ->and($calls)->not->toContain('/listings/1/describe');
});

it('AC-6 the describe loop reaches the web container by name and does not pin a router address', function (): void {
    ($this->load)(['BENCH_RUN_SCRIPT' => $this->root.'/bin/run-stub'], 'search-under-load', '--quick');
    $loop = collect(explode("\n", ($this->calls)()))->first(fn (string $line): bool => str_starts_with($line, 'docker run') && str_contains($line, 'VUS=4'));

    expect($loop)->toContain('-d ', 'BASE_URL=http://ddev-ai-lab-web', 'HOST_HEADER=ai-lab.ddev.site', '--network ddev_default')
        ->and($loop)->not->toContain('--add-host')
        ->and($loop)->not->toContain('172.19.0.9');
});

it('AC-6 search-under-load refuses to write when the loop completed no describe', function (): void {
    $process = ($this->load)(['BENCH_RUN_SCRIPT' => $this->root.'/bin/run-stub', 'STUB_FAILED' => '1000000'], 'search-under-load', '--quick');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('did not load frankenphp', '0 describes completed')
        ->and($this->searchFile)->not->toBeFile()
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->env)
        ->and($this->root.'/app/.env.llm-load-backup')->not->toBeFile()
        ->and(($this->calls)())->toContain('docker rm -f llm-load-k6-');
});

it('AC-6 search-under-load refuses to write when half or more of the loop streams were incomplete', function (): void {
    $process = ($this->load)(['BENCH_RUN_SCRIPT' => $this->root.'/bin/run-stub', 'STUB_FAILED' => '500000'], 'search-under-load', '--quick');
    $ok = ($this->load)(['BENCH_RUN_SCRIPT' => $this->root.'/bin/run-stub', 'STUB_FAILED' => '499999'], 'search-under-load', '--quick');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('did not load frankenphp')
        ->and($ok->getExitCode())->toBe(0, $ok->getErrorOutput())
        ->and($this->searchFile)->toBeFile();
});

it('AC-5 k6-sse.js counts the per-stream rate from the first byte to [DONE] and can reach a container by name', function (): void {
    $script = file_get_contents(base_path('bench/k6-sse.js'));

    expect($script)->toContain('response.timings.duration - response.timings.waiting')
        ->and($script)->toContain('events / (generationMs / 1000)')
        ->and($script)->toContain('HOST_HEADER')
        ->and($script)->toContain("dns: { ttl: '1s' }")
        ->and($script)->not->toContain('events / (response.timings.duration / 1000)');
});

it('AC-6 search-under-load leaves attempts made while the server restarts out of the incomplete share', function (): void {
    $refused = ($this->load)(['BENCH_RUN_SCRIPT' => $this->root.'/bin/run-stub', 'STUB_FAILED' => '600000', 'STUB_UNREACHABLE' => '50000'], 'search-under-load', '--quick');
    $ok = ($this->load)(['BENCH_RUN_SCRIPT' => $this->root.'/bin/run-stub', 'STUB_FAILED' => '600000', 'STUB_UNREACHABLE' => '300000'], 'search-under-load', '--quick');
    $describes = json_decode((string) file_get_contents($this->searchFile), true)['servers'][0]['describes'];

    expect($refused->getExitCode())->toBe(1)
        ->and($ok->getExitCode())->toBe(0, $ok->getErrorOutput())
        ->and($describes['incomplete'])->toBe(300000)
        ->and($describes['unreachable_attempts'])->toBe(300000);
});
