<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->node = (new ExecutableFinder)->find('node');
    if ($this->node === null) {
        $this->markTestSkipped('node is not installed');
    }

    $this->root = sys_get_temp_dir().'/bench-lcp-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app');

    $runs = json_encode(['runs' => array_map(
        fn (int $i): array => [
            'lcp_ms' => 1000 + $i * 100,
            'fcp_ms' => 800 + $i * 10,
            'ttfb_ms' => 300 + $i,
            'ttfb_nav_ms' => 20 + $i,
            'early_hint_used' => (bool) ($i % 2),
            'initiator_types' => ['css' => $i % 2 ? 'early-hints' : 'link'],
        ],
        range(0, 4),
    )]);

    $stubs = [
        'ddev' => <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$*" in
  "server --current") echo "${STUB_SERVER-frankenphp}" ;;
  "server --url") echo "https://interview.ddev.site" ;;
esac
BASH,
        'git' => "#!/usr/bin/env bash\necho abc1234\n",
        'docker' => <<<BASH
#!/usr/bin/env bash
echo "docker \$*" >> "\$STUB_LOG"
case "\$1" in
  pull) ;;
  image) echo "mcr.microsoft.com/playwright@sha256:abc" ;;
  inspect)
    case "\$*" in
      *ddev-router*) echo 172.19.0.9 ;;
      *) echo 172.19.0.2 ;;
    esac
    ;;
  run) echo '{$runs}' ;;
esac
BASH,
    ];
    foreach ($stubs as $name => $script) {
        file_put_contents($this->root.'/bin/'.$name, $script);
        chmod($this->root.'/bin/'.$name, 0755);
    }

    $this->lcp = function (array $env, string ...$args): Process {
        $process = new Process(
            [$this->node, base_path('bench/lcp.mjs'), ...$args],
            $this->root,
            $env + [
                'BENCH_APPROOT' => $this->root.'/app',
                'BENCH_DATE' => '2026-11-05',
                'STUB_LOG' => $this->root.'/calls.log',
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
            ],
        );
        $process->run();

        return $process;
    };
    $this->file = fn (string $variant): string => $this->root.'/app/bench/results/lcp/2026-11-05/'.$variant.'.json';
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-4 lcp dry-run prints the plan without calling docker', function (): void {
    $process = ($this->lcp)([], '--dry-run', '--variant', 'frankenphp-hints', '--path', 'direct');
    $plan = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($plan)->toMatchArray(['variant' => 'frankenphp-hints', 'path' => 'direct', 'runs' => 5, 'url' => 'https://interview.ddev.site/listings/1'])
        ->and($plan['throttle'])->toMatchArray(['rtt_ms' => 150, 'down_kbps' => 1600, 'up_kbps' => 750, 'cpu_slowdown' => 4])
        ->and($plan['throttle']['method'])->toContain('toxiproxy')
        ->and(file_get_contents($this->root.'/calls.log'))->not->toContain('docker');
});

