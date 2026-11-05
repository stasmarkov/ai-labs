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
