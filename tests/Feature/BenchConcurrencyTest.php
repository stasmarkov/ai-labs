<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-concurrency-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app/.ddev', 0777, true);
    file_put_contents($this->root.'/app/.ddev/config.yaml', "name: interview\nwebserver_type: frankenphp\n");

    file_put_contents($this->root.'/bin/ddev', <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
if [[ "$*" == "server --current" ]]; then
  echo "$STUB_SERVER"
fi
BASH);
    chmod($this->root.'/bin/ddev', 0755);

    $this->concurrency = function (string $server, string ...$args): Process {
        $process = new Process(
            ['bash', base_path('bench/concurrency.sh'), ...$args],
            $this->root,
            [
                'BENCH_APPROOT' => $this->root.'/app',
                'STUB_SERVER' => $server,
                'STUB_LOG' => $this->root.'/calls.log',
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
            ],
        );
        $process->run();

        return $process;
    };
    $this->ddevCalls = fn (): string => file_exists($this->root.'/calls.log') ? file_get_contents($this->root.'/calls.log') : '';
    $this->overlayFiles = fn (): array => array_map(
        fn (string $path): string => substr($path, strlen($this->root.'/app/')),
        array_filter(
            array_merge(
                glob($this->root.'/app/.ddev/web-entrypoint.d/*') ?: [],
                glob($this->root.'/app/.ddev/config.zz-bench*') ?: [],
                glob($this->root.'/app/.ddev/docker-compose.bench*') ?: [],
                glob($this->root.'/app/.ddev/bench-concurrency/*') ?: [],
            ),
        ),
    );
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-4 concurrency sets and restores the worker limit per server', function (string $server, string $file, array $expected): void {
    $set = ($this->concurrency)($server, '16');

    expect($set->getExitCode())->toBe(0, $set->getErrorOutput())
        ->and(file_get_contents($this->root.'/app/.ddev/'.$file))->toContain(...$expected)
        ->and(($this->ddevCalls)())->toContain('ddev restart')
        ->and(($this->concurrency)($server, '--show')->getOutput())->toBe("16 workers\n");

    $restore = ($this->concurrency)($server, 'default');

    expect($restore->getExitCode())->toBe(0, $restore->getErrorOutput())
        ->and(($this->overlayFiles)())->toBe([])
        ->and(substr_count(($this->ddevCalls)(), 'ddev restart'))->toBe(2)
        ->and(($this->concurrency)($server, '--show')->getOutput())->toBe("default\n")
        ->and(file_get_contents($this->root.'/app/.ddev/config.yaml'))->toBe("name: interview\nwebserver_type: frankenphp\n");
})->with([
    'frankenphp' => ['frankenphp', 'config.zz-bench-concurrency.yaml', ['FRANKENPHP_CONFIG', 'num_threads 16', 'max_threads 16']],
]);

it('AC-4 concurrency switching a server leaves no overlay of another server behind', function (): void {
    ($this->concurrency)('frankenphp-worker', '8');
    $process = ($this->concurrency)('frankenphp', '8');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(($this->overlayFiles)())->toBe(['.ddev/config.zz-bench-concurrency.yaml']);
});

it('AC-4 concurrency rejects values that are not positive integers', function (string $value): void {
    $process = ($this->concurrency)('frankenphp', $value);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Usage')
        ->and(($this->overlayFiles)())->toBe([])
        ->and(($this->ddevCalls)())->not->toContain('ddev restart');
})->with(['zero' => '0', 'text' => 'many', 'negative' => '-4']);