it('AC-4 lcp runs the pinned image on ddev_default with the chosen path and writes median and range', function (): void {
    $process = ($this->lcp)([], '--variant', 'frankenphp-hints', '--path', 'direct', '--path-reason', 'router drops 103');
    $calls = file_get_contents($this->root.'/calls.log');
    $result = json_decode(file_get_contents(($this->file)('frankenphp-hints')), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('--network ddev_default', 'mcr.microsoft.com/playwright@sha256:abc', '172.19.0.2')
        ->and($result)->toMatchArray([
            'variant' => 'frankenphp-hints', 'server' => 'frankenphp', 'path' => 'direct', 'path_reason' => 'router drops 103',
            'commit' => 'abc1234', 'listing' => 1, 'runs' => 5, 'cold' => true, 'browser_image_digest' => 'mcr.microsoft.com/playwright@sha256:abc',
        ])
        ->and($result['throttle'])->toMatchArray(['rtt_ms' => 150, 'down_kbps' => 1600, 'up_kbps' => 750, 'cpu_slowdown' => 4])
        ->and($result['lcp_ms'])->toMatchArray(['median' => 1200, 'min' => 1000, 'max' => 1400])
        ->and($result['fcp_ms'])->toHaveKeys(['median', 'min', 'max'])
        ->and($result['ttfb_ms'])->toMatchArray(['median' => 302, 'min' => 300, 'max' => 304])
        ->and($result['ttfb_source'])->toBe('cdp Network.responseReceived timing.receiveHeadersEnd of the document request')
        ->and($result['ttfb_nav_ms'])->toMatchArray(['median' => 22, 'min' => 20, 'max' => 24])
        ->and($result['samples'])->toHaveCount(5)
        ->and($result['early_hint_used'])->toBeTrue()
        ->and($process->getOutput())->toContain('variant=frankenphp-hints', 'path=direct', 'lcp_median_ms=1200');
});

it('AC-4 NFR-1 lcp reads TTFB from the CDP document response timing, not from navigation responseStart', function (): void {
    $script = file_get_contents(base_path('bench/lcp.mjs'));

    expect($script)->toContain("'Network.responseReceived'", 'receiveHeadersEnd')
        ->and($script)->not->toContain('ttfb_ms: Math.round(nav.responseStart)');
});

it('AC-4 lcp goes through the router address on the router path', function (): void {
    $process = ($this->lcp)([], '--variant', 'nginx-fpm', '--path', 'router', '--path-reason', 'router forwards 103');
    $calls = file_get_contents($this->root.'/calls.log');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('172.19.0.9')
        ->and(($this->file)('nginx-fpm'))->toBeFile();
});

it('AC-4 lcp refuses to run without a recorded path reason or with a bad path', function (): void {
    $noReason = ($this->lcp)([], '--variant', 'x', '--path', 'direct');
    $badPath = ($this->lcp)([], '--variant', 'x', '--path', 'sideways', '--path-reason', 'r');

    expect($noReason->getExitCode())->toBe(2)
        ->and($badPath->getExitCode())->toBe(2)
        ->and(($this->file)('x'))->not->toBeFile();
});

it('AC-4 lcp stops when the browser container fails instead of recording a result', function (): void {
    file_put_contents($this->root.'/bin/docker', "#!/usr/bin/env bash\ncase \"\$1\" in\n  image) echo mcr.microsoft.com/playwright@sha256:abc ;;\n  inspect) echo 172.19.0.2 ;;\n  run) exit 3 ;;\nesac\n");

    $process = ($this->lcp)([], '--variant', 'frankenphp-hints', '--path', 'direct', '--path-reason', 'r');

    expect($process->getExitCode())->not->toBe(0)
        ->and(($this->file)('frankenphp-hints'))->not->toBeFile();
});

it('AC-4 lcp prints usage with --help', function (): void {
    $process = ($this->lcp)([], '--help');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('--variant', '--path', '--path-reason', '--runs');
});

function lcpDockerStub(string $root, array $runs): void
{
    $json = json_encode(['runs' => $runs]);
    file_put_contents($root.'/bin/docker', <<<BASH
#!/usr/bin/env bash
echo "docker \$*" >> "\$STUB_LOG"
case "\$1" in
  image) echo "stub/image@sha256:abc" ;;
  inspect) echo 172.19.0.2 ;;
  run) echo '{$json}' ;;
esac
BASH);
}

function lcpRun(int $lcp, ?int $fcp): array
{
    return ['lcp_ms' => $lcp, 'fcp_ms' => $fcp, 'ttfb_ms' => 300, 'ttfb_nav_ms' => 20, 'early_hint_used' => false, 'initiator_types' => []];
}

it('AC-4 NFR-1 lcp throttles at the network layer with a pinned toxiproxy container and keeps only the CPU slowdown in CDP', function (): void {
    $process = ($this->lcp)([], '--variant', 'nginx-fpm', '--path', 'router', '--path-reason', 'r');
    $calls = file_get_contents($this->root.'/calls.log');
    $result = json_decode(file_get_contents(($this->file)('nginx-fpm')), true);
    $script = file_get_contents(base_path('bench/lcp.mjs'));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('--platform linux/amd64 mcr.microsoft.com/playwright@sha256:abc')
        ->and($calls)->toContain('create site --listen 0.0.0.0:443 --upstream 172.19.0.9:443')
        ->and($calls)->toContain('--toxicName latency_up --type latency --attribute latency=75 --upstream')
        ->and($calls)->toContain('--toxicName latency_down --type latency --attribute latency=75 --downstream')
        ->and($calls)->toContain('--toxicName bandwidth_down --type bandwidth --attribute rate=200 --downstream')
        ->and($calls)->toContain('--toxicName bandwidth_up --type bandwidth --attribute rate=94 --upstream')
        ->and($calls)->toContain('docker rm -f lcp-toxiproxy-')
        ->and($result['throttle']['method'])->toContain('toxiproxy')
        ->and($result['throttle']['toxics'])->toHaveCount(4)
        ->and($result['throttle_image_digest'])->toBe('mcr.microsoft.com/playwright@sha256:abc')
        ->and($script)->not->toContain('emulateNetworkConditions')
        ->and($script)->toContain('Emulation.setCPUThrottlingRate', '--host-resolver-rules');
});

