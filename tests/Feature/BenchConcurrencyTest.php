<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-concurrency-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app/.ddev/servers/openlitespeed/conf', 0777, true);
    copy(base_path('.ddev/servers/openlitespeed/conf/httpd_config.conf'), $this->root.'/app/.ddev/servers/openlitespeed/conf/httpd_config.conf');
    file_put_contents($this->root.'/app/.ddev/config.yaml', "name: interview\nwebserver_type: nginx-fpm\n");

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
        ->and(file_get_contents($this->root.'/app/.ddev/config.yaml'))->toBe("name: interview\nwebserver_type: nginx-fpm\n");
})->with([
    'nginx-fpm' => ['nginx-fpm', 'web-entrypoint.d/zz-bench-fpm.sh', ['pm = static', 'pm.max_children = 16', 'pm.max_requests = 0']],
    'apache-fpm' => ['apache-fpm', 'web-entrypoint.d/zz-bench-fpm.sh', ['pm.max_children = 16']],
    'frankenphp' => ['frankenphp', 'config.zz-bench-concurrency.yaml', ['FRANKENPHP_CONFIG', 'num_threads 16', 'max_threads 16']],
    'openlitespeed' => ['openlitespeed', 'bench-concurrency/httpd_config.conf', ['PHP_LSAPI_CHILDREN=16', 'maxConns                16']],
]);

it('AC-4 openlitespeed mounts the generated config through a compose overlay', function (): void {
    ($this->concurrency)('openlitespeed', '12');

    expect(file_get_contents($this->root.'/app/.ddev/docker-compose.bench-concurrency.yaml'))
        ->toContain('openlitespeed:', '/usr/local/lsws/conf/httpd_config.conf', '/usr/local/lsws/.conf/httpd_config.conf');
});

it('AC-4 concurrency switching a server leaves no overlay of another server behind', function (): void {
    ($this->concurrency)('nginx-fpm', '8');
    $process = ($this->concurrency)('frankenphp', '8');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(($this->overlayFiles)())->toBe(['.ddev/config.zz-bench-concurrency.yaml']);
});

it('AC-4 concurrency rejects values that are not positive integers', function (string $value): void {
    $process = ($this->concurrency)('nginx-fpm', $value);

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
    $edited = file_get_contents($workerCaddyfile);
    expect($set->getExitCode())->toBe(0, $set->getErrorOutput())
        ->and($edited)->toContain("\t\t\tnum 8\n", "\t\t\tenv MAX_REQUESTS 1000000\n")
        ->and($edited)->not->toContain('num 4', 'MAX_REQUESTS 500')
        ->and(str_replace(["num 8\n", 'MAX_REQUESTS 1000000'], ["num 4\n", 'MAX_REQUESTS 500'], $edited))->toBe(file_get_contents($caddyfile))
        ->and(file_exists($ddev.'/config.zz-bench-concurrency.yaml'))->toBeFalse()
        ->and(($this->concurrency)('frankenphp-worker', '--show')->getOutput())->toBe("8 workers, max-requests 1000000\n");

    $custom = ($this->concurrency)('frankenphp-worker', '1', '--max-requests', '500');
    expect($custom->getExitCode())->toBe(0, $custom->getErrorOutput())
        ->and(($this->concurrency)('frankenphp-worker', '--show')->getOutput())->toBe("1 workers, max-requests 500\n");

    $restore = ($this->concurrency)('frankenphp-worker', 'default');
    expect($restore->getExitCode())->toBe(0, $restore->getErrorOutput())
        ->and(file_get_contents($workerCaddyfile))->toBe(file_get_contents($caddyfile))
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
