<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-mercure-load-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app');
    $this->envBefore = "APP_ENV=local\nAPP_DEBUG=true\nSECRET=keep\nMERCURE_PUBLISHER_JWT_KEY=publisher-secret-value\nMERCURE_SUBSCRIBER_JWT_KEY=subscriber-secret-value\n";
    file_put_contents($this->root.'/app/.env', $this->envBefore);

    $stubs = [
        'ddev' => <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$*" in
  "server --current") echo "${STUB_SERVER-frankenphp}" ;;
  "server --url") echo "https://ai-lab.ddev.site" ;;
  "artisan config:show app.env") echo "${STUB_APP_ENV-production}" ;;
  "artisan config:show app.debug") echo "${STUB_APP_DEBUG-false}" ;;
  "artisan config:show mercure.topic_base") echo "https://ai-lab.ddev.site" ;;
  "artisan mercure:token 1") echo "token-for-listing-1" ;;
  "artisan mercure:token 2") echo "token-for-listing-2" ;;
  "artisan tinker --execute"*) echo "5000000" ;;
  "artisan listings:reprice"*) if [[ "${STUB_FAIL_REPRICE-}" == 1 && "$*" != *--restore* ]]; then exit 1; fi ;;
  "exec sh bench/lib/busy-threads.sh"*)
    printf '%s\n' "1 4" "3 4" "2 4" "${STUB_LAST_SAMPLE-na na}" ;;
esac
BASH,
        'curl' => <<<'BASH'
#!/usr/bin/env bash
out=""
url=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    -o) out="$2"; shift 2 ;;
    -w|-H|--max-time) shift 2 ;;
    http*) url="$1"; shift ;;
    *) shift ;;
  esac
done
[[ -n "$out" && "$out" != /dev/null ]] && printf '{"data":{}}' > "$out"
if [[ "$url" == *".well-known/mercure"* ]]; then
  if [[ "${STUB_NO_HUB-}" == 1 ]]; then printf '404 text/html'; else printf '200 text/event-stream'; fi
else
  printf '200'
fi
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
    if [[ "$*" == *--name\ mercure-load-probe-* ]]; then
      echo '{"summary":{"requestsPerSec":5},"latencyPercentiles":{"p50":0.004,"p95":0.02,"p99":0.05},"statusCodeDistribution":{"200":50},"errorDistribution":{"timeout":0}}'
    elif [[ "$*" == *--name\ mercure-load-subs-* ]]; then
      for arg in "$@"; do
        [[ "$arg" == *:/out ]] && out="${arg%:/out}"
        [[ "$arg" == COUNT=* ]] && count="${arg#COUNT=}"
      done
      echo '{"connected":'"${STUB_CONNECTED-$count}"',"failed":0}' > "$out/ready.json"
      until [[ -e "$out/stop" ]]; do sleep 0.05; done
      connected="${STUB_CONNECTED-$count}"
      cat > "$out/result.json" <<JSON
{"requested":$count,"connected":$connected,"failed":$((count - connected)),"failures":${STUB_FAILURES-{\}},
 "messages":${STUB_MESSAGES-$((connected * 6))},"received_min":6,"received_max":6,
 "latencies_ms":${STUB_LATENCIES-[10,20,30,40,50,60,70,80,90,100]},
 "checks":[
  {"name":"authorized","status":200,"content_type":"text/event-stream","open":true,"received":${STUB_AUTH_RECEIVED-1}},
  {"name":"wrong-token","status":200,"content_type":"text/event-stream","open":true,"received":${STUB_WRONG_RECEIVED-0}},
  {"name":"no-token","status":200,"content_type":"text/event-stream","open":true,"received":${STUB_NONE_RECEIVED-0}}]}