it('AC-4 lcp marks a run without FCP or LCP invalid, leaves it out of the summary and records the count', function (): void {
    lcpDockerStub($this->root, [lcpRun(1000, 500), lcpRun(1100, 520), lcpRun(0, 540), lcpRun(1300, null), lcpRun(1200, 560)]);

    $process = ($this->lcp)([], '--variant', 'frankenphp-hints', '--path', 'direct', '--path-reason', 'r');
    $result = json_decode(file_get_contents(($this->file)('frankenphp-hints')), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result)->toMatchArray(['runs' => 5, 'valid_runs' => 3, 'invalid_runs' => 2])
        ->and($result['lcp_ms'])->toMatchArray(['median' => 1100, 'min' => 1000, 'max' => 1200])
        ->and($result['fcp_ms'])->toMatchArray(['median' => 520, 'min' => 500, 'max' => 560])
        ->and(collect($result['samples'])->pluck('valid')->all())->toBe([true, true, false, false, true]);
});

it('AC-4 lcp fails without writing a file when fewer than 3 runs are valid', function (): void {
    lcpDockerStub($this->root, [lcpRun(1000, 500), lcpRun(0, 520), lcpRun(1100, null), lcpRun(1300, 0), lcpRun(1200, 560)]);

    $process = ($this->lcp)([], '--variant', 'frankenphp-hints', '--path', 'direct', '--path-reason', 'r');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('only 2 valid runs')
        ->and(($this->file)('frankenphp-hints'))->not->toBeFile();
});

it('AC-4 lcp.sh sets EARLY_HINTS=false for the no-hints variant, clears config and restores .env', function (): void {
    file_put_contents($this->root.'/app/.env', "APP_NAME=x\nEARLY_HINTS=true\n");
    $seen = $this->root.'/env-during';
    file_put_contents($this->root.'/bin/node', "#!/usr/bin/env bash\necho \"node \$*\" >> \"\$STUB_LOG\"\ncp \"\$BENCH_APPROOT/.env\" \"".$seen."\"\n");
    chmod($this->root.'/bin/node', 0755);

    $process = new Process(['bash', base_path('bench/lcp.sh'), '--variant', 'frankenphp-no-hints', '--hints', 'off', '--path', 'router', '--path-reason', 'r'], $this->root, [
        'BENCH_APPROOT' => $this->root.'/app', 'STUB_LOG' => $this->root.'/calls.log', 'PATH' => $this->root.'/bin:'.getenv('PATH'),
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($seen))->toContain('EARLY_HINTS=false')->not->toContain('EARLY_HINTS=true')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe("APP_NAME=x\nEARLY_HINTS=true\n")
        ->and($this->root.'/app/.env.bench-backup')->not->toBeFile()
        ->and(file_get_contents($this->root.'/calls.log'))->toContain('ddev artisan config:clear', '--hints off');
});

it('AC-4 lcp.sh leaves .env alone for the hints variant', function (): void {
    file_put_contents($this->root.'/app/.env', "APP_NAME=x\n");
    file_put_contents($this->root.'/bin/node', "#!/usr/bin/env bash\necho \"node \$*\" >> \"\$STUB_LOG\"\n");
    chmod($this->root.'/bin/node', 0755);

    $process = new Process(['bash', base_path('bench/lcp.sh'), '--variant', 'frankenphp-hints', '--path', 'router', '--path-reason', 'r'], $this->root, [
        'BENCH_APPROOT' => $this->root.'/app', 'STUB_LOG' => $this->root.'/calls.log', 'PATH' => $this->root.'/bin:'.getenv('PATH'),
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($this->root.'/calls.log'))->not->toContain('config:clear')->toContain('--hints on');
});

function lcpNetemRun(string $protocol, ?int $loadMs = 900): array
{
    return ['lcp_ms' => 1000, 'fcp_ms' => 500, 'ttfb_ms' => 300, 'ttfb_nav_ms' => 20, 'early_hint_used' => false, 'initiator_types' => [], 'next_hop_protocol' => $protocol, 'load_ms' => $loadMs];
}

function lcpNetemStubs(object $test, array $runs, bool $applied = true, bool $dropsOk = true, bool $webOffFails = false, array $profileOverride = []): void
{
    $json = json_encode(['runs' => $runs]);
    file_put_contents($test->root.'/bin/docker', <<<BASH
#!/usr/bin/env bash
echo "docker \$*" >> "\$STUB_LOG"
case "\$1" in
  image) echo "stub/image@sha256:abc" ;;
  inspect) echo 172.19.0.2 ;;
  exec) case "\$*" in *--inside*) echo '{$json}' ;; esac ;;
