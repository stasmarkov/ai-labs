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