JSON
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
        'BENCH_PUBLISH_GAP' => '0',
        'BENCH_DRAIN' => '0',
        'BENCH_SUBS_SETTLE' => '0',
        'STUB_LOG' => $this->root.'/calls.log',
        'PATH' => $this->root.'/bin:'.getenv('PATH'),
    ];
    $this->mercureLoad = function (array $env, string ...$args): Process {
        $process = new Process(['bash', base_path('bench/mercure-load.sh'), ...$args], $this->root, $env + ($this->baseEnv)());
        $process->run();

        return $process;
    };
    $this->calls = fn (): string => file_exists($this->root.'/calls.log') ? file_get_contents($this->root.'/calls.log') : '';
    $this->result = fn (string $name, string $dir = 'raw'): array => json_decode(file_get_contents($this->root.'/app/bench/results/'.$dir.'/'.$name.'.json'), true);
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-6 mercure-load holds the subscribers in a pinned node container beside the shared probe, both capped, and prints a table', function (): void {
    $process = ($this->mercureLoad)([], '--subscribers', '20', '--quick', '--label', 'frankenphp');
    $calls = ($this->calls)();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('/healthz', '--name mercure-load-subs-', '--name mercure-load-probe-')
        ->and($calls)->toContain('COUNT=20', 'TOPIC=https://ai-lab.ddev.site/listings/1/price', 'HUB=https://ai-lab.ddev.site/.well-known/mercure')
        ->and($calls)->toContain('node:22.12.0-alpine', 'node /bench-lib/mercure-subscribers.mjs')
        ->and(substr_count($calls, '--cpus=2'))->toBe(2)
        ->and($process->getOutput())->toContain('subscribers=20', 'health_p95_ms', 'busy', 'latency', 'private');

    $result = ($this->result)('n20-frankenphp');

    expect($result)->toMatchArray([
        'server' => 'frankenphp', 'label' => 'frankenphp', 'commit' => 'abc1234', 'subscribers' => 20,
        'publishes' => 5, 'quick' => true,
    ])
        ->and($result['connected'])->toBe(20)
        ->and($result['health']['p95_ms'])->toEqual(20)
        ->and($result['health']['errors'])->toBe(0);
});

it('AC-6 mercure-load publishes through listings:reprice (public, then one private) and puts the price back', function (): void {
    $process = ($this->mercureLoad)([], '--subscribers', '20', '--quick', '--label', 'x');
    preg_match_all('/^ddev artisan listings:reprice (.*)$/m', ($this->calls)(), $reprices);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($reprices[1])->toBe([
            '1 5000100', '1 5000200', '1 5000300', '1 5000400', '1 5000500',
            '1 5000600 --private',
            '1 --restore=5000000',
        ]);
});

it('AC-6 mercure-load aggregates latency, delivery, busy threads and health from the raw files', function (): void {
    $process = ($this->mercureLoad)(['STUB_LATENCIES' => '[10,20,30,40,50,60,70,80,90,100]', 'STUB_CONNECTED' => '18', 'STUB_MESSAGES' => '100'], '--subscribers', '20', '--quick', '--label', 'agg');
    $result = ($this->result)('n20-agg');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result['connected'])->toBe(18)
        ->and($result['connect_failed'])->toBe(2)
        ->and($result['latency_ms'])->toMatchArray(['p50' => 50, 'p95' => 100, 'max' => 100, 'samples' => 10])
        ->and($result['delivered'])->toMatchArray(['received' => 100, 'expected' => 120])
        ->and($result['delivered']['percent'])->toEqual(83.3)
        ->and($result['busy_threads'])->toMatchArray(['max' => 3, 'median' => 2, 'total_threads' => 4, 'samples' => 3, 'metric_found' => true])
        ->and($process->getOutput())->toContain('18/20', '100/120');
});

it('AC-6 mercure-load records that the busy-thread metric is missing when every sample is n/a', function (): void {
    $bin = $this->root.'/bin/ddev';
    file_put_contents($bin, str_replace('printf \'%s\n\' "1 4" "3 4" "2 4" "${STUB_LAST_SAMPLE-na na}"', 'printf \'%s\n\' "na na" "na na"', file_get_contents($bin)));

    $process = ($this->mercureLoad)([], '--subscribers', '20', '--quick', '--label', 'nometric');
    $result = ($this->result)('n20-nometric');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result['busy_threads']['metric_found'])->toBeFalse()
        ->and($result['busy_threads']['max'])->toBeNull()
        ->and($process->getOutput())->toContain('busy threads: metric not found');
});