esac
BASH);
    $profile = json_encode($profileOverride + ['applied' => $applied, 'reason' => $applied ? null : 'netem unavailable: no NET_ADMIN', 'delay_ms' => 75, 'loss_pct' => 2, 'rate_up' => '750kbit', 'rate_down' => '1600kbit', 'rtt_ms' => 153, 'rtt_samples_ms' => [153], 'rtt_target_ms' => [140, 170], 'offloads' => ['client' => ['before' => ['eth0' => ['tso' => 'on']], 'after' => ['eth0' => ['tso' => 'off']]]]]);
    $drops = json_encode(['applied' => true, 'reason' => $dropsOk ? null : 'ingress drop rate 0.00% outside 1-3.5% with loss 2%', 'mode' => 'bidirectional', 'limit' => 1000, 'qdisc' => ['egress_drop_rate' => 2.0, 'ingress_drop_rate' => 2.1, 'egress_packets' => 1000, 'ingress_packets' => 2000]]);
    $dropsStatus = $dropsOk ? 0 : 1;
    $webOff = json_encode(['before' => ['eth0' => ['tso' => 'on', 'gso' => 'on', 'gro' => 'off', 'udp_gso' => 'on']], 'after' => ['eth0' => ['tso' => 'off', 'gso' => 'off', 'gro' => 'off', 'udp_gso' => 'off']]]);
    $webRestore = json_encode(['restored' => ['eth0' => ['tso' => 'on', 'gso' => 'on', 'gro' => 'off', 'udp_gso' => 'on']], 'matches_before' => true]);
    $webOffStatus = $webOffFails ? 1 : 0;
    file_put_contents($test->root.'/netem.sh', <<<BASH
#!/usr/bin/env bash
echo "netem \$* loss=\$NETEM_LOSS probe=\$NETEM_PROBE_URL" >> "\$STUB_LOG"
case "\$1" in
  apply) echo '{$profile}' ;;
  drops) echo '{$drops}'; exit {$dropsStatus} ;;
  web-offloads)
    case "\$2" in
      off) if [ {$webOffStatus} -ne 0 ]; then echo 'cannot turn tso, gso and gro off on web' >&2; exit 1; fi; echo '{$webOff}' ;;
      restore) echo '{$webRestore}' ;;
    esac
    ;;
esac
BASH);
    $test->netemEnv = ['NETEM_SH' => $test->root.'/netem.sh'];
}

it('AC-3 lcp dry-run plans the netem mode with the protocol and loss and no toxiproxy', function (): void {
    $process = ($this->lcp)([], '--dry-run', '--variant', 'h3-loss2', '--protocol', 'h3', '--net', 'netem', '--loss', '2');
    $plan = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($plan)->toMatchArray(['protocol' => 'h3', 'net' => 'netem', 'loss_pct' => 2.0])
        ->and($plan['throttle']['method'])->toContain('netem')
        ->and($plan['throttle'])->toMatchArray(['rtt_ms' => 150, 'down_kbps' => 1600, 'up_kbps' => 750, 'cpu_slowdown' => 4])
        ->and($plan)->not->toHaveKey('throttle_image')
        ->and(file_get_contents($this->root.'/calls.log'))->not->toContain('docker');
});

it('AC-3 MNC-2 lcp dry-run without the new flags keeps the toxiproxy plan', function (): void {
    $plan = json_decode(($this->lcp)([], '--dry-run', '--variant', 'v', '--path', 'direct')->getOutput(), true);

    expect(array_keys($plan))->toBe(['variant', 'path', 'runs', 'hints', 'url', 'image', 'throttle_image', 'throttle'])
        ->and($plan['throttle']['method'])->toContain('toxiproxy');
});

it('AC-3 lcp rejects h3 or loss without the netem mode and a bad protocol or loss', function (): void {
    $h3 = ($this->lcp)([], '--dry-run', '--variant', 'v', '--path', 'direct', '--protocol', 'h3');
    $loss = ($this->lcp)([], '--dry-run', '--variant', 'v', '--path', 'direct', '--loss', '2');
    $bad = ($this->lcp)([], '--dry-run', '--variant', 'v', '--path', 'direct', '--protocol', 'h4', '--net', 'netem');
    $badLoss = ($this->lcp)([], '--dry-run', '--variant', 'v', '--path', 'direct', '--net', 'netem', '--loss', '150');

    expect($h3->getExitCode())->toBe(2)->and($loss->getExitCode())->toBe(2)->and($bad->getExitCode())->toBe(2)->and($badLoss->getExitCode())->toBe(2);
});

