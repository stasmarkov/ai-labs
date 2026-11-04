<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-export-load-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app');
    $this->envBefore = "APP_ENV=local\nAPP_DEBUG=true\nSECRET=keep\n";
    file_put_contents($this->root.'/app/.env', $this->envBefore);
    $this->body = "id,price\n1,12345.50\n";
    file_put_contents($this->root.'/body.csv', $this->body);
    $this->sha = hash('sha256', $this->body);

    $stubs = [
        'ddev' => <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$*" in
  "server --current") echo "${STUB_SERVER-nginx-fpm}" ;;
  "server --url") echo "https://interview.ddev.site" ;;
  "artisan config:show app.env") echo "${STUB_APP_ENV-production}" ;;
  "artisan config:show app.debug") echo "${STUB_APP_DEBUG-false}" ;;
  "exec test -f "*) if [[ "${STUB_NO_EXPORT-}" == 1 ]]; then exit 1; fi ;;
  "exec stat -c %s "*) echo "${STUB_EXPORT_BYTES-$(wc -c < "$STUB_BODY" | tr -d ' ')}" ;;
  "exec sha256sum "*) echo "${STUB_EXPORT_SHA-$STUB_SHA}  storage/app/exports/listings.csv" ;;
  "exec sh -c cat storage/logs/exports.jsonl"*)
    tag="$(cat "$STUB_COUNTER.tag" 2>/dev/null || true)"
    for i in 1 2 3; do
      echo '{"invocation_id":"x'$i'","export_id":"listings","server":"nginx-fpm","mode":"'"${STUB_PHP_MODE-offload}"'","bench_run":"'"$tag"'","php_held_ms":'$((i * 10))',"peak_memory_bytes":2097152,"cpu_ms":'"${STUB_PHP_CPU-1.5}"',"cpu_scope":"'"${STUB_PHP_CPU_SCOPE-request}"'","bytes_sent_by_php":'"${STUB_PHP_BYTES-0}"',"aborted":false}'
    done
    echo '{"invocation_id":"other","bench_run":"someone-else","php_held_ms":9999,"peak_memory_bytes":999999999,"cpu_ms":1,"bytes_sent_by_php":1,"aborted":true}'
    echo 'not json' ;;
esac
BASH,
        'curl' => <<<'BASH'
#!/usr/bin/env bash
out=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    -o) out="$2"; shift 2 ;;
    -w|-H|--max-time) shift 2 ;;
    *) shift ;;
  esac
done
printf '{"data":{}}' > "$out"
printf '200'
BASH,
        'git' => <<<'BASH'
#!/usr/bin/env bash
case "$*" in
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
    if [[ "$*" == *--name\ export-load-probe-* ]]; then
      echo '{"summary":{"requestsPerSec":5},"latencyPercentiles":{"p50":0.004,"p95":0.02,"p99":0.05},"statusCodeDistribution":{"200":50},"errorDistribution":{"timeout":0}}'
    elif [[ "$*" == *--name\ export-load-clients-* ]]; then
      for arg in "$@"; do
        [[ "$arg" == *:/out ]] && out="${arg%:/out}"
        [[ "$arg" == TAG=* ]] && echo "${arg#TAG=}" > "$STUB_COUNTER.tag"
      done
      for n in 1 2; do
        for seq in 1 2; do
          bytes="${STUB_CLIENT_BYTES-1000}"
          ok=true; size_ok=true
          if [[ "$bytes" != 1000 ]]; then ok=false; size_ok=false; fi
          echo '{"client":'$n',"seq":'$seq',"status":200,"bytes":'"$bytes"',"seconds":2,"size_ok":'$size_ok',"sha_checked":'$([[ $seq == 1 ]] && echo true || echo false)',"sha_ok":true,"ok":'$ok'}' >> "$out/client-$n.jsonl"
        done
      done
    fi ;;
