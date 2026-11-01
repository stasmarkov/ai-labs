<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-run-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app');
    file_put_contents($this->root.'/app/.env', "APP_ENV=local\nAPP_DEBUG=true\nDEBUG_ROUTES=false\nSECRET=keep\n");

    $stubs = [
        'ddev' => <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
if [[ -n "${STUB_DDEV_FAIL-}" && "$*" == *"$STUB_DDEV_FAIL"* ]]; then
  exit 1
fi
case "$*" in
  "server --current") echo nginx-fpm ;;
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
if [[ "$url" == */listings* ]]; then
  printf '{"meta":{"last_page":%s}}' "${STUB_LAST_PAGE-120}" > "$out"
  printf '200'
  exit 0
fi
printf '{"data":{"sapi":"fpm-fcgi","php_version":"8.4.1","opcache_enabled":%s,"xdebug_loaded":%s,"server_software":"nginx"}}' "${STUB_OPCACHE-true}" "${STUB_XDEBUG-false}" > "$out"
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
  stats) printf 'ddev-interview-web\t120.5MiB / 15GiB\t42.0%%\nddev-interview-db\t300MiB / 15GiB\t10.0%%\n' ;;
  pull) ;;
  image) [[ -n "${STUB_NO_DIGEST-}" ]] && exit 1; echo "stub/image@sha256:abc" ;;
  run)
    if [[ "$*" == *oha* ]]; then
      rps=1234.5
      if [[ -n "${STUB_RPS_SEQ-}" ]]; then
        n=$(( $(cat "$STUB_RPS_SEQ.n" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$STUB_RPS_SEQ.n"
        rps=$(cut -d, -f"$n" "$STUB_RPS_SEQ")
      fi
      echo '{"summary":{"requestsPerSec":'"$rps"'},"latencyPercentiles":{"p50":0.01,"p95":0.02,"p99":0.03},"statusCodeDistribution":{"200":90,"500":10},"errorDistribution":{"timeout":0}}'
    fi ;;
esac
BASH,
    ];
    foreach ($stubs as $name => $script) {
        file_put_contents($this->root.'/bin/'.$name, $script);
        chmod($this->root.'/bin/'.$name, 0755);
    }

    $this->bench = function (array $env, string ...$args): Process {
        $process = new Process(
            ['bash', base_path('bench/run.sh'), ...$args],
            $this->root,
            $env + [
                'BENCH_APPROOT' => $this->root.'/app',
                'BENCH_SETTLE' => '0',
                'BENCH_WARMUP' => '0',
                'BENCH_MEASURE' => '1',
                'STUB_LOG' => $this->root.'/calls.log',
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
            ],
        );
        $process->run();

        return $process;
    };
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-3 run refuses when a run condition fails', function (string $check, array $env): void {
    $process = ($this->bench)($env, 'ping', '--quick');
    $log = file_exists($this->root.'/calls.log') ? file_get_contents($this->root.'/calls.log') : '';

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain($check)
        ->and($log)->not->toContain('docker run')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe("APP_ENV=local\nAPP_DEBUG=true\nDEBUG_ROUTES=false\nSECRET=keep\n")
        ->and(file_exists($this->root.'/app/.env.bench-backup'))->toBeFalse();
})->with([
    'app env' => ['APP_ENV', ['STUB_APP_ENV' => 'local']],
    'app debug' => ['APP_DEBUG', ['STUB_APP_DEBUG' => 'true']],
    'config cache' => ['config cache', ['STUB_DDEV_FAIL' => 'bootstrap/cache/config.php']],
    'route cache' => ['route cache', ['STUB_DDEV_FAIL' => 'bootstrap/cache/routes']],
    'view cache' => ['view cache', ['STUB_DDEV_FAIL' => 'storage/framework/views']],
    'opcache' => ['OPcache', ['STUB_OPCACHE' => 'false']],
    'xdebug' => ['Xdebug', ['STUB_XDEBUG' => 'true']],
    'git tree' => ['git working tree', ['STUB_GIT_STATUS' => ' M app/Models/User.php']],
]);