it('AC-3 NFR-1 lcp netem mode shapes the browser container, records the profile and writes load time per protocol', function (): void {
    lcpNetemStubs($this, array_map(fn (): array => lcpNetemRun('h3'), range(1, 5)));
    $process = ($this->lcp)($this->netemEnv, '--variant', 'frankenphp-h3-loss2', '--path', 'direct', '--path-reason', 'r', '--protocol', 'h3', '--net', 'netem', '--loss', '2');
    $calls = file_get_contents($this->root.'/calls.log');
    $file = $this->root.'/app/bench/results/http3/2026-11-05/lcp-frankenphp-h3-loss2.json';
    $result = json_decode(file_get_contents($file), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('netem apply lcp-netem-', 'loss=2', 'probe=https://interview.ddev.site', 'netem clear lcp-netem-', 'docker rm -f lcp-netem-')
        ->and($calls)->not->toContain('create site')
        ->and($result)->toMatchArray(['protocol' => 'h3', 'valid_runs' => 5, 'invalid_runs' => 0])
        ->and($result['net'])->toMatchArray(['applied' => true, 'loss_pct' => 2, 'rtt_ms' => 153])
        ->and($result['net'])->toMatchArray(['mode' => 'bidirectional', 'limit' => 1000])
        ->and($result['net']['qdisc'])->toMatchArray(['egress_drop_rate' => 2.0, 'ingress_drop_rate' => 2.1])
        ->and($result['path'])->toBe('direct')
        ->and($calls)->toContain('netem drops lcp-netem-')
        ->and($result['throttle']['method'])->toContain('netem')
        ->and($result['load_ms'])->toMatchArray(['median' => 900, 'min' => 900, 'max' => 900])
        ->and($result['samples'][0])->toMatchArray(['next_hop_protocol' => 'h3', 'valid' => true]);
});

function lcpNetemQueue(object $test, array $runs): void
{
    file_put_contents($test->root.'/queue', implode("\n", array_map('json_encode', $runs))."\n");
    file_put_contents($test->root.'/bin/docker', <<<'BASH'
#!/usr/bin/env bash
echo "docker $*" >> "$STUB_LOG"
case "$1" in
  image) echo "stub/image@sha256:abc" ;;
  inspect) echo 172.19.0.2 ;;
  exec)
    case "$*" in
      *--inside*)
        n=$(echo "$*" | sed -E 's/.*--runs ([0-9]+).*/\1/')
        q="$STUB_QUEUE"
        echo "{\"runs\":[$(head -n "$n" "$q" | paste -sd, -)]}"
        tail -n +"$((n + 1))" "$q" > "$q.tmp"; mv "$q.tmp" "$q"
        ;;
    esac
    ;;
esac
BASH);
    $test->netemEnv['STUB_QUEUE'] = $test->root.'/queue';
}

it('AC-3 lcp netem mode repeats mismatched runs until 5 are valid, 2 mismatches then 5 h3 runs give 5 valid after 7 attempts', function (): void {
    lcpNetemStubs($this, []);
    lcpNetemQueue($this, [lcpNetemRun('h2'), lcpNetemRun('h2'), ...array_map(fn (): array => lcpNetemRun('h3'), range(1, 5))]);
    $process = ($this->lcp)($this->netemEnv, '--variant', 'x', '--path', 'direct', '--path-reason', 'r', '--protocol', 'h3', '--net', 'netem', '--loss', '0');
    $result = json_decode(file_get_contents($this->root.'/app/bench/results/http3/2026-11-05/lcp-x.json'), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result)->toMatchArray(['runs' => 5, 'attempts' => 7, 'valid_runs' => 5, 'invalid_runs' => 2])
        ->and($result['samples'])->toHaveCount(7)
        ->and(collect($result['samples'])->pluck('valid')->all())->toBe([false, false, true, true, true, true, true]);
});