esac
BASH,
    ];
    foreach ($stubs as $name => $script) {
        file_put_contents($this->root.'/bin/'.$name, $script);
        chmod($this->root.'/bin/'.$name, 0755);
    }

    $this->baseEnv = fn (): array => [
        'BENCH_APPROOT' => $this->root.'/app',
        'BENCH_SETTLE' => '0',
        'STUB_LOG' => $this->root.'/calls.log',
        'STUB_COUNTER' => $this->root.'/counter',
        'STUB_BODY' => $this->root.'/body.csv',
        'STUB_SHA' => $this->sha,
        'PATH' => $this->root.'/bin:'.getenv('PATH'),
    ];
    $this->exportLoad = function (array $env, string ...$args): Process {
        $process = new Process(['bash', base_path('bench/export-load.sh'), ...$args], $this->root, $env + ($this->baseEnv)());
        $process->run();

        return $process;
    };
    $this->calls = fn (): string => file_exists($this->root.'/calls.log') ? file_get_contents($this->root.'/calls.log') : '';
    $this->result = fn (string $name, string $dir = 'raw'): array => json_decode(file_get_contents($this->root.'/app/bench/results/'.$dir.'/'.$name.'.json'), true);
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-6 export-load runs rate-limited clients and the shared probe as two capped containers and prints a table', function (): void {
    $process = ($this->exportLoad)([], '--mode', 'offload', '--quick', '--label', 'nginx-fpm');
    $calls = ($this->calls)();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('/healthz', '--name export-load-clients-', '--name export-load-probe-')
        ->and($calls)->toContain('URL=https://interview.ddev.site/exports/listings?mode=offload', 'CLIENTS=20', 'RATE=10M')
        ->and($calls)->toContain('EXPECT_BYTES='.strlen($this->body), 'EXPECT_SHA256='.$this->sha)
        ->and(substr_count($calls, '--cpus=2'))->toBe(2)
        ->and($process->getOutput())->toContain('mode=offload', 'downloads', 'integrity', 'health_p95_ms', 'median', 'health p95 spread');

    $result = ($this->result)('offload-nginx-fpm');

    expect($result)->toMatchArray([
        'server' => 'nginx-fpm', 'mode' => 'offload', 'label' => 'nginx-fpm', 'commit' => 'abc1234',
        'clients' => 20, 'rate' => '10M', 'measure_s' => 15, 'repeats' => 1, 'quick' => true, 'flagged' => false,
    ])
        ->and($result['median']['load_downloads'])->toEqual(4)
        ->and($result['median']['load_errors'])->toEqual(0)
        ->and($result['median']['health_p95_ms'])->toEqual(20)
        ->and($result['runs'])->toHaveCount(1);
});

it('AC-6 export-load reads only the PHP log lines of its own run tag', function (): void {
    $process = ($this->exportLoad)([], '--mode', 'stream', '--quick', '--label', 'x', '--clients', '5');
    $result = ($this->result)('stream-x');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(($this->calls)())->toContain('mode=stream', 'CLIENTS=5')
        ->and($result['median']['php_downloads'])->toEqual(3)
        ->and($result['median']['php_held_ms_p95'])->toEqual(30)
        ->and($result['median']['php_peak_memory_bytes_max'])->toEqual(2097152)
        ->and($result['median']['php_aborted'])->toEqual(0)
        ->and($result['runs'][0]['bench_run'])->toContain('export-load-nginx-fpm-stream-x-');
});

it('AC-6 export-load reports CPU as n/a for a server whose PHP threads share one process, and sums it elsewhere', function (): void {
    $shared = ($this->exportLoad)(['STUB_PHP_CPU' => 'null', 'STUB_PHP_CPU_SCOPE' => 'process'], '--mode', 'stream', '--quick', '--label', 'franken');
    $result = ($this->result)('stream-franken');

    expect($shared->getExitCode())->toBe(0, $shared->getErrorOutput())
        ->and($result['median']['php_cpu_ms_total'])->toBeNull()
        ->and($result['runs'][0]['php']['cpu_scope'])->toBe('process')
        ->and($shared->getOutput())->toContain('n/a (shared process)');

    $own = ($this->exportLoad)([], '--mode', 'stream', '--quick', '--label', 'fpm');

    expect(($this->result)('stream-fpm')['median']['php_cpu_ms_total'])->toEqual(4.5)
        ->and(($this->result)('stream-fpm')['runs'][0]['php']['cpu_scope'])->toBe('request')
        ->and($own->getOutput())->not->toContain('shared process');
});

it('NFR-1 export-load counts a download of the wrong size as an integrity error', function (): void {
    $process = ($this->exportLoad)(['STUB_CLIENT_BYTES' => '999'], '--mode', 'offload', '--quick', '--label', 'bad');
    $result = ($this->result)('offload-bad');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result['median']['load_errors'])->toEqual(4)
        ->and($result['median']['load_integrity_errors'])->toEqual(4)
        ->and($result['median']['load_error_percent'])->toEqual(100)
        ->and($process->getOutput())->toContain('INTEGRITY');
});

it('NFR-1 export-client checks every size and the first sha256 of each client', function (): void {
    $bin = $this->root.'/bin';
    file_put_contents($bin.'/sha256sum', "#!/usr/bin/env bash\nshasum -a 256\n");
    file_put_contents($bin.'/curl', <<<'BASH'
#!/usr/bin/env bash
meta=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    -w) meta="${2#*%output\{}"; meta="${meta%%\}*}"; shift 2 ;;
    -o) out="$2"; shift 2 ;;
    --limit-rate|--max-time|-H) shift 2 ;;
    *) shift ;;
  esac