it('AC-1 run writes one result file per run and restores the env', function (): void {
    $process = ($this->bench)([], 'ping', '--quick');
    $files = glob($this->root.'/app/bench/results/raw/*/nginx-fpm/ping-*.json') ?: [];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($files)->toHaveCount(1);

    $result = json_decode(file_get_contents($files[0]), true);

    expect($result)->toMatchArray(['server' => 'nginx-fpm', 'scenario' => 'ping', 'commit' => 'abc1234'])
        ->and($result['rps'])->toEqual(1234.5)
        ->and($result['p50_ms'])->toEqual(10)
        ->and($result['error_percent'])->toEqual(10)
        ->and($result['peak_memory_mib'])->toHaveKeys(['web', 'db'])
        ->and($result['avg_cpu_percent'])->toHaveKeys(['web', 'db'])
        ->and(file_get_contents($this->root.'/calls.log'))->toContain('--add-host interview.ddev.site:172.19.0.2', '--cpus=2')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe("APP_ENV=local\nAPP_DEBUG=true\nDEBUG_ROUTES=false\nSECRET=keep\n")
        ->and(file_exists($this->root.'/app/.env.bench-backup'))->toBeFalse();
});

it('AC-1 a registry image is pulled before its digest is read and the digest is recorded', function (): void {
    $process = ($this->bench)(['BENCH_OHA_IMAGE' => 'example.com/oha:1.0'], 'ping', '--quick');
    $files = glob($this->root.'/app/bench/results/raw/*/nginx-fpm/ping-*.json') ?: [];
    $log = file_get_contents($this->root.'/calls.log');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(strpos($log, 'docker pull'))->not->toBeFalse()
        ->and(strpos($log, 'docker pull'))->toBeLessThan(strpos($log, 'docker image inspect'))
        ->and(strpos($log, 'docker image inspect'))->toBeLessThan(strpos($log, 'docker run'))
        ->and(json_decode(file_get_contents($files[0]), true)['image_digest'])->toBe('stub/image@sha256:abc');
});

it('AC-1 run refuses when the image digest is unknown', function (): void {
    $process = ($this->bench)(['STUB_NO_DIGEST' => '1'], 'ping', '--quick');
    $log = file_get_contents($this->root.'/calls.log');

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('digest')
        ->and($log)->not->toContain('docker run')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe("APP_ENV=local\nAPP_DEBUG=true\nDEBUG_ROUTES=false\nSECRET=keep\n");
});

it('AC-1 search refuses when make=BMW has fewer than 100 pages', function (): void {
    $process = ($this->bench)(['STUB_LAST_PAGE' => '50'], 'search', '--quick');
    $log = file_get_contents($this->root.'/calls.log');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('make=BMW', '50')
        ->and($log)->not->toContain('docker run');
});

it('AC-1 search proceeds when make=BMW has at least 100 pages', function (): void {
    $process = ($this->bench)(['STUB_LAST_PAGE' => '100'], 'search', '--quick');

    expect($process->getErrorOutput())->not->toContain('make=BMW');
});

it('AC-1 seeded data gives make=BMW at least 100 pages at the default page size', function (): void {
    $this->seed();

    $this->getJson('/listings?make=BMW')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 20);

    expect($this->getJson('/listings?make=BMW')->json('meta.last_page'))->toBeGreaterThanOrEqual(100);
});

it('AC-2 run reruns once when the spread is above 5 percent and discards the first set', function (): void {
    file_put_contents($this->root.'/seq', '1000,1200,800,1000,1010,990');
    $process = ($this->bench)(['BENCH_REPEATS' => '3', 'STUB_RPS_SEQ' => $this->root.'/seq'], 'ping');
    $files = glob($this->root.'/app/bench/results/raw/*/nginx-fpm/ping-*.json') ?: [];
    sort($files);
    $results = array_map(fn (string $file): array => json_decode(file_get_contents($file), true), $files);
    $discarded = array_values(array_filter($results, fn (array $result): bool => ($result['discarded'] ?? false) === true));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($files)->toHaveCount(6)
        ->and($discarded)->toHaveCount(3)
        ->and(array_unique(array_column($discarded, 'set')))->toBe([1])
        ->and(array_unique(array_column(array_filter($results, fn (array $result): bool => ! isset($result['discarded'])), 'set')))->toBe([2]);
});

it('AC-2 run keeps a single set when the spread is within 5 percent', function (): void {
    file_put_contents($this->root.'/seq', '1000,1010,990');
    $process = ($this->bench)(['BENCH_REPEATS' => '3', 'STUB_RPS_SEQ' => $this->root.'/seq'], 'ping');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(glob($this->root.'/app/bench/results/raw/*/nginx-fpm/ping-*.json'))->toHaveCount(3);
});