it('AC-3 lcp netem mode fails with exit 1 and no file when 4 of 8 attempts mismatch', function (): void {
    lcpNetemStubs($this, []);
    lcpNetemQueue($this, [lcpNetemRun('h2'), lcpNetemRun('h3'), lcpNetemRun('h2'), lcpNetemRun('h3'), lcpNetemRun('h2'), lcpNetemRun('h3'), lcpNetemRun('h2'), lcpNetemRun('h3'), lcpNetemRun('h3')]);
    $process = ($this->lcp)($this->netemEnv, '--variant', 'x', '--path', 'direct', '--path-reason', 'r', '--protocol', 'h3', '--net', 'netem', '--loss', '0');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('only 4 valid runs of 8 attempts')
        ->and($this->root.'/app/bench/results/http3/2026-11-05/lcp-x.json')->not->toBeFile();
});

it('AC-3 NFR-1 lcp netem mode refuses to record an unshaped run', function (): void {
    lcpNetemStubs($this, array_map(fn (): array => lcpNetemRun('h2'), range(1, 5)), applied: false);
    $process = ($this->lcp)($this->netemEnv, '--variant', 'x', '--path', 'direct', '--path-reason', 'r', '--protocol', 'h2', '--net', 'netem', '--loss', '2');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('netem was not applied')
        ->and($this->root.'/app/bench/results/http3/2026-11-05/lcp-x.json')->not->toBeFile();
});

it('AC-2 lcp netem mode writes nothing when the qdisc drop rates are outside the loss range', function (): void {
    lcpNetemStubs($this, array_map(fn (): array => lcpNetemRun('h2'), range(1, 5)), dropsOk: false);
    $process = ($this->lcp)($this->netemEnv, '--variant', 'x', '--path', 'direct', '--path-reason', 'r', '--protocol', 'h2', '--net', 'netem', '--loss', '2');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('netem loss check failed', 'ingress drop rate 0.00%')
        ->and($this->root.'/app/bench/results/http3/2026-11-05/lcp-x.json')->not->toBeFile();
});

it('MNC-2 AC-3 the toxiproxy result keeps exactly the SL-10 schema: no netem field at top level or in the samples, 3 valid runs are enough', function (): void {
    lcpDockerStub($this->root, [lcpRun(1000, 500), lcpRun(1100, 520), lcpRun(1200, 540), lcpRun(0, 560), lcpRun(1300, null)]);
    $process = ($this->lcp)([], '--variant', 'nginx-fpm', '--path', 'router', '--path-reason', 'r');
    $result = json_decode(file_get_contents(($this->file)('nginx-fpm')), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_keys($result))->toBe([
            'variant', 'server', 'path', 'path_reason', 'date', 'commit', 'listing', 'url', 'runs', 'valid_runs', 'invalid_runs',
            'early_hints_setting', 'cold', 'browser_image', 'browser_image_digest', 'playwright_version', 'throttle_image',
            'throttle_image_digest', 'throttle_upstream', 'throttle', 'lcp_ms', 'fcp_ms', 'ttfb_ms', 'ttfb_source', 'ttfb_nav_ms',
            'early_hint_used', 'early_hint_runs', 'samples',
        ])
        ->and($result)->not->toHaveKeys(['protocol', 'net', 'attempts', 'load_ms'])
        ->and(array_keys($result['throttle']))->toBe(['method', 'rtt_ms', 'down_kbps', 'up_kbps', 'cpu_slowdown', 'cpu_method', 'toxics'])
        ->and($result['valid_runs'])->toBe(3);
    foreach ($result['samples'] as $sample) {
        expect($sample)->not->toHaveKeys(['next_hop_protocol', 'load_ms', 'error'])
            ->and(array_keys($sample))->toBe(['lcp_ms', 'fcp_ms', 'ttfb_ms', 'ttfb_nav_ms', 'early_hint_used', 'initiator_types', 'valid']);
    }
});

it('AC-3 lcp netem mode always uses the direct path: --path router is rejected, --path and --path-reason are optional', function (): void {
    lcpNetemStubs($this, array_map(fn (): array => lcpNetemRun('h2'), range(1, 5)));
    $router = ($this->lcp)($this->netemEnv, '--variant', 'x', '--path', 'router', '--path-reason', 'r', '--protocol', 'h2', '--net', 'netem');
    $plain = ($this->lcp)($this->netemEnv, '--variant', 'x', '--protocol', 'h2', '--net', 'netem', '--loss', '2');
    $result = json_decode(file_get_contents($this->root.'/app/bench/results/http3/2026-11-05/lcp-x.json'), true);

    expect($router->getExitCode())->toBe(2)
        ->and($router->getErrorOutput())->toContain('--path direct')
        ->and($plain->getExitCode())->toBe(0, $plain->getErrorOutput())
        ->and($result['path'])->toBe('direct')
        ->and($result['path_reason'])->toContain('router has no HTTP/3');
});