it('AC-1 worker servers get their worker count and no recycling, and default restores 4 and 500', function (): void {
    $ddev = $this->root.'/app/.ddev';
    mkdir($ddev.'/servers/frankenphp-octane', 0777, true);
    mkdir($ddev.'/servers/frankenphp-worker/web-build', 0777, true);
    mkdir($ddev.'/web-build', 0777, true);
    $daemon = base_path('.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml');
    $caddyfile = base_path('.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp');
    copy($daemon, $ddev.'/servers/frankenphp-octane/config.frankenphp-octane.yaml');
    copy($caddyfile, $ddev.'/servers/frankenphp-worker/web-build/Caddyfile.frankenphp');
    copy($caddyfile, $ddev.'/web-build/Caddyfile.frankenphp');
    file_put_contents($ddev.'/config.zz-server.yaml', "# server: frankenphp-octane\n".file_get_contents($daemon));

    $octaneOverlay = $ddev.'/config.zzz-bench-concurrency.yaml';
    $workerCaddyfile = $ddev.'/web-build/Caddyfile.frankenphp';

    expect(($this->concurrency)('frankenphp-octane', '--show')->getOutput())->toBe("4 workers, max-requests 500\n")
        ->and(($this->concurrency)('frankenphp-worker', '--show')->getOutput())->toBe("4 workers, max-requests 500\n");

    $set = ($this->concurrency)('frankenphp-octane', '8');
    expect($set->getExitCode())->toBe(0, $set->getErrorOutput())
        ->and(file_get_contents($octaneOverlay))->toContain('override_config: true', '--workers=8 --max-requests=1000000', 'frankenphp-octane', 'web_extra_exposed_ports')
        ->and(file_get_contents($octaneOverlay))->not->toContain('--workers=4', '--max-requests=500')
        ->and(file_exists($ddev.'/config.zz-bench-concurrency.yaml'))->toBeFalse()
        ->and(file_get_contents($ddev.'/config.zz-server.yaml'))->toContain('--workers=4 --max-requests=500')
        ->and(($this->concurrency)('frankenphp-octane', '--show')->getOutput())->toBe("8 workers, max-requests 1000000\n");

    $custom = ($this->concurrency)('frankenphp-octane', '2', '--max-requests', '500');
    expect($custom->getExitCode())->toBe(0, $custom->getErrorOutput())
        ->and(($this->concurrency)('frankenphp-octane', '--show')->getOutput())->toBe("2 workers, max-requests 500\n");

    $restore = ($this->concurrency)('frankenphp-octane', 'default');
    expect($restore->getExitCode())->toBe(0, $restore->getErrorOutput())
        ->and(file_exists($octaneOverlay))->toBeFalse()
        ->and(($this->concurrency)('frankenphp-octane', '--show')->getOutput())->toBe("4 workers, max-requests 500\n");

    $set = ($this->concurrency)('frankenphp-worker', '8');
    expect($set->getExitCode())->toBe(0, $set->getErrorOutput())
        // The activated Caddyfile is never edited: sizing goes through environment placeholders.
        ->and(file_get_contents($workerCaddyfile))->toBe(file_get_contents($caddyfile))
        ->and(file_get_contents($octaneOverlay))->toContain("WORKER_NUM=8\n", "WORKER_MAX_REQUESTS=1000000\n")
        ->and(file_exists($ddev.'/config.zz-bench-concurrency.yaml'))->toBeFalse()
        ->and(($this->concurrency)('frankenphp-worker', '--show')->getOutput())->toBe("8 workers, max-requests 1000000\n");

    $custom = ($this->concurrency)('frankenphp-worker', '1', '--max-requests', '500');
    expect($custom->getExitCode())->toBe(0, $custom->getErrorOutput())
        ->and(($this->concurrency)('frankenphp-worker', '--show')->getOutput())->toBe("1 workers, max-requests 500\n");

    $restore = ($this->concurrency)('frankenphp-worker', 'default');
    expect($restore->getExitCode())->toBe(0, $restore->getErrorOutput())
        ->and(file_get_contents($workerCaddyfile))->toBe(file_get_contents($caddyfile))
        ->and(file_exists($octaneOverlay))->toBeFalse()
        ->and(($this->concurrency)('frankenphp-worker', '--show')->getOutput())->toBe("4 workers, max-requests 500\n")
        ->and(substr_count(($this->ddevCalls)(), 'ddev restart'))->toBe(6);
});

it('AC-1 max-requests is accepted only with a worker server and a positive integer', function (string $server, array $args): void {
    $process = ($this->concurrency)($server, ...$args);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Usage')
        ->and(($this->ddevCalls)())->not->toContain('ddev restart');
})->with([
    'classic server' => ['frankenphp', ['4', '--max-requests', '500']],
    'zero' => ['frankenphp-worker', ['4', '--max-requests', '0']],
    'text' => ['frankenphp-octane', ['4', '--max-requests', 'x']],
    'missing value' => ['frankenphp-octane', ['4', '--max-requests']],
]);