done
sleep 0.4
printf '200 %s 0.4' "$(wc -c < "$STUB_BODY" | tr -d ' ')" > "$meta"
[[ "$out" == "-" ]] && cat "$STUB_BODY"
exit 0
BASH);
    chmod($bin.'/sha256sum', 0755);
    chmod($bin.'/curl', 0755);
    mkdir($this->root.'/out');

    $run = function (string $expectSha, int $expectBytes): array {
        (new Process(['rm', '-rf', $this->root.'/out']))->run();
        mkdir($this->root.'/out');
        $process = new Process(['sh', base_path('bench/lib/export-client.sh')], $this->root, ($this->baseEnv)() + [
            'URL' => 'http://x/exports/listings?mode=offload', 'TAG' => 't', 'CLIENTS' => '2', 'DURATION' => '1', 'RATE' => '1M',
            'MAX_TIME' => '5', 'EXPECT_BYTES' => (string) $expectBytes, 'EXPECT_SHA256' => $expectSha, 'OUT' => $this->root.'/out',
        ]);
        $process->mustRun();

        return array_map(
            fn (string $line): array => json_decode($line, true),
            array_merge(...array_map(fn (string $f): array => file($f, FILE_IGNORE_NEW_LINES), glob($this->root.'/out/client-*.jsonl'))),
        );
    };

    $good = $run($this->sha, strlen($this->body));
    expect($good)->not->toBeEmpty()
        ->and(array_column($good, 'ok'))->each->toBeTrue()
        ->and(count(array_filter($good, fn (array $d): bool => $d['sha_checked'])))->toBe(2);

    $wrongSha = $run(str_repeat('0', 64), strlen($this->body));
    expect(array_filter($wrongSha, fn (array $d): bool => $d['sha_checked'] && ! $d['ok']))->toHaveCount(2)
        ->and(array_filter($wrongSha, fn (array $d): bool => ! $d['sha_checked'] && $d['ok']))->not->toBeEmpty();

    $wrongSize = $run($this->sha, strlen($this->body) + 1);
    expect(array_column($wrongSize, 'size_ok'))->each->toBeFalse()
        ->and(array_column($wrongSize, 'ok'))->each->toBeFalse();
});

it('AC-6 export-load refuses to run without the export file and restores .env', function (): void {
    $process = ($this->exportLoad)(['STUB_NO_EXPORT' => '1'], '--mode', 'offload', '--quick');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Run condition failed', 'exports:generate')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore)
        ->and(file_exists($this->root.'/app/.env.bench-backup'))->toBeFalse();
});

it('AC-6 export-load sets the run conditions in .env and puts it back afterwards', function (): void {
    $process = ($this->exportLoad)([], '--mode', 'offload', '--quick');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore)
        ->and(($this->calls)())->toContain('ddev artisan optimize');
});

it('AC-6 export-load rejects a bad mode, label and rate', function (): void {
    expect(($this->exportLoad)([], '--mode', 'sideways')->getExitCode())->toBe(2)
        ->and(($this->exportLoad)([], '--mode', 'stream', '--label', 'Bad Label')->getExitCode())->toBe(2)
        ->and(($this->exportLoad)([], '--mode', 'stream', '--rate', 'fast')->getExitCode())->toBe(2);
});

it('AC-6 export-load matrix lists 8 cells ending on nginx-fpm', function (): void {
    $process = new Process(['bash', base_path('bench/export-load-matrix.sh'), '--dry-run', '--quick']);
    $process->mustRun();
    $lines = array_values(array_filter(explode("\n", $process->getOutput())));
    $cells = array_filter($lines, fn (string $l): bool => str_contains($l, 'export-load.sh'));

    expect($cells)->toHaveCount(8)
        ->and(implode("\n", $cells))->toContain('--mode offload --label apache-fpm --quick', '--mode stream --label openlitespeed --quick')
        ->and(end($lines))->toBe('ddev server nginx-fpm')
        ->and(implode("\n", $lines))->toContain('ddev server frankenphp');
});

it('MNC-4 slow-load and export-load share one probe library instead of copies', function (): void {
    $slowLoad = file_get_contents(base_path('bench/slow-load.sh'));
    $exportLoad = file_get_contents(base_path('bench/export-load.sh'));

    expect($slowLoad)->toContain('lib/health-probe.sh')->not->toContain('parse_oha() {')
        ->and($exportLoad)->toContain('lib/health-probe.sh')->not->toContain('parse_oha() {')
        ->and(file_get_contents(base_path('bench/lib/health-probe.sh')))->toContain('parse_oha() {', 'generate_probe() {');
});

it('AC-6 export-load defaults match the documented run: 2 MiB/s, 60 s window, 3 repeats, quick 10M for 15 s', function (): void {
    $script = file_get_contents(base_path('bench/export-load.sh'));
    $spec = file_get_contents(base_path('.ai/specs/spec-20261104-1944-export-offload/spec-lite.md'));
    $benchmarks = file_get_contents(base_path('BENCHMARKS.md'));

    expect($script)->toContain('MEASURE="${BENCH_MEASURE:-60}"', 'REPEATS="${BENCH_REPEATS:-3}"', 'rate="${BENCH_RATE:-2M}"')
        ->toContain('MEASURE="${BENCH_MEASURE:-15}"', 'rate="${BENCH_RATE:-10M}"')
        ->and($spec)->toContain('--rate 2M', '60 s', 'no warm-up', '`--quick`:** 15 s, 1 repeat, `--rate 10M`')
        ->and($benchmarks)->toContain('2 MiB/s')->not->toContain('2 MB/s');
});