it('AC-2 mercure-load records the three private checks and passes when only the right token received', function (): void {
    $process = ($this->mercureLoad)([], '--subscribers', '20', '--quick', '--label', 'priv');
    $result = ($this->result)('n20-priv');
    $calls = ($this->calls)();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result['private'])->toMatchArray(['authorized_received' => 1, 'wrong_token_received' => 0, 'no_token_received' => 0, 'passed' => true])
        ->and($calls)->toContain('ddev artisan mercure:token 1', 'ddev artisan mercure:token 2')
        ->and($process->getOutput())->toContain('private checks: PASS');
});

it('AC-2 mercure-load fails the private checks when a wrong or missing token received the update, or the right one did not', function (): void {
    foreach (['STUB_WRONG_RECEIVED' => '1', 'STUB_NONE_RECEIVED' => '1', 'STUB_AUTH_RECEIVED' => '0'] as $name => $value) {
        $process = ($this->mercureLoad)([$name => $value], '--subscribers', '20', '--quick', '--label', 'bad');
        $result = ($this->result)('n20-bad');

        expect($process->getExitCode())->toBe(1, $name)
            ->and($result['private']['passed'])->toBeFalse($name)
            ->and($process->getOutput())->toContain('private checks: FAIL');
    }
});

it('NFR-1 mercure-load never gives a key to the containers or the result, only tokens to the subscriber container', function (): void {
    $process = ($this->mercureLoad)([], '--subscribers', '20', '--quick', '--label', 'keys');
    $result = file_get_contents($this->root.'/app/bench/results/raw/n20-keys.json');
    $calls = ($this->calls)();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls.$result.$process->getOutput().$process->getErrorOutput())->not->toContain('publisher-secret-value', 'subscriber-secret-value')
        ->and($result)->not->toContain('token-for-listing');
});

it('AC-6 mercure-load refuses a server without a hub, or without keys, before changing anything', function (): void {
    $noHub = ($this->mercureLoad)(['STUB_NO_HUB' => '1'], '--subscribers', '20', '--quick');

    expect($noHub->getExitCode())->toBe(2)
        ->and($noHub->getErrorOutput())->toContain('Run condition failed', 'Mercure hub')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore)
        ->and(($this->calls)())->not->toContain('listings:reprice');

    file_put_contents($this->root.'/app/.env', "APP_ENV=local\n");
    $noKeys = ($this->mercureLoad)([], '--subscribers', '20', '--quick');

    expect($noKeys->getExitCode())->toBe(2)
        ->and($noKeys->getErrorOutput())->toContain('Run condition failed', 'mercure:keys')
        ->and(file_exists($this->root.'/app/.env.bench-backup'))->toBeFalse();
});

it('MNC-3 mercure-load puts .env back and restores the listing price when a publish fails', function (): void {
    $process = ($this->mercureLoad)(['STUB_FAIL_REPRICE' => '1'], '--subscribers', '20', '--quick');

    expect($process->getExitCode())->not->toBe(0)
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore)
        ->and(file_exists($this->root.'/app/.env.bench-backup'))->toBeFalse()
        ->and(($this->calls)())->toContain('ddev artisan listings:reprice 1 --restore=5000000')
        ->and(($this->calls)())->toContain('docker rm -f');
});

it('AC-6 mercure-load sets the run conditions in .env and keeps the keys when it restores it', function (): void {
    $process = ($this->mercureLoad)([], '--subscribers', '20', '--quick');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore)
        ->and(($this->calls)())->toContain('ddev artisan optimize');
});

it('AC-6 mercure-load rejects a bad subscriber count, label and a missing count', function (): void {
    expect(($this->mercureLoad)([], '--subscribers', 'many')->getExitCode())->toBe(2)
        ->and(($this->mercureLoad)([], '--subscribers', '20', '--label', 'Bad Label')->getExitCode())->toBe(2)
        ->and(($this->mercureLoad)([], '--quick')->getExitCode())->toBe(2);
});