it('AC-6 autoscale on frankenphp writes threads, max threads and max wait, shows them and default restores', function (): void {
    $overlay = $this->root.'/app/.ddev/config.zz-bench-concurrency.yaml';

    $set = ($this->concurrency)('frankenphp', 'autoscale', '--threads', '14', '--max-threads', '64', '--max-wait', '5');

    expect($set->getExitCode())->toBe(0, $set->getErrorOutput())
        ->and(file_get_contents($overlay))->toContain('FRANKENPHP_CONFIG', 'num_threads 14', 'max_threads 64', 'max_wait_time 5s')
        ->and(($this->ddevCalls)())->toContain('ddev restart')
        ->and(($this->concurrency)('frankenphp', '--show')->getOutput())->toBe("autoscale 14 to 64 threads, max-wait 5s\n")
        ->and(file_get_contents($this->root.'/app/.ddev/config.yaml'))->toBe("name: interview\nwebserver_type: frankenphp\n");

    $restore = ($this->concurrency)('frankenphp', 'default');

    expect($restore->getExitCode())->toBe(0, $restore->getErrorOutput())
        ->and(($this->overlayFiles)())->toBe([])
        ->and(($this->concurrency)('frankenphp', '--show')->getOutput())->toBe("default\n");
});

it('AC-6 autoscale max-wait defaults to 5 seconds', function (): void {
    $set = ($this->concurrency)('frankenphp', 'autoscale', '--threads', '8', '--max-threads', '32');

    expect($set->getExitCode())->toBe(0, $set->getErrorOutput())
        ->and(($this->concurrency)('frankenphp', '--show')->getOutput())->toBe("autoscale 8 to 32 threads, max-wait 5s\n");
});

it('AC-6 autoscale is an error on other servers and on bad values, with no overlay and no restart', function (string $server, array $args): void {
    $process = ($this->concurrency)($server, 'autoscale', ...$args);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('autoscale')
        ->and(($this->overlayFiles)())->toBe([])
        ->and(($this->ddevCalls)())->not->toContain('ddev restart');
})->with([
    'frankenphp-worker' => ['frankenphp-worker', ['--threads', '8', '--max-threads', '32']],
    'frankenphp-octane' => ['frankenphp-octane', ['--threads', '8', '--max-threads', '32']],
    'missing threads' => ['frankenphp', ['--max-threads', '32']],
    'max not above threads' => ['frankenphp', ['--threads', '32', '--max-threads', '32']],
    'zero wait' => ['frankenphp', ['--threads', '8', '--max-threads', '32', '--max-wait', '0']],
    'text' => ['frankenphp', ['--threads', 'x', '--max-threads', '32']],
    'unknown flag' => ['frankenphp', ['--threads', '8', '--max-threads', '32', '--bogus', '1']],
]);

it('AC-6 the one usage text lists every form including autoscale, and every bad call exits 2 through it', function (array $args): void {
    $process = ($this->concurrency)('frankenphp', ...$args);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Usage', 'autoscale --threads', '--max-threads', '--max-wait', 'default', '--show', '--max-requests');
})->with([
    'bad value' => [['abc']],
    'autoscale without values' => [['autoscale']],
    'autoscale not above' => [['autoscale', '--threads', '8', '--max-threads', '8']],
]);

it('AC-2 concurrency refuses a removed server with the removal message and changes nothing', function (string $server, array $args): void {
    $process = ($this->concurrency)($server, ...$args);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain($server.' was removed', 'frankenphp-octane')
        ->and(($this->overlayFiles)())->toBe([])
        ->and(($this->ddevCalls)())->not->toContain('ddev restart');
})->with([
    'nginx-fpm' => ['nginx-fpm', ['8']],
    'apache-fpm' => ['apache-fpm', ['default']],
    'openlitespeed' => ['openlitespeed', ['autoscale', '--threads', '8', '--max-threads', '32']],
]);