it('AC-3 lcp validates --variant against [A-Za-z0-9._-]{1,40} because it names a result file', function (): void {
    foreach (['../x', 'a/b', 'a b', str_repeat('a', 41), '..%2f', ''] as $bad) {
        $process = ($this->lcp)([], '--dry-run', '--variant', $bad, '--path', 'direct');
        expect($process->getExitCode())->toBe(2, "variant '{$bad}'");
    }
    foreach (['h3-loss2', 'frankenphp-no-hints', 'v1.2_a', str_repeat('a', 40)] as $good) {
        $process = ($this->lcp)([], '--dry-run', '--variant', $good, '--path', 'direct');
        expect($process->getExitCode())->toBe(0, "variant '{$good}'");
    }
});

it('AC-3 lcp netem results go to lcp-<variant>.json and cannot overwrite the http3.sh files', function (): void {
    lcpNetemStubs($this, array_map(fn (): array => lcpNetemRun('h2'), range(1, 5)));
    mkdir($this->root.'/app/bench/results/http3/2026-11-05', 0777, true);
    file_put_contents($this->root.'/app/bench/results/http3/2026-11-05/frankenphp.json', 'curl result');
    $process = ($this->lcp)($this->netemEnv, '--variant', 'frankenphp', '--protocol', 'h2', '--net', 'netem', '--loss', '2');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($this->root.'/app/bench/results/http3/2026-11-05/frankenphp.json'))->toBe('curl result')
        ->and($this->root.'/app/bench/results/http3/2026-11-05/lcp-frankenphp.json')->toBeFile();
});

it('AC-3 lcp netem throttle block comes from the applied profile, not from constants', function (): void {
    lcpNetemStubs($this, array_map(fn (): array => lcpNetemRun('h2'), range(1, 5)), profileOverride: ['delay_ms' => 40, 'rate_up' => '500kbit', 'rate_down' => '2mbit']);
    $process = ($this->lcp)($this->netemEnv, '--variant', 'x', '--protocol', 'h2', '--net', 'netem', '--loss', '2');
    $result = json_decode(file_get_contents($this->root.'/app/bench/results/http3/2026-11-05/lcp-x.json'), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result['throttle'])->toMatchArray(['rtt_ms' => 80, 'down_kbps' => 2000, 'up_kbps' => 500, 'cpu_slowdown' => 4]);
});

it('AC-3 lcp netem dry-run reads the throttle from the netem.sh defaults', function (): void {
    $process = ($this->lcp)(['NETEM_DELAY_MS' => '50', 'NETEM_RATE_DOWN' => '3000kbit'], '--dry-run', '--variant', 'v', '--net', 'netem');
    $plan = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($plan['path'])->toBe('direct')
        ->and($plan['throttle'])->toMatchArray(['rtt_ms' => 100, 'down_kbps' => 3000, 'up_kbps' => 750]);
});

it('AC-3 lcp netem mode counts a thrown navigation as an invalid attempt inside the 8-attempt cap', function (): void {
    lcpNetemStubs($this, []);
    $failed = ['error' => 'page.goto: net::ERR_QUIC_PROTOCOL_ERROR at https://interview.ddev.site/listings/1'];
    lcpNetemQueue($this, [$failed, $failed, ...array_map(fn (): array => lcpNetemRun('h3'), range(1, 5))]);
    $process = ($this->lcp)($this->netemEnv, '--variant', 'x', '--protocol', 'h3', '--net', 'netem', '--loss', '0');
    $result = json_decode(file_get_contents($this->root.'/app/bench/results/http3/2026-11-05/lcp-x.json'), true);
    $script = file_get_contents(base_path('bench/lcp.mjs'));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result)->toMatchArray(['attempts' => 7, 'valid_runs' => 5, 'invalid_runs' => 2])
        ->and(collect($result['samples'])->pluck('valid')->all())->toBe([false, false, true, true, true, true, true])
        ->and($result['samples'][0]['error'])->toContain('ERR_QUIC_PROTOCOL_ERROR')
        ->and($script)->toContain('await measureOnce(context, options)', 'if (!options.protocol) { throw error; }');
});

it('AC-3 lcp netem mode fails with no file when thrown navigations use up the 8 attempts', function (): void {
    lcpNetemStubs($this, []);
    $failed = ['error' => 'page.goto: Timeout 120000ms exceeded'];
    lcpNetemQueue($this, [$failed, $failed, $failed, $failed, lcpNetemRun('h3'), lcpNetemRun('h3'), lcpNetemRun('h3'), lcpNetemRun('h3'), lcpNetemRun('h3')]);
    $process = ($this->lcp)($this->netemEnv, '--variant', 'x', '--protocol', 'h3', '--net', 'netem', '--loss', '0');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('only 4 valid runs of 8 attempts')
        ->and($this->root.'/app/bench/results/http3/2026-11-05/lcp-x.json')->not->toBeFile();
});