it('AC-6 mercure-load keeps the documented defaults: 90 s window and 20 publishes, quick 20 s and 5', function (): void {
    $script = file_get_contents(base_path('bench/mercure-load.sh'));

    expect($script)->toContain('MEASURE="${BENCH_MEASURE:-90}"', 'PUBLISHES="${BENCH_PUBLISHES:-20}"')
        ->toContain('MEASURE="${BENCH_MEASURE:-20}"', 'PUBLISHES="${BENCH_PUBLISHES:-5}"')
        ->and($script)->toContain('lib/health-probe.sh', 'lib/env-switch.sh');
});

it('AC-6 the node subscribers count connections, deliver by topic and token, and measure latency against a local hub', function (): void {
    if ((new Process(['node', '--version']))->run() !== 0) {
        $this->markTestSkipped('node is not installed');
    }

    $hub = $this->root.'/hub.mjs';
    file_put_contents($hub, <<<'JS'
import { createServer } from 'node:http';
import { writeFileSync } from 'node:fs';
const server = createServer((req, res) => {
  const url = new URL(req.url, 'http://x');
  const topic = url.searchParams.get('topic');
  if (topic.endsWith('/gone')) {
    res.writeHead(404).end();
    return;
  }
  res.writeHead(200, { 'content-type': 'text/event-stream' });
  res.write(':\n');
  const allowed = !topic.endsWith('/private') || req.headers.authorization === 'Bearer good';
  setTimeout(() => {
    if (allowed) {
      res.write(`id: a\ndata: {"published_at_ms":${Date.now() - 25}}\n\n`);
    }
  }, 400);
});
server.listen(0, '127.0.0.1', () => writeFileSync(process.argv[2], String(server.address().port)));
JS);
    $hubProcess = new Process(['node', $hub, $this->root.'/port']);
    $hubProcess->start();
    for ($i = 0; $i < 100 && ! is_file($this->root.'/port'); $i++) {
        usleep(50_000);
    }
    $port = trim((string) file_get_contents($this->root.'/port'));
    mkdir($this->root.'/out');

    $subs = new Process(['node', base_path('bench/lib/mercure-subscribers.mjs')], $this->root, [
        'HUB' => "http://127.0.0.1:{$port}/hub", 'TOPIC' => 'https://x/listings/1/price', 'COUNT' => '7', 'OUT' => $this->root.'/out',
        'RAMP_BATCH' => '3', 'RAMP_PAUSE_MS' => '10',
        'CHECKS' => json_encode([
            ['name' => 'authorized', 'topic' => 'https://x/listings/1/price/private', 'token' => 'good'],
            ['name' => 'wrong-token', 'topic' => 'https://x/listings/1/price/private', 'token' => 'other'],
            ['name' => 'no-token', 'topic' => 'https://x/listings/1/price/private', 'token' => ''],
            ['name' => 'missing', 'topic' => 'https://x/gone', 'token' => ''],
        ]),
    ]);
    $subs->start();
    for ($i = 0; $i < 100 && ! is_file($this->root.'/out/ready.json'); $i++) {
        usleep(50_000);
    }
    usleep(900_000);
    touch($this->root.'/out/stop');
    $subs->wait();
    $hubProcess->stop();

    $result = json_decode((string) file_get_contents($this->root.'/out/result.json'), true);
    $checks = array_column($result['checks'], null, 'name');

    expect($subs->getExitCode())->toBe(0, $subs->getErrorOutput())
        ->and($result)->toMatchArray(['requested' => 7, 'connected' => 7, 'failed' => 0, 'messages' => 7])
        ->and($result['latencies_ms'])->toHaveCount(7)
        ->and(min($result['latencies_ms']))->toBeGreaterThanOrEqual(25)
        ->and($checks['authorized']['received'])->toBe(1)
        ->and($checks['wrong-token']['received'])->toBe(0)
        ->and($checks['no-token']['received'])->toBe(0)
        ->and($checks['missing'])->toMatchArray(['status' => 404, 'open' => false]);
});