it('NFR-1 AC-2 lcp netem mode turns the web offloads off before shaping, restores them after the runs and records before, after and restored', function (): void {
    lcpNetemStubs($this, array_map(fn (): array => lcpNetemRun('h3'), range(1, 5)));
    $process = ($this->lcp)($this->netemEnv, '--variant', 'x', '--protocol', 'h3', '--net', 'netem', '--loss', '2');
    $calls = file_get_contents($this->root.'/calls.log');
    $result = json_decode(file_get_contents($this->root.'/app/bench/results/http3/2026-11-05/lcp-x.json'), true);
    $web = $result['net']['offloads']['web'];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(strpos($calls, 'netem web-offloads off ddev-interview-web'))->toBeLessThan(strpos($calls, 'netem apply lcp-netem-'))
        ->and(strpos($calls, 'netem drops lcp-netem-'))->toBeLessThan(strpos($calls, 'netem web-offloads restore ddev-interview-web'))
        ->and($web['before']['eth0']['tso'])->toBe('on')
        ->and($web['after']['eth0']['tso'])->toBe('off')
        ->and($web['restored'])->toBe($web['before'])
        ->and($result['net']['offloads']['client']['after']['eth0']['tso'])->toBe('off');
});

it('NFR-1 lcp netem mode stops with no file when the web offloads cannot be turned off, and still restores them', function (): void {
    lcpNetemStubs($this, array_map(fn (): array => lcpNetemRun('h3'), range(1, 5)), webOffFails: true);
    $process = ($this->lcp)($this->netemEnv, '--variant', 'x', '--protocol', 'h3', '--net', 'netem', '--loss', '2');
    $calls = file_get_contents($this->root.'/calls.log');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('cannot turn the segmentation offloads off')
        ->and($calls)->toContain('netem web-offloads restore ddev-interview-web', 'docker rm -f lcp-netem-')
        ->and($this->root.'/app/bench/results/http3/2026-11-05/lcp-x.json')->not->toBeFile();
});

it('NFR-1 lcp netem mode restores the web offloads and removes the client when the loss check fails', function (): void {
    lcpNetemStubs($this, array_map(fn (): array => lcpNetemRun('h2'), range(1, 5)), dropsOk: false);
    ($this->lcp)($this->netemEnv, '--variant', 'x', '--protocol', 'h2', '--net', 'netem', '--loss', '2');

    expect(file_get_contents($this->root.'/calls.log'))->toContain('netem web-offloads restore ddev-interview-web', 'netem clear lcp-netem-', 'docker rm -f lcp-netem-');
});

it('NFR-1 lcp netem mode cleans up the client, the sidecar shaping and the web offloads on SIGTERM', function (): void {
    lcpNetemStubs($this, []);
    file_put_contents($this->root.'/bin/docker', <<<'BASH'
#!/usr/bin/env bash
echo "docker $*" >> "$STUB_LOG"
case "$1" in
  image) echo "stub/image@sha256:abc" ;;
  inspect) echo 172.19.0.2 ;;
  exec) case "$*" in *--inside*) exec sleep 20 ;; esac ;;
esac
BASH);
    $process = new Process(
        [$this->node, base_path('bench/lcp.mjs'), '--variant', 'x', '--protocol', 'h2', '--net', 'netem', '--loss', '0'],
        $this->root,
        $this->netemEnv + ['BENCH_APPROOT' => $this->root.'/app', 'BENCH_DATE' => '2026-11-05', 'STUB_LOG' => $this->root.'/calls.log', 'PATH' => $this->root.'/bin:'.getenv('PATH')],
    );
    $process->start();
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline && ! str_contains((string) @file_get_contents($this->root.'/calls.log'), '--inside')) {
        usleep(100000);
    }
    $process->signal(SIGTERM);
    $process->wait();
    $calls = file_get_contents($this->root.'/calls.log');

    expect($process->getExitCode())->toBe(143)
        ->and($calls)->toContain('netem clear lcp-netem-', 'docker rm -f lcp-netem-', 'netem web-offloads restore ddev-interview-web')
        ->and($this->root.'/app/bench/results/http3/2026-11-05/lcp-x.json')->not->toBeFile();
});
